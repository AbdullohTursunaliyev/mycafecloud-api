# Cloud Game Profile Sync (CS2/Dota + Logitech/Razer)

Bu modul user o'yin sozlamalarini bir kompyuterdan boshqasiga avtomatik olib o'tadi.

## Flow

1. Client login qiladi (`/api/client-auth/login`).
2. Backend shu PC ga `APPLY_CLOUD_PROFILE` command qo'yadi.
3. Agent commandni poll qiladi:
   - `/api/agent/commands/poll`
4. Agent profile metadata oladi:
   - `GET /api/agent/profiles/pull?client_login=<login>&game_slug=cs2`
5. Profile archive bo'lsa, yuklaydi:
   - `GET /api/agent/profiles/{id}/download`
6. Agent local game config papkalariga apply qiladi.
7. Client logout paytida backend `BACKUP_CLOUD_PROFILE` command qo'yadi.
8. Agent current configni zip qilib upload qiladi:
   - `POST /api/agent/profiles/push`

## Endpoints

- Client token (`client.auth`):
  - `GET /api/client/game-profiles`
  - `GET /api/client/game-profiles/{gameSlug}`
  - `POST /api/client/game-profiles/{gameSlug}` (multipart/file + json)
  - `GET /api/client/game-profiles/{gameSlug}/download`

- Agent token (`pc.device`):
  - `GET /api/agent/profiles/pull`
  - `POST /api/agent/profiles/push`
  - `GET /api/agent/profiles/{id}/download`

## Command payloads

### APPLY_CLOUD_PROFILE

```json
{
  "trigger": "client_login",
  "client_id": 123,
  "client_login": "946445291",
  "session_id": 998,
  "pc_code": "VIP03",
  "profiles": [
    {"game_slug":"cs2","version":5,"has_archive":true}
  ],
  "mouse_vendor_priority": ["logitech", "razer", "generic"]
}
```

### BACKUP_CLOUD_PROFILE

```json
{
  "trigger": "client_logout",
  "client_id": 123,
  "client_login": "946445291",
  "session_id": 998,
  "pc_code": "VIP03",
  "capture_all_known_games": true
}
```

## Logitech / Razer yechim

Hardware-level DPI/lighting API'lari vendor appga bog'liq. Shu sabab 2 bosqichli strategiya ishlatiladi:

1) **Guaranteed layer (always works)**  
- In-game configlar (bind/crosshair/sensitivity/video) cloud orqali apply/backup qilinadi.

2) **Vendor layer (best effort)**  
- `mouse_hints` da profil nomlari beriladi (`MyCafeCloud-CS2` va h.k.).
- Agent avval Logitech G HUB profile import/activatega urinadi.
- Agar mavjud bo'lmasa, Razer Synapse profile activatega urinadi.
- Ikkalasi ham bo'lmasa `generic` fallback ishlaydi (faqat in-game settings).

## CS2 default config paths

- `%STEAM%/userdata/%STEAM_ID%/730/local/cfg`
- `%STEAM%/steamapps/common/Counter-Strike Global Offensive/game/csgo/cfg`

## Dota2 default config paths

- `%STEAM%/userdata/%STEAM_ID%/570/remote/cfg`
- `%STEAM%/steamapps/common/dota 2 beta/game/dota/cfg`

