<?php

declare(strict_types=1);

namespace Wfl\Dto;

use Waffle\Commons\Contracts\Attribute\Dto;
use Waffle\Exception\ValidationException;

/**
 * DTO de la route POST /locked — démonstration des « property hooks » PHP 8.5
 * qui valident et assainissent l'entrée AU PLUS PRÈS de la donnée (RFC-011), de
 * façon synchrone, au niveau du moteur du langage : aucune étape de validation
 * externe ne peut être oubliée.
 *
 * Visibilité asymétrique « public private(set) » : `content` est lisible
 * publiquement mais ne peut être écrit QUE depuis l'intérieur de la classe — le
 * hook `set` est donc l'unique point d'entrée et rejette toute valeur non
 * conforme avant qu'aucun état ne soit stocké. Un DTO à hook ne peut PAS être
 * `readonly` (contrainte PHP 8.5) : l'immuabilité repose sur l'écriture unique
 * « private(set) », le constructeur étant le seul à affecter la propriété.
 */
#[Dto]
final class Message
{
    /**
     * Contenu du message, assaini (trim) puis validé (non vide) par le hook `set`.
     * Lecture publique ; écriture réservée à cette classe (visibilité asymétrique).
     */
    public private(set) string $content {
        set(string $value) {
            $clean = mb_trim($value);

            if ($clean === '') {
                throw new ValidationException(
                    message: 'Le champ « content » ne peut être vide ou ne contenir que des espaces.',
                    field: 'content',
                );
            }

            $this->content = $clean;
        }
    }

    public function __construct(string $content)
    {
        // Affectation directe ⇒ déclenche le hook `set` dès l'instanciation :
        // assainissement et validation synchrones, sans étape externe possible.
        $this->content = $content;
    }
}
