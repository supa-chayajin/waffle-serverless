# syntax=docker/dockerfile:1

# =============================================================================
# EcoShield-Minimal — image serverless FrankenPHP pour AWS Lambda.
#
# Build multi-étapes :
#   1. builder : installation des dépendances Composer de production ;
#   2. prod    : image finale minimale (LWA + OPcache preload + worker mode).
# =============================================================================

# ---- Étape 1 : Builder (dépendances Composer optimisées) --------------------
FROM dunglas/frankenphp:1.12.3-php8-trixie AS builder

# Composer fourni par l'image officielle.
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Outils de build + extensions requises (yaml pour le parseur de configuration).
RUN apt-get update && apt-get install -y --no-install-recommends \
    acl \
    file \
    gettext \
    libyaml-dev \
    zip \
    unzip \
    && install-php-extensions \
        apcu \
        gd \
        intl \
        opcache \
        zip \
        yaml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Cache Docker : on copie d'abord les manifestes, puis on installe les vendors.
COPY composer.json composer.lock ./

RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --no-interaction --optimize-autoloader

# Copie du code applicatif, puis autoloader optimisé (classmap autoritaire).
COPY . .

# Enable Preload ONLY in production and ONLY after code is copied
RUN echo "opcache.validate_timestamps = 1" >> $PHP_INI_DIR/conf.d/app.ini \
    && echo "opcache.preload=/app/config/preload.php" >> $PHP_INI_DIR/conf.d/app.ini

# Autoloader warmup
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---- Étape 2 : Production (image minimale et sécurisée) ---------------------
FROM dunglas/frankenphp:1.12.3-php8.5-trixie AS prod

# Injection du AWS Lambda Web Adapter (LWA) en tant qu'extension Lambda :
# il convertit chaque évènement Lambda en requête HTTP vers le port local 8080.
COPY --from=public.ecr.aws/awsguru/aws-lambda-adapter:0.8.4 /lambda-adapter /opt/extensions/lambda-adapter

WORKDIR /app

# Extensions runtime : OPcache (préchargement) + YAML (lecture de app.yaml).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libyaml-dev \
    && install-php-extensions opcache yaml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Environnement d'exécution.
#   - APP_ENV / APP_DEBUG : alimentent l'interpolation %env(...)% de app.yaml ;
#   - AWS_LWA_PORT : port que le Lambda Web Adapter interroge (doit valoir 8080).
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    AWS_LWA_PORT=8080

# Caddyfile du POC (mode worker + écoute sur :8080).
COPY docker/frankenphp/caddy/Caddyfile /etc/frankenphp/Caddyfile

# Code applicatif préparé par le builder.
COPY --from=builder /app/vendor /app/vendor
COPY --from=builder /app/src /app/src
COPY --from=builder /app/config /app/config
COPY --from=builder /app/public /app/public

# Préchargement OPcache : compile les classes de Waffle en mémoire partagée au
# démarrage du conteneur. « validate_timestamps=0 » fige le cache (code immuable
# en production), gage de démarrages « chauds » instantanés.
RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.preload=/app/config/preload.php"; \
      echo "opcache.preload_user=root"; \
      echo "opcache.validate_timestamps=0"; \
    } > "$PHP_INI_DIR/conf.d/zz-serverless.ini"

# Le AWS Lambda Web Adapter interroge ce port pour relayer les évènements.
EXPOSE 8080

# Lancement de FrankenPHP en mode worker, piloté par le Caddyfile.
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
