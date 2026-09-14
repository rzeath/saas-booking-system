# TakdaOps

Event Booking Management

Developed by Rzeath

TakdaOps is a multi-tenant internal event booking management system. It is initially focused on photobooth businesses while keeping the core architecture suitable for other event-service businesses. Phase 1 provides self-registration and session-based authentication for one admin user per organization; business domains remain deferred.

## Prerequisites

- Docker Engine with Docker Compose
- Git

PHP, Composer, Node, MySQL, and Redis run in containers, so matching host installations are not required.

## First-time setup

```sh
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env
docker compose build
docker compose up -d
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate
```

Open <http://localhost:8080>. Nginx serves the Vite development application and forwards `/api/*` and Sanctum's CSRF endpoint to Laravel over the internal Docker network. Register an organization and its single admin at `/register`, or sign in at `/login`.

Sanctum uses the first-party session cookie and CSRF flow; the frontend does not store bearer tokens. For the default local URL, keep `SANCTUM_STATEFUL_DOMAINS=localhost:8080`, `SESSION_SECURE_COOKIE=false`, and `SESSION_SAME_SITE=lax`. Use secure cookie settings appropriate to an HTTPS deployment.

The example credentials are development-only. Replace them in the ignored `backend/.env` before using the environment beyond local development. If your Linux user does not use UID/GID `1000`, set `HOST_UID` and `HOST_GID` in your shell before building.

## Development commands

```sh
# Start the full development environment
docker compose up -d

# Follow service logs
docker compose logs -f nginx php node

# Run Laravel migrations
docker compose exec php php artisan migrate

# Run backend tests
docker compose exec php php artisan test

# Run frontend tests, type checking, linting, and a production build
docker compose exec node npm test
docker compose exec node npm run typecheck
docker compose exec node npm run lint
docker compose exec node npm run build
```

The frontend API base path is configured by `VITE_API_BASE_URL` in `frontend/.env`; `/api` is the same-origin development default.

Composer and npm dependencies are installed into the development images and exposed through container-only volumes, so host `vendor` and `node_modules` directories are not used. After changing either lockfile, rebuild the affected image and recreate its anonymous dependency volume. For example:

```sh
docker compose build php node
docker compose up -d --force-recreate --renew-anon-volumes php node nginx
```

## Stop the environment

```sh
docker compose down
```

To also remove local MySQL and Redis data, run `docker compose down -v`. This permanently deletes the development database and Redis volumes.
