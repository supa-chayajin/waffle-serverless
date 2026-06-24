# syntax=docker/dockerfile:1

# =============================================================================
# EcoShield-Minimal — image FrankenPHP (mode worker) pour Kubernetes (OVHcloud).
#
# Build multi-étapes :
#   1. builder : dépendances Composer de production + autoloader optimisé ;
#   2. prod    : image finale durcie (OPcache preload + JIT + worker + non-root).
#
# La terminaison TLS est assurée par l'Ingress NGINX en amont (cf. k8s/) : le
# conteneur ne sert qu'en clair sur :8080. Aucun adaptateur Lambda — déploiement
# 100 % Kubernetes.
# =============================================================================

# ---- Étape 1 : Builder (dépendances Composer optimisées) --------------------
FROM dunglas/frankenphp:1.12.3-php8.5-trixie AS builder

# Composer fourni par l'image officielle.
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Outils de build + extensions exigées par composer.json (yaml + opcache).
RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip \
        libyaml-dev \
    && install-php-extensions opcache yaml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Cache Docker : manifestes d'abord, puis installation des vendors de PROD.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --no-interaction --optimize-autoloader

# Code applicatif (le .dockerignore exclut tests/, vendor/, .git…), puis
# autoloader autoritaire (classmap figée).
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---- Étape 2 : Production (image minimale et durcie) ------------------------
FROM dunglas/frankenphp:1.12.3-php8.5-trixie AS prod

WORKDIR /app

# Extensions runtime (OPcache + YAML) et curl (sondes / debug en direct).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libyaml-dev curl \
    && install-php-extensions opcache yaml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Environnement d'exécution (surchargé par la ConfigMap K8s en cluster).
#   - FRANKENPHP_NUM_WORKERS : nombre de workers résidents (≈ CPU alloué) ;
#   - MAX_REQUESTS           : recyclage du worker après N requêtes (borne mémoire) ;
#   - XDG_*_HOME             : dossiers inscriptibles de Caddy (montés en emptyDir
#                             sous K8s avec un rootfs en lecture seule).
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    FRANKENPHP_NUM_WORKERS=2 \
    MAX_REQUESTS=500 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

# Caddyfile du POC (mode worker + écoute :8080).
COPY docker/frankenphp/caddy/Caddyfile /etc/frankenphp/Caddyfile

# Code applicatif préparé par le builder.
COPY --from=builder /app/vendor /app/vendor
COPY --from=builder /app/src /app/src
COPY --from=builder /app/config /app/config
COPY --from=builder /app/public /app/public

# OPcache : préchargement du framework en mémoire partagée + dimensionnement et
# JIT pour le mode worker. « validate_timestamps=0 » fige le cache (code immuable
# en production) ⇒ démarrages « chauds » instantanés.
RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=0"; \
      echo "opcache.preload=/app/config/preload.php"; \
      echo "opcache.preload_user=app"; \
      echo "opcache.validate_timestamps=0"; \
      echo "opcache.memory_consumption=128"; \
      echo "opcache.interned_strings_buffer=16"; \
      echo "opcache.max_accelerated_files=20000"; \
      echo "opcache.jit=tracing"; \
      echo "opcache.jit_buffer_size=64M"; \
    } > "$PHP_INI_DIR/conf.d/zz-worker.ini"

# Durcissement : utilisateur non-root + dossiers inscriptibles. Permet à K8s
# d'imposer runAsNonRoot + readOnlyRootFilesystem (cf. deployment.yaml).
RUN groupadd -g 1001 app \
    && useradd -u 1001 -g app -d /app -s /usr/sbin/nologin app \
    && mkdir -p /config /data \
    && chown -R app:app /app /config /data
USER app

EXPOSE 8080

# Sonde Docker locale (Compose) ; en cluster, ce sont les probes K8s qui font foi.
# L'en-tête « Host: localhost » est OBLIGATOIRE : le TrustedHostMiddleware (RFC-003)
# rejette tout hôte hors allow-list (un appel direct à 127.0.0.1 ⇒ 400, donc un
# conteneur faussement « unhealthy »). Les probes K8s injectent le même en-tête
# (cf. k8s/deployment.yaml).
HEALTHCHECK --interval=30s --timeout=3s --start-period=20s --retries=3 \
    CMD curl -fsS -H "Host: localhost" http://127.0.0.1:8080/healthz || exit 1

# Lancement de FrankenPHP en mode worker, piloté par le Caddyfile.
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
