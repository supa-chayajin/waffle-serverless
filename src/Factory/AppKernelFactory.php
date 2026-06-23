<?php

declare(strict_types=1);

namespace Wfl\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Container\Container;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\Security\Csrf\Constant as CsrfConstant;
use Waffle\Commons\Contracts\Security\Csrf\CsrfTokenManagerInterface;
use Waffle\Commons\ErrorHandler\Middleware\ErrorHandlerMiddleware;
use Waffle\Commons\ErrorHandler\Renderer\JsonErrorRenderer;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Pipeline\CoreRoutingMiddleware;
use Waffle\Commons\Pipeline\Middleware\SecureHeadersMiddleware;
use Waffle\Commons\Pipeline\Middleware\TrustedHostMiddleware;
use Waffle\Commons\Pipeline\MiddlewareStack;
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Security\Container\SecureContainer;
use Waffle\Commons\Security\Cors\CorsPolicy;
use Waffle\Commons\Security\Csrf\CsrfTokenManager;
use Waffle\Commons\Security\Middleware\AnonymousSessionMiddleware;
use Waffle\Commons\Security\Middleware\CorsMiddleware;
use Waffle\Commons\Security\Middleware\CsrfMiddleware;
use Waffle\Commons\Security\Middleware\SecurityMiddleware;
use Waffle\Commons\Security\Security;
use Wfl\Kernel\AppKernel;

/**
 * Code d'assemblage d'EcoShield-Minimal.
 *
 * Monte les implémentations concrètes des composants Waffle puis câble le
 * pipeline « Proxy-Shield » dans l'ordre canonique Beta-1, AVANT que le Runtime
 * ne déclenche boot()/configure() :
 *
 *   ErrorHandler (prepend)
 *     → TrustedHost      (allow-list d'hôtes — RFC-003)
 *     → Cors             (fail-closed, liste blanche d'origines — SEC-04)
 *     → AnonymousSession (SID par navigateur, requis avant Csrf — SEC-01)
 *     → Routing          (résolution #[Route] → _classname / _method)
 *     → Csrf             (#[RequiresCsrfToken], jeton HMAC lié au SID — SEC-01)
 *     → Security         (#[Voter] / #[PublicAccess] fail-closed — ABAC)
 *     → SecureHeaders    (en-têtes de durcissement)
 *       → ControllerDispatcher (handler terminal auto-câblé par configure())
 *
 * Choix d'architecture (Beta 4) :
 *   - Injection par SETTERS : AbstractKernel (Beta 4) expose set*() ; on conserve
 *     ce style (l'injection par constructeur n'arrive qu'en Beta 5 / ARCH-03).
 *   - Le SecureContainer (constructeur 2-arg en Beta 4) n'est injecté QUE dans le
 *     SecurityMiddleware : son analyze($classe, $méthode) repose sur la réflexion
 *     + `new $voter()` et ne résout JAMAIS un service via le conteneur. La
 *     résolution des dépendances (contrôleurs, CsrfTokenManager…) passe donc par
 *     le conteneur SIMPLE — aucune analyse de sécurité parasite sur les services
 *     du framework.
 */
