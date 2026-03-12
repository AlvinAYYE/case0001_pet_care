# Docker setup (frontend + backend + mysql)

This project uses three containers:

- frontend (Vue build output served by Nginx)
- backend (PHP 8.3 + Apache)
- mysql (MySQL 8.0)

## 1) Prepare environment

Copy root env template:

```bash
cp .env.example .env
```

On Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Adjust password values in .env as needed.

## 2) Start services

```bash
docker compose up -d --build
```

## 3) URLs

- Frontend: http://localhost:5180
- Backend API health: http://localhost:8080/api/health
- MySQL: not exposed to host (internal Docker network only)

## Cross-container communication

Inside Docker network, services talk by service name:

- backend -> mysql: mysql:3306
- frontend nginx -> backend: http://backend:80

MySQL is intentionally not published to host ports.

Important distinction:

- Browser requests use host ports (localhost:5180)
- Container-to-container requests use service names (backend, mysql)

## Common commands

```bash
docker compose ps
docker compose logs -f backend
docker compose logs -f frontend
docker compose logs -f mysql
docker compose down
docker compose down -v
```

docker compose down -v will remove DB data volume.
