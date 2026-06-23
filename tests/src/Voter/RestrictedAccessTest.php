<?php

declare(strict_types=1);

namespace WflTests\Voter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Wfl\Voter\RestrictedAccess;

/**
 * Vérifie la posture fail-closed du voter et la lecture de la politique
 * immuable (ECOSHIELD_LOCKED_OPEN), exposée en lecture seule via private(set).
 */
#[CoversClass(RestrictedAccess::class)]
final class RestrictedAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ECOSHIELD_LOCKED_OPEN');
    }

    public function testDeniesByDefaultWhenFlagAbsent(): void
    {
        putenv('ECOSHIELD_LOCKED_OPEN');

        $voter = new RestrictedAccess();

        static::assertFalse($voter->shieldOpen);
        static::assertFalse($voter->decide());
    }

    public function testDeniesWhenFlagIsFalsey(): void
    {
        putenv('ECOSHIELD_LOCKED_OPEN=false');

        static::assertFalse(new RestrictedAccess()->decide());
    }

    public function testGrantsWhenFlagIsTruthy(): void
    {
        putenv('ECOSHIELD_LOCKED_OPEN=true');

        $voter = new RestrictedAccess();

        static::assertTrue($voter->shieldOpen);
        static::assertTrue($voter->decide());
    }
}
