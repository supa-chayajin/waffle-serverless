<?php

declare(strict_types=1);

namespace Wfl\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Commons\Contracts\Security\Attribute\Voter;
use Waffle\Commons\Contracts\Security\Csrf\Attribute\RequiresCsrfToken;
use Waffle\Commons\Contracts\Security\Csrf\Constant as Csrf;
use Waffle\Commons\Contracts\Security\Csrf\CsrfTokenManagerInterface;
use Waffle\Commons\Security\Csrf\CsrfBindingResolver;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;
use Wfl\Dto\Message;
use Wfl\Voter\RestrictedAccess;

/**
 * Contrôleur de démonstration du POC EcoShield-Minimal.
 *
 * L'attribut #[Route] de CLASSE est obligatoire : le RouteParser ignore toute
 * classe qui n'en porte pas. Le préfixe de chemin « / » et le préfixe de nom
 * « app » se combinent avec la route de méthode pour produire la route finale.
 */
#[Route(path: '/', name: 'app')]
final class HelloController extends BaseController
{
    /**
     * GET / — route publique. Renvoie {"message": "hello Waffle!"}.
     *
     * Combinaison classe + méthode : chemin « / » + « » = « / » ; nom « app » +
     * « _ » + « index » = « app_index ». #[PublicAccess] dispense la route de la
     * barrière ABAC (sinon : 403 fail-closed faute de #[Voter]).
     *
     * @throws RenderingException
     */
    #[PublicAccess]
    #[Route(path: '', methods: [Routing::METHOD_GET], name: 'index')]
    public function index(): ResponseInterface
    {
        return $this->jsonResponse(data: ['message' => 'hello Waffle!']);
    }

    /**
     * GET /csrf — affordance de démonstration : émet un jeton CSRF lié au SID
     * anonyme de la requête (publié par AnonymousSessionMiddleware) et le
     * renvoie au client, qui pourra alors appeler POST /locked.
     *
     * En conditions réelles le jeton serait embarqué dans la page rendue ou un
     * endpoint dédié ; ici il facilite la démonstration « en direct ».
     *
     * @throws RenderingException
     */
    #[PublicAccess]
    #[Route(path: 'csrf', methods: [Routing::METHOD_GET], name: 'csrf')]
    public function csrf(ServerRequestInterface $request, CsrfTokenManagerInterface $tokens): ResponseInterface
    {
        // Même liaison que celle revérifiée par CsrfMiddleware : « anon:<sid> »
        // en l'absence d'identité authentifiée.
        $binding = CsrfBindingResolver::resolve($request) ?? '';
        $token = $tokens->issue(Csrf::DEFAULT_TOKEN_ID, $binding);

        return $this->jsonResponse(data: [
            'token' => $token->getValue(),
            'header' => Csrf::HEADER_NAME,
            'hint' => 'POST /locked avec cet en-tête + le cookie WAFFLE_SID.',
        ]);
    }

    /**
     * POST /locked — route protégée par le double bouclier « EcoShield ».
     *
     *   1. CsrfMiddleware exige un jeton CSRF valide (#[RequiresCsrfToken]) ;
     *   2. SecurityMiddleware soumet l'accès au RestrictedAccess (#[Voter]) —
     *      refus par défaut tant que ECOSHIELD_LOCKED_OPEN n'est pas « vrai ».
     *
     * Le corps JSON est hydraté et validé par le DTO Message (property hooks) :
     * un `content` vide ou composé uniquement d'espaces ⇒ 422. Combinaison :
     * chemin « /locked », nom « app_locked ».
     *
     * @throws RenderingException
     */
    #[RequiresCsrfToken]
    #[Voter(name: RestrictedAccess::class)]
    #[Route(path: 'locked', methods: [Routing::METHOD_POST], name: 'locked')]
    public function locked(Message $message): ResponseInterface
    {
        return $this->jsonResponse(data: [
            'message' => sprintf('Waffle says Hi to %s !', $message->content),
        ]);
    }
}
