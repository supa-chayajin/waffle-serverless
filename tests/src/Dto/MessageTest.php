<?php

declare(strict_types=1);

namespace WflTests\Dto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waffle\Exception\ValidationException;
use Wfl\Dto\Message;

/**
 * Vérifie le « property hook » set du DTO : assainissement (trim, y compris les
 * espaces Unicode) puis validation (rejet du vide / des espaces seuls), au plus
 * près de la donnée.
 */
#[CoversClass(Message::class)]
final class MessageTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function validContents(): iterable
    {
        yield 'trims surrounding whitespace' => ['  Ada  ', 'Ada'];
        yield 'preserves inner spaces and words' => ['Bonjour Waffle', 'Bonjour Waffle'];
        yield 'accepts accents, digits and punctuation' => ['Léa a écrit 42 !', 'Léa a écrit 42 !'];
        yield 'trims tabs and newlines' => ["\t Salut \n", 'Salut'];
    }

    #[DataProvider('validContents')]
    public function testTrimsAndAcceptsNonEmptyContent(string $input, string $expected): void
    {
        static::assertSame($expected, new Message($input)->content);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function blankContents(): iterable
    {
        yield 'empty string' => [''];
        yield 'spaces only' => ['   '];
        yield 'tabs and newlines only' => ["\t\n "];
        yield 'non-breaking space only' => ["\u{00A0}"];
    }

    #[DataProvider('blankContents')]
    public function testRejectsEmptyOrWhitespaceOnlyInput(string $candidate): void
    {
        $this->expectException(ValidationException::class);

        new Message($candidate);
    }
}