final class AppKernelFactory
{
    /**
     * Construit le Kernel entièrement assemblé et prêt à être amorcé.
     */
    public static function create(string $env = Constant::ENV_PROD, bool $debug = false): KernelInterface
    {
        /** @var string $root */
        $root = APP_ROOT;
        $configDir = $root . DIRECTORY_SEPARATOR . APP_CONFIG;

        // 1. Conteneur PSR-11 concret (paquet waffle-commons/container).
        $container = new Container();

        // 2. Factory PSR-17 de réponses : requise par l'ErrorHandler, le CORS ET
        //    injectée dans les contrôleurs par le ControllerDispatcher.
        $responseFactory = new ResponseFactory();
        $container->set(ResponseFactoryInterface::class, $responseFactory);

        // 3. Configuration (paquet waffle-commons/config) : analyse config/app.yaml
        //    avec interpolation des variables d'environnement (« %env(...)% »).
        $config = new Config(configDir: $configDir, environment: $env, env: getenv());

        // 4. Sécurité (paquet waffle-commons/security) : OBLIGATOIRE —
        //    AbstractKernel::configure() avorte si elle est absente.
        $security = new Security($config);

        // 4a. Gestionnaire CSRF sans état (SEC-01) : HMAC lié à un SID anonyme par
        //     navigateur. Le secret est résolu depuis la config (%env%) ou l'env ;
        //     absent ou < 32 octets ⇒ avortement du boot en production.
        $csrfTokenManager = new CsrfTokenManager(secret: self::resolveCsrfSecret($config, $env));
        $container->set(CsrfTokenManagerInterface::class, $csrfTokenManager);

        // 4b. Conteneur sécurisé : SEULE responsabilité ici, fournir la barrière
        //     ABAC au SecurityMiddleware (discoverRules → vote). Non utilisé comme
        //     conteneur d'injection (cf. docblock de classe).
        $secureContainer = new SecureContainer($container, $security);

        // 5. Pipeline PSR-15 (paquet waffle-commons/pipeline).
        $stack = new MiddlewareStack();

        // 5a. Gestionnaire d'erreurs : « prepend »-é pour englober tout le pipeline
        //     et transformer chaque exception (403 CSRF/voter, 400 validation…) en
        //     réponse JSON propre.
        $errorRenderer = new JsonErrorRenderer($responseFactory, $debug);
        $stack->prepend(middleware: new ErrorHandlerMiddleware(renderer: $errorRenderer, logger: new NullLogger()));

        // 5b. Allow-list d'hôtes (RFC-003) : première barrière exécutable. Les
        //     entrées vides (ex. %env(SERVER_NAME)% non renseigné) sont filtrées.
        /** @var list<string> $trustedHosts */
        $trustedHosts = array_values(array_filter(
            $config->getArray(key: 'waffle.trusted_hosts') ?? [],
            static fn(mixed $host): bool => is_string($host) && $host !== '',
        ));
        $stack->add(middleware: new TrustedHostMiddleware($trustedHosts));

        // 5c. CORS fail-closed (SEC-04) : avant le routage pour répondre au pré-vol
        //     OPTIONS. Liste blanche vide ⇒ toute requête cross-origin est refusée.
        /** @var list<string> $corsOrigins */
        $corsOrigins = $config->getArray(key: 'waffle.security.cors.allowed_origins') ?? [];
        $stack->add(middleware: new CorsMiddleware(new CorsPolicy(allowedOrigins: $corsOrigins), $responseFactory));

        // 5d. SID anonyme par navigateur (SEC-01) : DOIT précéder Csrf pour que
        //     l'attribut _anon_sid soit alimenté lors du binding HMAC. Le contexte
        //     de sécurité est optionnel (Beta 4) — non requis ici.
        $stack->add(middleware: new AnonymousSessionMiddleware());

        // 5e. Routage → Csrf → Security → SecureHeaders. Sans chemin de
        //     contrôleurs configuré, aucune route n'existe (404 systématique).
        $controllersPath = $config->getString(key: 'waffle.paths.controllers');
        if (is_string($controllersPath)) {
            $router = new Router($root . DIRECTORY_SEPARATOR . $controllersPath);
            $router->boot(container: $container);

            $stack->add(middleware: new CoreRoutingMiddleware($router, $responseFactory));
            // Csrf APRÈS Routing (lit _classname/_method) et AVANT Security.
            $stack->add(middleware: new CsrfMiddleware($csrfTokenManager));
            $stack->add(middleware: new SecurityMiddleware(
                secureContainer: $secureContainer,
                logger: new NullLogger(),
            ));
            $stack->add(middleware: new SecureHeadersMiddleware());
        }

        // 6. Kernel : injection des quatre dépendances obligatoires via setters
        //    (Beta 4). Le handler terminal (ControllerDispatcher + ReflectionService
        //    + ArgumentResolver) est auto-câblé par AbstractKernel::configure().
        $kernel = new AppKernel();
        $kernel->setConfiguration($config);
        $kernel->setSecurity($security);
        $kernel->setContainerImplementation($container);
        $kernel->setMiddlewareStack($stack);

        return $kernel;
    }

    /**
     * Résout le secret de signature CSRF avec des fallbacks raisonnables : la
     * config gagne (`waffle.security.csrf.secret`, interpolée depuis
     * WAFFLE_CSRF_SECRET) ; sinon lecture directe de l'environnement. La
     * production refuse de démarrer si le secret est absent ou plus court que
     * `CsrfConstant::MIN_SECRET_BYTES` (32 octets) ; hors-prod, un secret
     * aléatoire éphémère permet à dev/test de démarrer proprement.
     */
    private static function resolveCsrfSecret(Config $config, string $env): string
    {
        $fromConfig = $config->getString('waffle.security.csrf.secret');
        $candidate = is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;

        if ($candidate === null) {
            $fromEnv = getenv(CsrfConstant::SECRET_ENV_KEY);
            if (is_string($fromEnv) && $fromEnv !== '') {
                $candidate = $fromEnv;
            }
        }

        if ($candidate !== null && strlen($candidate) >= CsrfConstant::MIN_SECRET_BYTES) {
            return $candidate;
        }

        if ($env === Constant::ENV_PROD) {
            throw new RuntimeException(sprintf(
                'Secret CSRF manquant ou plus court que %d octets en production. '
                . 'Renseignez "waffle.security.csrf.secret" ou la variable d\'environnement %s.',
                CsrfConstant::MIN_SECRET_BYTES,
                CsrfConstant::SECRET_ENV_KEY,
            ));
        }

        // Fallback dev/test : secret éphémère par processus (les jetons émis ne
        // survivront pas au redémarrage d'un worker — acceptable hors production).
        return random_bytes(CsrfConstant::MIN_SECRET_BYTES);
    }
}
