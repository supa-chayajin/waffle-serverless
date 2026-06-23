<?php

declare(strict_types=1);

namespace WflTests\Dto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waffle\Exception\ValidationException;
use Wfl\Dto\Message;

/**
 * Vérifie le « property hook » set du DTO : assainissement (trim) + validation
 * (lettres uniquement), au plus près de la donnée.
 */
#[CoversClass(Message::class)]
final class MessageTest extends TestCase
{
    public function testTrimsAndAcceptsLettersIncludingAccents(): void
    {
        static::assertSame('Ada', new Message('  Ada  ')->author);
        static::assertSame('Léa', new Message('Léa')->author);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidAuthors(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'digits' => ['A1b2'];
        yield 'symbols' => ['Ada!'];
        yield 'spaced words' => ['Ada Lovelace'];
    }

    #[DataProvider('invalidAuthors')]
    public function testRejectsNonAlphabeticInput(string $candidate): void
    {
        $this->expectException(ValidationException::class);

        new Message($candidate);
    }
}
