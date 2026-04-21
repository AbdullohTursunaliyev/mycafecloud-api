# MyCafeCloud: iCafe-uslubida oson o'rnatish va tarqatish

Bu modul mavjud `agent/poll + pc_commands` oqimi ustiga qurilgan.

## 1) Tez ulash (pair code)

- `POST /api/deployment/quick-install`
- `POST /api/deployment/quick-install/bulk`
- `GET /api/deployment/pair-codes?status=active|used|expired`
- `DELETE /api/deployment/pair-codes/{CODE}` (faqat ishlatilmagan kod uchun)

Body (`quick-install`):

```json
{
  "zone_id": 1,
  "expires_in_min": 10
}
```

Body (`quick-install/bulk`):

```json
{
  "count": 114,
  "zone_id": 1,
  "expires_in_min": 30
}
```

`quick-install` javobida:
- `pair_code`
- `installer_script_url`
- `install_one_liner`
- `installer_script`

Public script endpoint:
- `GET /api/deployment/quick-install/{CODE}/script.ps1`

Bu endpoint authsiz ishlaydi, lekin faqat:
- kod formati to'g'ri bo'lsa
- kod ishlatilmagan bo'lsa
- kod muddati tugamagan bo'lsa

## 2) Agentni 1 buyruq bilan ulash (iCafe uslubi)

Windows PowerShell (Admin) da:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -Command "iwr -UseBasicParsing -Uri 'https://YOUR-DOMAIN/api/deployment/quick-install/ABCD-EF/script.ps1' | iex"
```

Skript:
- optional installer (`deploy_agent_download_url`) ni yuklaydi va ishga tushiradi
- `POST /api/agent/pair` bilan serverga pair qiladi
- device token oladi

## 3) Rollout yuborish

- `POST /api/deployment/rollout`

Body (misol):

```json
{
  "type": "UPDATE_SHELL",
  "payload": {
    "version": "1.2.4",
    "download_url": "https://cdn.example.com/shell-1.2.4.zip",
    "sha256": "..."
  },
  "target_mode": "online",
  "only_online": true
}
```

`target_mode`:
- `all`
- `online`
- `zone` (`zone_id` kerak)
- `selected` (`pc_ids` kerak)

`dry_run: true` yuborsangiz faqat qaysi PC lar tanlanishini ko'rsatadi, command yaratmaydi.

## 4) Batch monitoring

- `GET /api/deployment/batches` - oxirgi batchlar
- `GET /api/deployment/batches/{batchId}` - bitta batch holati
- `POST /api/deployment/batches/{batchId}/retry-failed` - faqat failed larni qayta yuborish

## 5) Tavsiya etilgan 114 ta PC jarayoni

1. 114 ta `pair_code` ni `quick-install/bulk` bilan yarating.
2. Har PC da bir martalik one-liner bilan agentni ulang.
3. `GET /api/pcs` orqali hammasi `online` ekanini tekshiring.
4. `rollout` ni avval `selected` (5-10 test PC) ga yuboring.
5. So'ng `online` rejimida barcha PClarga rollout qiling.
6. `retry-failed` bilan yiqilganlarini qayta yuboring.

## 6) Eslatma

Server buyruqni yuboradi, lekin commandni bajarish agent implementatsiyasiga bog'liq.
Yangi type lar:
- `INSTALL_GAME`
- `UPDATE_GAME`
- `ROLLBACK_GAME`
- `UPDATE_SHELL`
- `RUN_SCRIPT`
