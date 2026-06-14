# waffle-serverless — EcoShield-Minimal

> POC : *PHP Proxy Shield* serverless tournant sur **AWS Lambda** via **FrankenPHP**
> (mode worker) et l'**AWS Lambda Web Adapter** (LWA), bâti sur le framework
> [Waffle](https://github.com/waffle-commons) (`waffle-commons/*`).

L'application expose deux routes de démonstration :

```
GET /  ->  200  {"message": "hello Waffle!"}
POST /locked  ->  200  {"message": "Waffle says Hi to $message->author !"}
```

---

## Architecture

```
Évènement Lambda
      │
      ▼
AWS Lambda Web Adapter (extension /opt/extensions/lambda-adapter)
      │  traduit l'évènement en requête HTTP -> 127.0.0.1:8080
      ▼
FrankenPHP (mode worker, Caddyfile)  ── écoute sur :8080
      │  public/index.php amorcé UNE fois, résident en mémoire
      ▼
WaffleRuntime->loop()  ── boucle worker, invocations « chaudes »
      │
      ▼
AppKernel  ─►  Pipeline PSR-15
                 ErrorHandlerMiddleware
                   └─► CoreRoutingMiddleware
                         └─► ControllerDispatcher ─► HelloController::index()
```

Points clés :

- **FrankenPHP en mode worker** (`Caddyfile`) : l'application est amorcée une seule
  fois puis réside en mémoire ; `num 1` force un worker unique, optimal sur Lambda
  mono-vCPU.
- **OPcache preload** (`config/preload.php`) : compile le framework Waffle en mémoire
  partagée au démarrage du conteneur (`opcache.validate_timestamps=0` fige le cache),
  pour des démarrages « chauds » quasi instantanés.
- **Statelessness** : `AppKernel::reset()` purge l'état à portée requête entre deux
  invocations du worker.
- Le LWA est ajouté comme **extension Lambda** ; il interroge le port `8080`
  (`AWS_LWA_PORT`). C'est aussi le seul port sur lequel le conteneur écoute.

---

## Prérequis

- [Docker](https://docs.docker.com/get-docker/) (avec Buildx / Compose v2).
- Aucun accès privé : les paquets `waffle-commons/*` sont publics sur GitHub et
  résolus par Composer à partir de `composer.lock`.

---

## Build & exécution locale

### Avec Docker Compose (recommandé en local)

```bash
docker compose up --build
```

Le conteneur écoute en interne sur `:8080` ; Compose le publie sur l'hôte en `6080` :

```bash
curl http://localhost:6080/
# {"message":"hello waffle"}
```

### Avec Docker seul

```bash
# Construit le stage final « prod » (minimal, LWA + preload).
docker build --target prod -t waffle-serverless:prod .

# Le LWA n'agit qu'en contexte Lambda ; en local on interroge FrankenPHP directement.
docker run --rm -p 6080:8080 -e APP_ENV=prod -e APP_DEBUG=0 waffle-serverless:prod
curl http://localhost:6080/
```

---

## Image Docker (build multi-étapes)

| Étape | Image de base | Rôle |
|-------|---------------|------|
| `builder` | `dunglas/frankenphp:1.12.3-php8-trixie` | Dépendances Composer de production + autoloader optimisé |
| `prod`    | `dunglas/frankenphp:1.12.3-php8.5-trixie` | Image finale : LWA + OPcache preload + mode worker |

L'adaptateur Lambda est copié depuis l'ECR public AWS :

```dockerfile
COPY --from=public.ecr.aws/awsguru/aws-lambda-adapter:0.8.4 \
     /lambda-adapter /opt/extensions/lambda-adapter
```

> ⚠️ **Références d'images — pièges à éviter**
> - Le tag `dunglas/frankenphp:latest-php8.5` **n'existe pas** ; utiliser un tag
>   versionné cohérent avec le builder, ici `1.12.3-php8.5-trixie`.
> - Le dépôt ECR public est nommé **`aws-lambda-adapter`** (et non
>   `aws-lambda-web-adapter`) ; le second renvoie `name unknown` au build.

---

## Configuration

La configuration applicative vit dans `config/app.yaml` et interpole les variables
d'environnement via la syntaxe `%env(...)%`.

| Variable        | Défaut                | Rôle |
|-----------------|-----------------------|------|
| `APP_ENV`       | `prod`                | Environnement applicatif (`dev`/`prod`/`test`). |
| `APP_DEBUG`     | `0` / `false`         | Mode debug — **doit rester `false`** en production. |
| `AWS_LWA_PORT`  | `8080`                | Port interrogé par le Lambda Web Adapter (= port d'écoute). |
| `MAX_REQUESTS`  | `500`                 | Nombre d'invocations traitées par le worker avant recyclage. |
| `SERVER_NAME`   | `localhost`           | Nom de domaine servi (utilisé par Compose). |
| `APP_SECRET`    | `ChangeMeInProduction`| Secret applicatif — à injecter via la CI/CD ou Docker Secrets en production. |

---

## Structure du projet

```
.
├── Caddyfile                     # FrankenPHP : mode worker + écoute :8080
├── Dockerfile                    # Build multi-étapes (builder -> prod)
├── docker-compose.yml            # Service local (publie 6080 -> 8080)
├── composer.json / composer.lock # Dépendances waffle-commons/*
├── config/
│   ├── app.yaml                  # Configuration Waffle (interpolation %env(...)%)
│   └── preload.php               # Préchargement OPcache du framework
├── public/
│   └── index.php                 # Point d'entrée : assemble le Kernel + WaffleRuntime
└── src/
    ├── Controller/HelloController.php  # Route GET / -> {"message":"hello waffle"}
    ├── Factory/AppKernelFactory.php    # Assemblage (conteneur, config, sécurité, pipeline)
    └── Kernel/AppKernel.php            # Kernel résident (reset() entre invocations)
```

---

## Licence

MIT — voir `composer.json`.
