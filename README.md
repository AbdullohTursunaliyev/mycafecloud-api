# MyCafeCloud API

Laravel 10 asosidagi backend. Bu repo billing, shift, booking, PC command, reports va deploy oqimlarini boshqaradi.

## Docker bilan ishga tushirish

1. Docker uchun env tayyorlang:

```bash
cp .env.docker.example .env.docker
```

2. Stackni ishga tushiring:

```bash
docker compose up --build -d
```

3. API default holatda quyida ochiladi:

- API: `http://localhost:8080`
- PostgreSQL: `localhost:5432`
- Redis: `localhost:6379`

## To'liq stack

Agar `mycafecloud-cp`, `mycafecloud-saas` va `mycafecloud-api` repo'lari bitta parent papka ichida yonma-yon tursa, to'liq stackni ham shu repo ichidan ko'tarish mumkin:

```bash
docker compose -f docker-compose.full.yml up --build -d
```

Bu holda:

- CP: `http://localhost:3000`
- SaaS: `http://localhost:4173`
- API: `http://localhost:8080`

## Eslatma

- Docker build vaqtida `ext-cassandra` platform talabi ignore qilinadi.
- Scheduler konteyneri `pcs:heartbeat-check`, `bookings:expire`, `billing:sessions-tick`, `shifts:auto-tick` komandalarini har daqiqada yuritadi.
- Agar ishlab chiqarishdagi maxsus env qiymatlar bo'lsa, ularni `.env.docker` ga ko'chiring.
