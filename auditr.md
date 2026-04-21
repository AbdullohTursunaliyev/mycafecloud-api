# Audit Hisoboti

## Jiddiy muammolar (High)
- PC update endpointi noto‘g‘ri relation nomi va string ustunda nullsafe chaqiruv sababli buzilgan. `load(['zone:...'])` mavjud bo‘lmagan relationga ishora qiladi, `$pc->zone?->name` esa `zone` ustuni string bo‘lgani uchun xato beradi. Hammasini `zoneRel` bilan bir xil ishlatish kerak. `app/Http/Controllers/Api/PcController.php:186`, `app/Http/Controllers/Api/PcController.php:188`
- Session start endpointi `Client` importi yo‘qligi sababli yiqiladi. `use App\Models\Client;` qo‘shish kerak. `app/Http/Controllers/Api/SessionController.php:67`
- Mobile PC list `pcs.name` ustuniga tayanadi, lekin sxemada bunday ustun yo‘q. `/api/mobile/pcs` va tegishli javoblarda SQL xatolar bo‘ladi. `code`dan foydalanish yoki ustun qo‘shish kerak. `app/Http/Controllers/Api/MobilePcController.php:21`, `app/Http/Controllers/Api/MobilePcController.php:35`, `app/Http/Controllers/Api/MobilePcController.php:117`, sxema: `database/migrations/2026_02_05_124316_create_pcs_table.php`
- Mobile join‑by‑code `TenantJoinCode` modeliga tayanadi (odatda `tenant_join_codes` jadvali bo‘ladi), lekin migratsiya yo‘q; join kodlar `tenants` jadvalida va alohida `tenant_invites` jadvali mavjud. Bu endpoint ishlamaydi. Bitta manbani tanlab uyg‘unlashtirish kerak. `app/Http/Controllers/Api/MobileClubController.php:22`, `database/migrations/2026_02_11_213131_add_join_code_to_tenants_table.php`, `database/migrations/2026_02_11_210451_create_tenant_invites_table.php`
- Legacy mobile controller `mobile_tokens` jadvaliga `identity_id` yozadi, lekin jadvalda `mobile_user_id` bor. Agar bu controller ishlatilsa, xatolik beradi. `app/Http/Controllers/Mobile/AuthController.php:33`, `database/migrations/2026_02_11_220630_create_mobile_tokens_table.php`
- Bir nechta endpointlar Postgres‑ga xos SQL ishlatadi, `.env.example` esa MySQL deydi. MySQL’da bu yerlar yiqiladi: `ILIKE`, `NULLS LAST`, `EXTRACT(EPOCH...)`, `::time` cast. `app/Http/Controllers/Api/ClientController.php:33`, `app/Http/Controllers/Api/PcController.php:25`, `app/Http/Controllers/Api/PcController.php:40`, `app/Http/Controllers/Api/PromotionController.php:159`, `app/Http/Controllers/Api/ReportController.php:78`, `app/Http/Controllers/Saas/TenantController.php:18`

## O‘rta muammolar (Medium)
- `/api/reports/cash` paket daromadini `package_buy` turi bilan hisoblaydi, ammo paket xaridi `package` turi bilan yozilmoqda. Hisobot noto‘g‘ri bo‘ladi. `app/Http/Controllers/Api/ReportController.php:35`, `app/Http/Controllers/Api/ClientPackageController.php:124`
- Client login boshqa klientning PC’dagi aktiv sessiyasini qaytarishi mumkin; bu sessiya ma’lumotini oshkor qiladi va noto‘g‘ri bog‘lanish keltiradi. Sessiya qaytarishdan oldin klient mosligini tekshirish kerak. `app/Http/Controllers/Api/ClientAuthController.php:143`
- Mobile client summary `client_subscriptions`dan `plan_id` oladi, asl ustun `subscription_plan_id`. Natijada `0` chiqadi. `app/Http/Controllers/Api/MobileClientController.php:103`

## Past muammolar (Low)
- Tier bonus transaction turi `tier_upgrade_bonus`, mobile activity esa `tier_bonus` kutadi — label noto‘g‘ri chiqadi. `app/Services/ClientTierService.php`, `app/Http/Controllers/Api/MobileClientController.php:86`
- `ClientTransaction` modelida `meta` `$fillable`da yo‘q; `ClientTransaction::create()`ga berilgan `meta` saqlanmaydi. Agar kerak bo‘lsa, ustun va `$fillable`ni to‘ldirish kerak. `app/Models/ClientTransaction.php:9`, `app/Http/Controllers/Api/ClientSubscriptionController.php:156`

