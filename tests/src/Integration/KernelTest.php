<?php

declare(strict_types=1);

namespace WflTests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Http\Factory\ServerRequestFactory;
use Wfl\Factory\AppKernelFactory;

/**
 * Test d'intégration « boîte noire » : amorce le kernel via la factory et
 * dispatche des requêtes PSR-7 synthétiques à travers TOUT le pipeline
 * « Proxy-Shield ». Vérifie le double bouclier (CSRF + voter), les sondes et la
 * validation du DTO.
 */
#[CoversNothing]
final class KernelTest extends TestCase
{
    private KernelInterface $kernel;

    protected function setUp(): void
    {
        $this->kernel = AppKernelFactory::create('test', true);
        $this->kernel->boot();
        $this->kernel->configure();
    }

    protected function tearDown(): void
    {
        // Le voter lit ECOSHIELD_LOCKED_OPEN à la construction : on neutralise
        // le drapeau entre les tests pour éviter toute fuite d'état de processus.
        putenv('ECOSHIELD_LOCKED_OPEN');
    }

    public function testPublicAndHealthRoutesReturn200(): void
    {
        $home = $this->dispatch('GET', '/');
        static::assertSame(200, $home->getStatusCode());
        static::assertSame('{"message":"hello Waffle!"}', (string) $home->getBody());

        static::assertSame(200, $this->dispatch('GET', '/healthz')->getStatusCode());
        static::assertSame(200, $this->dispatch('GET', '/readyz')->getStatusCode());
    }

    public function testLockedWithoutTokenIsForbidden(): void
    {
        // Bouclier 1 : CsrfMiddleware refuse faute de jeton.
        static::assertSame(403, $this->dispatch('POST', '/locked', body: ['content' => 'Ada'])->getStatusCode());
    }

    public function testHappyPathWhenShieldIsOpen(): void
    {
        [$token, $cookies] = $this->mintCsrfToken();

        putenv('ECOSHIELD_LOCKED_OPEN=true');
        $response = $this->dispatch('POST', '/locked', headers: ['X-CSRF-Token' => $token], cookies: $cookies, body: [
            'content' => 'Ada',
        ]);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame('{"message":"Waffle says Hi to Ada !"}', (string) $response->getBody());
    }

    public function testDeniedByVoterWhenShieldIsClosed(): void
    {
        [$token, $cookies] = $this->mintCsrfToken();

        putenv('ECOSHIELD_LOCKED_OPEN=false');
        $response = $this->dispatch('POST', '/locked', headers: ['X-CSRF-Token' => $token], cookies: $cookies, body: [
            'content' => 'Ada',
        ]);

        // Bouclier 2 : le voter RestrictedAccess refuse par défaut (fail-closed).
        static::assertSame(403, $response->getStatusCode());
    }

    public function testBlankContentIsRejected(): void
    {
        [$token, $cookies] = $this->mintCsrfToken();

        putenv('ECOSHIELD_LOCKED_OPEN=true');
        $response = $this->dispatch('POST', '/locked', headers: ['X-CSRF-Token' => $token], cookies: $cookies, body: [
            'content' => '   ',
        ]);

        // Le property hook du DTO rejette une valeur vide ou composée uniquement
        // d'espaces (422), même bouclier ouvert et jeton CSRF valide.
        static::assertSame(422, $response->getStatusCode());
    }

    /**
     * Appelle GET /csrf et renvoie [jeton, cookies] prêts à rejouer sur /locked.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function mintCsrfToken(): array
    {
        $response = $this->dispatch('GET', '/csrf');
        static::assertSame(200, $response->getStatusCode());

        /** @var array{token?: string} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $token = $payload['token'] ?? '';
        static::assertNotSame('', $token);

        $matches = [];
        $sid = '';
        if (preg_match('/WAFFLE_SID=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $matches) === 1) {
            $sid = $matches[1] ?? '';
        }
        static::assertNotSame('', $sid);

        return [$token, ['WAFFLE_SID' => $sid]];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed>|null $body
     */
    private function dispatch(
        string $method,
        string $path,
        array $headers = [],
        array $cookies = [],
        ?array $body = null,
    ): ResponseInterface {
        $request = new ServerRequestFactory()->createServerRequest($method, 'http://localhost' . $path);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($cookies !== []) {
            $request = $request->withCookieParams($cookies);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        $response = $this->kernel->handle($request);
        $this->kernel->reset();

        return $response;
    }
}
