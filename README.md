# Flash Sale Engine

Real-time flash sale & high-concurrency inventory lock engine built on **Laravel 11**, **Redis** and **Laravel Reverb**.

> Work in progress.

## Stack

| Component | Version |
|-----------|---------|
| PHP (fpm) | 8.3 |
| Laravel | 11.20 |
| Laravel Reverb | 1.x |
| MySQL | 8.0 |
| Redis | 7.2 |
| Node (build only) | 20 |

## Quick start

Requires Docker.

```bash
cp .env.example .env
docker compose up -d --build
```

| Service | URL |
|---------|-----|
| App | http://localhost:8080 |
| Reverb (WebSocket) | ws://localhost:8081 |
| MySQL | localhost:3307 (`flash_sale` / `secret`) |
| Redis | localhost:6380 |
