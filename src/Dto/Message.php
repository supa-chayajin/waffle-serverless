<?php

declare(strict_types=1);

namespace Wfl\Dto;

use Waffle\Commons\Contracts\Attribute\Dto;
use Waffle\Exception\ValidationException;

/**
 * DTO de la route POST /locked — démonstration des « property hooks » PHP 8.5
 * pour valider et assainir l'entrée AU PLUS PRÈS de la donnée (RFC-011).
 *
 * `private(set)` (visibilité asymétrique) : `author` est lisible publiquement
 * mais ne peut être affecté que via le hook `set`, qui rejette toute valeur non
 * conforme. Un DTO à hook ne peut PAS être `readonly` (contrainte PHP 8.5).
 */
#[Dto]
final class Message
{
    public function __construct(
        private(set) string $author {
            set(string $value) {
                $clean = mb_trim($value);

                if ($clean === '' || preg_match('/^\p{L}+$/u', $clean) !== 1) {
                    throw new ValidationException(
                        message: 'Le champ « author » doit être une chaîne non vide composée uniquement de lettres.',
                        field: 'author',
                    );
                }

                $this->author = $clean;
            }
        },
    ) {}
}