## Cheklovlar / Farazlar
- Faqat o‘qish rejimida audit; testlar/migratsiyalar ishga tushirilmagan.
- `.env.example` MySQL deb ko‘rsatgani sabab MySQL deb faraz qilindi.

# Loyiha Hujjatlari

## Umumiy ko‘rinish
Laravel 10 asosidagi API: internet‑kafe/klublarni boshqarish (multi‑tenant litsenziya), operator paneli, PC agentlar, klientlar, sessiya/billing, paket/obuna, promo, smena va mobile kirish.

## Texnologiyalar
- PHP 8.1+ , Laravel 10.x
- Sanctum (API tokenlar)
- Vite (front assetlar)
- MySQL ( `.env.example` bo‘yicha )
- Ixtiyoriy: Cassandra extension

## Asosiy tushunchalar
- **Tenant**: klub/filial
- **LicenseKey**: tenantni faollashtiruvchi kalit
- **Operator**: xodim (role: operator/admin/owner)
- **Client**: klient (balance/bonus va sessiya egasi)
- **PC**: ish joyi, status/zone/heartbeat
- **Session**: foydalanish sessiyasi; cron orqali billing
- **Package**: zonaga bog‘langan prepaid minutlar
- **Subscription**: zonaga bog‘langan vaqtli obuna
- **Promotion**: topup bonuslari
- **Shift**: kassa smenasi

## Autentifikatsiya
- **Operator/SaaS**: Sanctum tokenlar (`/api/auth/login`, `/api/saas/auth/login`)
- **Client (PC shell)**: custom token (`/api/client-auth/login`)
- **Agent (PC device)**: `/api/agent/pair` orqali device token, so‘ng `pc.device` middleware
- **Mobile**: `/api/mobile/auth/login` — mobile token; `/api/mobile/auth/switch-club` — club token

## API kirish nuqtalari (qisqa)
- **Public**: `GET /api/ping`, `POST /api/auth/login`, `POST /api/client-auth/login`, `POST /api/agent/pair`, `POST /api/pcs/heartbeat`
- **Operator** (`auth:operator`): `/api/clients`, `/api/pcs`, `/api/sessions`, `/api/shifts`, `/api/promotions`, `/api/zones`, `/api/packages`, `/api/subscription-plans`, `/api/bookings`, `/api/reports/*`, `/api/settings`
- **Client token** (`client.auth`): `/api/client-auth/me`, `/api/mobile/client/summary`, `/api/mobile/pcs/*`
- **Agent** (`pc.device`): `/api/agent/heartbeat`, `/api/agent/commands/poll`, `/api/agent/commands/ack`, `/api/agent/sessions/start`
- **SaaS** (`auth:saas`): `/api/saas/tenants`, `/api/saas/licenses`, `/api/saas/operators`

## Billing oqimi
- Sessiya operator/agent/client orqali boshlanadi.
- `billing:sessions-tick` cron har daqiqada balans/paketdan yechadi va mablag‘ tugasa PCni lock qiladi. `app/Console/Kernel.php`

## Operatsion vazifalar
- Heartbeat: `pcs:heartbeat-check` har daqiqada (offline belgilaydi).
- Booking expiry komandasi bor, ammo schedule’da izohga olingan.

## Konfiguratsiya
- `.env`: `APP_*`, `DB_*`, `SANCTUM`, `CACHE`, `QUEUE`, `SESSION`, `MAIL`
- Guardlar: `config/auth.php` (`operator`, `saas`)

## Lokal ishga tushirish (tavsiya)
1. `composer install`
2. `.env.example` → `.env`, DB va `APP_KEY` sozlash
3. `php artisan key:generate`
4. `php artisan migrate`
5. `npm install` va `npm run dev` (agar Vite kerak bo‘lsa)
6. Cron: `php artisan schedule:run` har daqiqada

## E’tibor talab qiladigan bo‘shliqlar
- DB dialekt nomuvofiqligi (Postgres SQL vs MySQL default)
- Mobile join code manbasi (tenants vs invites)
- Legacy mobile controllerlar sxema bilan mos emas
