<?php

declare(strict_types=1);

namespace Wfl\Voter;

use Override;
use Waffle\Commons\Contracts\Security\VoterInterface;

/**
 * Voter « EcoShield » de la route POST /locked — démonstration d'une posture
 * fail-closed avec visibilité asymétrique (PHP 8.5).
 *
 * Contrainte Beta-4 (assumée) : `VoterInterface::decide()` ne reçoit AUCUN
 * argument et le SecureContainer instancie chaque voter via `new $voter()`
 * (constructeur sans argument). Une véritable ABAC contextuelle — sujet /
 * ressource / attributs de requête — n'est donc PAS possible en Beta 4. Ce
 * voter illustre la *posture* attendue, pas l'évaluation contextuelle :
 *
 *   - **refus par défaut** (`$shieldOpen = false`) : le bouclier est fermé tant
 *     qu'une politique explicite ne l'ouvre pas ;
 *   - la décision dérive d'une politique IMMUABLE à l'échelle du processus
 *     (drapeau d'environnement `ECOSHIELD_LOCKED_OPEN`), lue une seule fois à la
 *     construction — aucun état mutable entre deux requêtes (worker-safe) ;
 *   - **`public private(set)`** : la politique est inspectable de l'extérieur
 *     mais ne peut être réécrite que depuis l'intérieur de la classe (visibilité
 *     asymétrique PHP 8.5).
 *
 * Pourquoi l'absence d'état est une exigence de SÉCURITÉ (pas une optimisation) :
 * en mode worker FrankenPHP, le processus PHP — et donc l'instance de voter
 * résolue par le SecureContainer — RESTE en mémoire et est réutilisée d'une
 * requête à l'autre, des milliers de fois. Un voter qui mémoriserait un état
 * propre à la requête (l'identité de l'appelant, le sujet évalué, une décision
 * mise en cache) transporterait cet état dans la requête SUIVANTE : c'est la
 * « pollution du contexte de sécurité » — l'autorisation accordée à un précédent
 * utilisateur fuiterait et pourrait octroyer un accès indu au suivant. Ici, le
 * voter ne détient AUCUN état de requête : son unique champ est la politique
 * immuable du processus, et chaque `decide()` est une fonction pure de cet état
 * figé. Il est donc structurellement impossible qu'un contexte de requête
 * déborde sur une autre boucle de worker, indépendamment du reset() entre deux.
 *
 * Voie d'évolution : en Beta 5 / RFC-021, `VoterInterface` recevra un
 * SecurityContext (identité authentifiée). Ce voter deviendra alors une
 * véritable règle ABAC sujet/ressource, SANS changer sa posture fail-closed.
 */
final class RestrictedAccess implements VoterInterface
{
    /**
     * Politique du bouclier, figée à la construction (immuable, worker-safe).
     * Lecture publique ; écriture réservée à cette classe (visibilité asymétrique).
     */
    public private(set) bool $shieldOpen;

    public function __construct()
    {
        // Refus par défaut : seule une valeur d'environnement explicitement
        // « vraie » (1/true/on/yes) ouvre la route /locked. Tout le reste ⇒ 403.
        $this->shieldOpen = filter_var(getenv('ECOSHIELD_LOCKED_OPEN'), FILTER_VALIDATE_BOOL);
    }

    #[Override]
    public function decide(): bool
    {
        // Fail-closed : la fermeture est l'état par défaut et sûr.
        return $this->shieldOpen;
    }
}
