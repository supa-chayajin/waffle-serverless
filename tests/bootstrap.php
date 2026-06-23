<?php

declare(strict_types=1);

// Amorçage PHPUnit : résout la racine applicative et le dossier de configuration
// (mêmes constantes que public/index.php) puis charge l'autoloader Composer.
define('APP_ROOT', dirname(__DIR__));
const APP_CONFIG = 'config';

require dirname(__DIR__) . '/vendor/autoload.php';
