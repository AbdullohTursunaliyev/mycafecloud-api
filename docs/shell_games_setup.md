# Shell Games Setup (2 PC test)

## Qisqa model

- CP/Backend kompyuter: katalog va boshqaruv.
- Shell kompyuter: o'yinni lokal ishga tushiradi.
- Tarmoq/hub: ulanish va rollout uchun.

Server o'yinni "stream" qilmaydi, o'yin Shell PC ichida ochiladi.

## 1) Migratsiya

```bash
php artisan migrate --force
```

## 2) Game list olish (Shell taraf)

Shell login bo'lgandan keyin:

- `GET /api/shell/games?pc_code=VIP01`
- `Authorization: Bearer <client_token>`

Backend tenant bo'yicha default katalogni avtomatik yaratadi (`shell_games` bo'sh bo'lsa).

## 3) Launch command

`shell_games.launch_command` da buyruq turadi.

Misol:

- `steam://rungameid/570` (Dota 2)
- `steam://rungameid/730` (CS2)
- `C:\\Games\\Riot\\RiotClientServices.exe`

Shell UI `Play` bosilganda shu command bajariladi.

## 4) Admin API (katalog boshqaruvi)

- `GET /api/shell-games`
- `POST /api/shell-games`
- `PATCH /api/shell-games/{id}`
- `POST /api/shell-games/{id}/toggle`

PC bo'yicha install holat (ixtiyoriy):

- `POST /api/pcs/{pcId}/shell-games/{gameId}/state`
  - body: `{ "is_installed": true, "version": "1.0.0" }`

## 5) 2 PC real test

1. Katta PCda backend/CP ishlasin.
2. Noutda shell ishga tushsin va login qiling.
3. Shell game list backenddan kelganini tekshiring.
4. Dota/CS2 kartasida `Play` bosing.
5. O'yin noutda ochilishi kerak.

