<?php

declare(strict_types=1);

namespace Wfl\Kernel;

use Waffle\Kernel as BaseKernel;

/**
 * Kernel applicatif d'EcoShield-Minimal.
 *
 * Étend le Kernel CONCRET de Waffle (Waffle\Kernel), et non l'abstrait
 * Waffle\Abstract\AbstractKernel : l'analyseur de sécurité du framework
 * (System::boot → Security::analyze) EXIGE que le noyau soit une instance de
 * `Waffle\Kernel & AbstractKernel & KernelInterface`. Hériter directement de
 * l'abstrait déclencherait une SecurityException au démarrage.
 *
 * Tout le cycle de vie résident est déjà fourni par AbstractKernel ; aucune
 * logique n'est réécrite ici :
 *   - boot()      : lecture de l'environnement processus (une seule fois) ;
 *   - configure() : compilation du conteneur, des contrôleurs et du pipeline en
 *                   mémoire partagée (une seule fois, au démarrage du worker) ;
 *   - handle()    : chemin chaud, exécuté à CHAQUE requête ;
 *   - reset()     : purge de l'état à portée requête entre deux itérations du
 *                   worker FrankenPHP — garant de l'absence de fuite d'état
 *                   (statelessness).
 *
 * Cette classe reste vide À DESSEIN : un point d'extension prêt à l'emploi, sans
 * dette. Réécrire un crochet du cycle de vie fragiliserait un flux déjà éprouvé.
 */
final class AppKernel extends BaseKernel {}
