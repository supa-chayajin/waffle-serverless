<?php

declare(strict_types=1);

use Waffle\Commons\Config\DotEnv;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Http\Emitter\ResponseEmitter;
use Waffle\Commons\Http\Factory\GlobalsFactory;
use Waffle\Commons\Runtime\WaffleRuntime;
use Wfl\Factory\AppKernelFactory;

// Chargement de l'autoloader Composer (framework Waffle + code applicatif).
require_once __DIR__ . '/../vendor/autoload.php';

// Racine applicative et dossier de configuration : résolus UNE SEULE FOIS au
// démarrage du worker, jamais par requête (mandat de statelessness FrankenPHP).
define('APP_ROOT', realpath(path: dirname(path: __DIR__)));
const APP_CONFIG = 'config';

// Registre d'environnement : le .env (lecture seule — DotEnv ne mute NI $_ENV NI
// $_SERVER NI putenv(), conformément à la règle worker FrankenPHP) est fusionné
// avec l'environnement processus, ce dernier l'emportant (les valeurs Docker/K8s
// écrasent les défauts du .env). getenv() est worker-safe ; on évite $_ENV.
$envRegistry = array_merge(new DotEnv(path: APP_ROOT)->load(), getenv());
$env = $envRegistry[Constant::APP_ENV] ?? Constant::ENV_PROD;
$debug = filter_var($envRegistry[Constant::APP_DEBUG] ?? false, FILTER_VALIDATE_BOOL);

// 1. Contexte & assemblage.
// On délègue à la Factory la création des implémentations concrètes.
$kernel = AppKernelFactory::create(env: $env, debug: $debug);

// 2. Runtime (agnostique).
// Le runtime orchestre simplement la boucle FrankenPHP [Kernel + Request -> Emitter].
// STAB-01 : la GlobalsFactory et l'émetteur sont des instances par processus,
// injectées explicitement dans le runtime (aucun état statique partagé).
// MAX_REQUESTS recycle le worker après N requêtes (borne la mémoire en mode
// worker) ; lu depuis le registre d'environnement (ConfigMap K8s / Docker / .env).
$maxRequestsRaw = $envRegistry['MAX_REQUESTS'] ?? '';
$maxRequests = $maxRequestsRaw !== '' ? (int) $maxRequestsRaw : 500;
new WaffleRuntime(new GlobalsFactory(), new ResponseEmitter())
    ->loop(
        kernel: $kernel,
        maxRequests: $maxRequests
    )
;
