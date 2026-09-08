<?php

declare(strict_types=1);

namespace WflTests\Voter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wfl\Voter\RestrictedAccess;

/**
 * Vérifie la posture fail-closed du voter et la lecture de la politique
 * immuable (ECOSHIELD_LOCKED_OPEN), exposée en lecture seule via private(set).
 *
 * L'accent est mis sur la robustesse « fail-closed » : SEULES des valeurs
 * explicitement vraies ouvrent le bouclier ; toute autre entrée — absente, vide,
 * espaces, négation, nombre, ou chaîne arbitraire/hostile — laisse la route
 * /locked fermée (refus, 403).
 */
#[CoversClass(RestrictedAccess::class)]
final class RestrictedAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        // Le voter lit l'environnement à la construction : on neutralise le
        // drapeau entre les tests pour éviter toute fuite d'état de processus.
        putenv('ECOSHIELD_LOCKED_OPEN');
    }

    public function testDeniesByDefaultWhenFlagAbsent(): void
    {
        putenv('ECOSHIELD_LOCKED_OPEN');

        $voter = new RestrictedAccess();

        static::assertFalse($voter->shieldOpen);
        static::assertFalse($voter->decide());
    }

    /**
     * Toute valeur qui n'est pas explicitement « vraie » DOIT laisser le
     * bouclier fermé — y compris les entrées malformées ou hostiles.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function closedFlagValues(): iterable
    {
        yield 'false' => ['false'];
        yield 'zero' => ['0'];
        yield 'off' => ['off'];
        yield 'no' => ['no'];
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'arbitrary garbage' => ['garbage'];
        yield 'non-one number' => ['2'];
        yield 'negative number' => ['-1'];
        yield 'near-miss word' => ['nope'];
    }

    #[DataProvider('closedFlagValues')]
    public function testStaysClosedForAnyNonTruthyValue(string $value): void
    {
        putenv('ECOSHIELD_LOCKED_OPEN=' . $value);

        $voter = new RestrictedAccess();

        static::assertFalse($voter->shieldOpen);
        static::assertFalse($voter->decide());
    }

    /**
     * Liste blanche des valeurs reconnues comme vraies par FILTER_VALIDATE_BOOL
     * (insensible à la casse) — les seules à ouvrir la route /locked.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function openFlagValues(): iterable
    {
        yield 'true' => ['true'];
        yield 'one' => ['1'];
        yield 'on' => ['on'];
        yield 'yes' => ['yes'];
        yield 'uppercase TRUE' => ['TRUE'];
        yield 'capitalized True' => ['True'];
    }

    #[DataProvider('openFlagValues')]
    public function testOpensOnlyForExplicitlyTruthyValue(string $value): void
    {
        putenv('ECOSHIELD_LOCKED_OPEN=' . $value);

        $voter = new RestrictedAccess();

        static::assertTrue($voter->shieldOpen);
        static::assertTrue($voter->decide());
    }
}
