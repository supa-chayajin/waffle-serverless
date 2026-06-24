# waffle-serverless — EcoShield-Minimal

> POC : *PHP Proxy Shield* en **mode worker FrankenPHP**, déployé sur
> **OVHcloud Managed Kubernetes**, bâti sur le framework
> [Waffle](https://github.com/waffle-commons) (`waffle-commons/*`, **Beta 4**).

Une application volontairement minimale qui met en scène, en quelques classes,
les capacités de Waffle Beta 4 et de PHP 8.5 :

- un **pipeline de sécurité « Proxy-Shield » réellement appliqué** (CSRF + voter
  ABAC fail-closed + en-têtes de durcissement) ;
- un **DTO à property hooks** (validation/assainissement au plus près de la donnée) ;
- un **voter à visibilité asymétrique** (`public private(set)`) ;
- un conteneur **FrankenPHP worker** durci (OPcache preload + JIT, non-root,
  rootfs en lecture seule) prêt pour **Kubernetes**.

---

## Routes

| Méthode & chemin | Accès | Réponse |
|------------------|-------|---------|
| `GET /`          | public | `200` `{"message":"hello Waffle!"}` |
| `GET /healthz`   | public | `200` `{"status":"ok"}` — sonde *liveness* |
| `GET /readyz`    | public | `200` `{"status":"ready"}` — sonde *readiness* |
| `GET /csrf`      | public | `200` `{"token":"…","header":"X-CSRF-Token","hint":"…"}` |
| `POST /locked`   | **double bouclier** | `200` greeting · `403` (CSRF/voter) · `422` (DTO) |

`POST /locked` traverse deux boucliers **indépendants** :

1. **CSRF** (`#[RequiresCsrfToken]`) — jeton HMAC sans état, lié au SID anonyme
   du navigateur ; absent/invalide ⇒ `403`.
2. **Voter ABAC** (`#[Voter]` → `RestrictedAccess`) — **refus par défaut**
   (fail-closed) tant que `ECOSHIELD_LOCKED_OPEN` n'est pas « vrai » ⇒ `403`.

Puis le corps JSON est hydraté et validé par le DTO `Message` (property hook) :
un `content` vide ou composé uniquement d'espaces ⇒ `422`.

---

## Architecture (Kubernetes)

```
                Internet (HTTPS)
                      │
                      ▼
        Ingress NGINX  ── terminaison TLS (cert-manager / Let's Encrypt)
                      │  (LoadBalancer OVHcloud)
                      ▼
        Service (ClusterIP :80 → :8080)
                      │
                      ▼
        Deployment — pods FrankenPHP (mode worker, non-root, rootfs RO)
          public/index.php amorcé UNE fois, résident en mémoire
                      │
                      ▼
        WaffleRuntime->loop()  ── invocations « chaudes »
                      │
                      ▼
        Pipeline PSR-15 « Proxy-Shield » :
          ErrorHandler → TrustedHost → CORS → AnonymousSession
            → Routing → CSRF → Security/Voter → SecureHeaders
              → ControllerDispatcher
```

Points clés :

- **Mode worker FrankenPHP** : l'app est amorcée une seule fois puis réside en
  mémoire. `FRANKENPHP_NUM_WORKERS` cale le nombre de workers sur le CPU du pod ;
  `MAX_REQUESTS` recycle le worker pour borner la mémoire.
- **OPcache preload + JIT** (`config/preload.php`) : le framework est compilé en
  mémoire partagée au démarrage (`validate_timestamps=0` fige le cache).
- **Statelessness** : audité par `wfl igor` (0 KO) — aucun état ne fuit entre
  deux requêtes du worker.
- **Durcissement** : conteneur non-root (uid 1001), `readOnlyRootFilesystem`,
  capabilities `drop: ALL`, API d'admin Caddy désactivée.

---

## Prérequis

- [Docker](https://docs.docker.com/get-docker/) (Buildx / Compose v2) pour le local.
- Les paquets `waffle-commons/*` (Beta 4) sont **publics** sur Packagist/GitHub et
  résolus par Composer depuis `composer.lock`.

---

## Exécution locale

```bash
docker compose up --build
# Le conteneur écoute en interne sur :8080, publié sur l'hôte en 6080.
```

### Démonstration « en direct » des deux boucliers

```bash
# 1. Route publique
curl http://localhost:6080/
# {"message":"hello Waffle!"}

# 2. Bouclier 1 (CSRF) : POST sans jeton → 403
curl -i -X POST http://localhost:6080/locked \
  -H 'Content-Type: application/json' -d '{"content":"Ada"}'
# HTTP/1.1 403 Forbidden — "Missing CSRF token…"

# 3. On forge un jeton (et on capture le cookie WAFFLE_SID)
TOKEN=$(curl -s -c cj.txt http://localhost:6080/csrf \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

# 4. Bouclier 2 (voter) : jeton OK mais ECOSHIELD_LOCKED_OPEN=false (défaut) → 403
curl -i -b cj.txt -X POST http://localhost:6080/locked \
  -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' -d '{"content":"Ada"}'
# HTTP/1.1 403 Forbidden — "Access refused by …RestrictedAccess"

# 5. On ouvre le bouclier (redémarrer compose avec ECOSHIELD_LOCKED_OPEN=true)
ECOSHIELD_LOCKED_OPEN=true docker compose up -d
TOKEN=$(curl -s -c cj.txt http://localhost:6080/csrf | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
curl -b cj.txt -X POST http://localhost:6080/locked \
  -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' -d '{"content":"Ada"}'
# {"message":"Waffle says Hi to Ada !"}

# 6. DTO (property hook) : content vide ou composé d'espaces seuls → 422
curl -i -b cj.txt -X POST http://localhost:6080/locked \
  -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' -d '{"content":"   "}'
# HTTP/1.1 422 Unprocessable Entity — "…vide ou ne contenir que des espaces."
```

---

## Image Docker (multi-étapes)

| Étape | Base | Rôle |
|-------|------|------|
| `builder` | `dunglas/frankenphp:1.12.3-php8.5-trixie` | dépendances Composer de prod + autoloader autoritaire |
| `prod`    | `dunglas/frankenphp:1.12.3-php8.5-trixie` | image finale durcie : OPcache preload + JIT, worker, non-root |

```bash
docker build --target prod -t waffle-serverless:prod -f docker/Dockerfile .
```

---

## Déploiement sur OVHcloud Managed Kubernetes

### Prérequis cluster

1. **Ingress NGINX** installé (un LoadBalancer public est provisionné par OVHcloud) :
   ```bash
   helm upgrade --install ingress-nginx ingress-nginx \
     --repo https://kubernetes.github.io/ingress-nginx \
     --namespace ingress-nginx --create-namespace
   ```
2. **cert-manager** + un `ClusterIssuer` Let's Encrypt nommé `letsencrypt-prod`
   (solveur HTTP-01 via l'ingress `nginx`).
3. Un enregistrement **DNS A** pointant `waffle-serverless.example.com` vers
   l'IP publique du LoadBalancer de l'ingress.

### 1. Construire et pousser l'image

Vers GitHub Container Registry **ou** l'OVH Managed Private Registry (Harbor) :

```bash
# GHCR
docker build --target prod -t ghcr.io/supa-chayajin/waffle-serverless:0.1.0 -f docker/Dockerfile .
docker push ghcr.io/supa-chayajin/waffle-serverless:0.1.0

# …ou OVH Harbor : <projet>.<region>.registry.ovh.net/<repo>/waffle-serverless:0.1.0
```

### 2. Adapter les manifestes

- `k8s/configmap.yaml` → `SERVER_NAME` = votre domaine.
- `k8s/ingress.yaml` → `host` + `tls.hosts` = votre domaine.
- `k8s/kustomization.yaml` → `images[].newName/newTag` = votre image.
- **Secret CSRF** (≥ 32 octets) créé HORS GIT :
  ```bash
  kubectl create namespace waffle-serverless
  kubectl -n waffle-serverless create secret generic waffle-serverless-secret \
    --from-literal=WAFFLE_CSRF_SECRET="$(openssl rand -base64 48)"
  ```
  (Le `k8s/secret.yaml` fourni n'est qu'un **gabarit** ; ne committez jamais de
  secret réel. Pour un déploiement « tout-en-un », remplacez sa valeur avant le
  `apply`.)

### 3. Déployer

```bash
kubectl apply -k k8s/
kubectl -n waffle-serverless rollout status deploy/waffle-serverless
```

### 4. Vérifier

```bash
curl https://waffle-serverless.example.com/healthz   # {"status":"ok"}
curl https://waffle-serverless.example.com/           # {"message":"hello Waffle!"}
```

> **Autoscaling (optionnel)** : `kubectl -n waffle-serverless apply -f k8s/hpa.yaml`
> (requiert metrics-server ; retirez alors `replicas` du Deployment).

---

## Configuration (variables d'environnement)

| Variable | Défaut | Rôle |
|----------|--------|------|
| `APP_ENV` | `prod` | Environnement applicatif. |
| `APP_DEBUG` | `0` | Mode debug — **`0` en production**. |
| `SERVER_NAME` | `localhost` | Domaine servi → `waffle.trusted_hosts` (anti-injection Host). |
| `WAFFLE_CSRF_SECRET` | *(requis)* | Secret HMAC CSRF, **≥ 32 octets** ; absent/court ⇒ le boot prod avorte (fail-closed). |
| `ECOSHIELD_LOCKED_OPEN` | `false` | Ouvre/ferme le voter de `POST /locked` (fail-closed). |
| `FRANKENPHP_NUM_WORKERS` | `2` | Workers résidents — à caler sur le CPU du pod. |
| `MAX_REQUESTS` | `500` | Recyclage du worker après N requêtes (borne mémoire). |

> Les sondes Kubernetes présentent `Host: localhost` (toujours de confiance),
> elles sont donc indépendantes de `SERVER_NAME`.

---

## Structure du projet

```
.
├── docker/
│   ├── Dockerfile                      # build multi-étapes (builder → prod durci)
│   └── frankenphp/caddy/Caddyfile      # FrankenPHP worker + admin off + :8080
├── docker-compose.yml                  # service local (publie 6080 → 8080)
├── k8s/                                # manifestes OVHcloud (kustomize)
│   ├── namespace / configmap / secret
│   ├── deployment (probes, securityContext, drain) / service / ingress
│   ├── hpa.yaml (optionnel) / kustomization.yaml
├── config/
│   ├── app.yaml                        # config Waffle (%env(...)%, sécurité)
│   └── preload.php                     # préchargement OPcache
├── public/index.php                    # point d'entrée : Kernel + WaffleRuntime
├── src/
│   ├── Controller/HelloController.php   # /, /csrf, POST /locked
│   ├── Controller/HealthController.php  # /healthz, /readyz
│   ├── Dto/Message.php                  # property hook + private(set)
│   ├── Voter/RestrictedAccess.php       # fail-closed + private(set)
│   ├── Factory/AppKernelFactory.php     # assemblage du pipeline « Proxy-Shield »
│   └── Kernel/AppKernel.php
└── tests/                               # PHPUnit (intégration + DTO + voter)
```

---

## Qualité (definition of done)

Tous verts, sortie Mago à **zéro** :

```bash
composer install            # PHP 8.5 + ext-yaml requis (ou via le conteneur)
composer mago               # fmt + lint + analyze + guard
composer tests              # PHPUnit
composer igor               # audit worker-safety (0 KO)
```

---

## Licence

MIT — voir `composer.json`.
