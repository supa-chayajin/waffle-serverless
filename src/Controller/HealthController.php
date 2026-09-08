<?php

declare(strict_types=1);

namespace Wfl\Controller;

use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Sondes d'exploitation pour Kubernetes.
 *
 * Beta 4 n'expose pas encore de HealthCheckInterface (prévu Beta 6 / RFC-014) :
 * on fournit donc deux routes HTTP minimales et publiques, cibles des
 * `livenessProbe` (/healthz) et `readinessProbe` (/readyz). Sans dépendance
 * externe câblée, « prêt » équivaut à « vivant » ; /readyz reste distinct pour
 * accueillir plus tard la vérification des dépendances (base, cache…).
 *
 * Les deux routes sont #[PublicAccess] (hors barrière ABAC) et en GET (donc
 * exemptées de CSRF). En cluster, les sondes doivent présenter un en-tête `Host`
 * de confiance (cf. manifeste Deployment) pour franchir le TrustedHostMiddleware.
 */
#[Route(path: '/', name: 'health')]
final class HealthController extends BaseController
{
    /**
     * GET /healthz — vivacité : le worker répond.
     *
     * @throws RenderingException
     */
    #[PublicAccess]
    #[Route(path: 'healthz', methods: [Routing::METHOD_GET], name: 'live')]
    public function live(): ResponseInterface
    {
        return $this->jsonResponse(data: ['status' => 'ok']);
    }

    /**
     * GET /readyz — disponibilité : prêt à recevoir du trafic.
     *
     * @throws RenderingException
     */
    #[PublicAccess]
    #[Route(path: 'readyz', methods: [Routing::METHOD_GET], name: 'ready')]
    public function ready(): ResponseInterface
    {
        return $this->jsonResponse(data: ['status' => 'ready']);
    }
}
