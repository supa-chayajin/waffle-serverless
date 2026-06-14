<?php

declare(strict_types=1);

namespace Wfl\Kernel;

use Waffle\Kernel as BaseKernel;

/**
 * Kernel applicatif d'EcoShield-Minimal.
 *
 * Étend le Kernel CONCRET de Waffle (Waffle\Kernel), et non l'abstrait
 * Waffle\Abstract\AbstractKernel : l'analyseur de sécurité du framework
 * (System::boot -> Security::analyze) EXIGE que le noyau soit une instance de
 * `Waffle\Kernel & AbstractKernel & KernelInterface`. Hériter directement de
 * l'abstrait déclencherait une SecurityException au démarrage.
 *
 * Waffle\Kernel implémente déjà, via AbstractKernel, l'intégralité du cycle de
 * vie résident :
 *   - boot()      : lecture de l'environnement processus (une seule fois) ;
 *   - configure() : compilation du conteneur, des contrôleurs et du pipeline
 *                   en mémoire partagée (une seule fois, au démarrage du worker) ;
 *   - handle()    : chemin chaud, exécuté à CHAQUE invocation Lambda ;
 *   - reset()     : purge de l'état à portée requête entre deux itérations.
 *
 * On ne réécrit AUCUNE logique de cycle de vie : la dupliquer introduirait de la
 * dette et fragiliserait un flux déjà éprouvé. Seul `reset()` est surchargé —
 * point d'extension documenté garantissant l'absence de fuite d'état entre les
 * invocations du worker FrankenPHP (statelessness).
 */
final class AppKernel extends BaseKernel {}
