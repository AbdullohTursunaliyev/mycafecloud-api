# MyCafeCloud Billing Engine Arxitekturasi

Bu hujjat MyCafeCloud uchun shell, session va billing oqimini to'g'ri yo'nalishda olib borish uchun mo'ljallangan.

Asosiy qaror:

- hozircha `Laravel` authoritative billing engine bo'lib qoladi
- `C# shell` UI va command executor bo'lib qoladi
- billing qoidasi bitta markaziy engine orqali yuradi
- `state`, `heartbeat`, `poll` kabi endpointlar read-only bo'ladi

Bu yo'l `iCafeCloud` va `SmartShell`dagi amaliy modelga yaqin:

- shell faqat ko'rsatadi yoki buyruq bajaradi
- haqiqiy time/price calculation bitta joyda yuradi
- rounding, pause, tariff window, package, wallet bir xil engine orqali hisoblanadi

## 1. Muammo qayerda edi

Oldingi yondashuvda quyidagi xavf bor edi:

- billingning ayrim qismi read endpointlarda ham ishlardi
- countdown va haqiqiy charge turli formulalardan chiqardi
- `tick` va `logout` turlicha rounding ishlatardi
- shellga yaqin flow bilan billing domeni aralashib ketardi

Bu holat foydalanuvchida "vaqt boshqa, pul boshqa" degan hissiyot qoldiradi.

## 2. To'g'ri yo'nalish

To'g'ri target bu:

- `single source of truth`
- `single billing rule`
- `single countdown rule`
- `command/query separation`

Qisqa ko'rinish:

```text
C# Shell
  -> state / me / public settings / commands
  -> faqat ko'rsatadi va buyruq bajaradi

Laravel API
  -> session lifecycle
  -> billing engine
  -> ledger/log
  -> reports/admin

Scheduler
  -> periodic billing tick
  -> expire/pause/reconcile flows
```

## 3. Maqsadli qatlamlar

### 3.1. Session lifecycle qatlami

Bu qatlam sessiya hayot siklini boshqaradi:

- `start`
- `stop`
- `logout`
- `pause`
- `resume`
- `expire/finalize`

Tavsiya etilgan classlar:

- `SessionStartService`
- `SessionStopService`
- `SessionPauseService`
- `SessionResumeService`
- `SessionFinalizeService`

Qoidalar:

- sessiya ochish va yopish faqat write endpoint yoki scheduler orqali bo'ladi
- read endpointlar sessiyani o'zgartirmaydi
- billing finalize faqat explicit write flow yoki schedulerda yuradi

### 3.2. Metering qatlami

Bu qatlam vaqtni billable unitga aylantiradi.

Hozir repo ichida birinchi asos bor:

- [SessionMeteringService](/Users/user/Desktop/GitHub/mycafecloud-api/app/Services/SessionMeteringService.php)

Bu qatlamning vazifasi:

- `price per minute` yoki keyinchalik `price per second` ni hisoblash
- `completed billable units` ni topish
- `countdown seconds` ni qaytarish
- `billing anchor` va `next anchor` ni hisoblash

Muhim qoida:

- bitta rounding modeli bo'ladi
- `tick`, `logout`, `stop`, `active state projection` bir xil metering qoidaga tayanadi

### 3.3. Price rule qatlami

Bu qatlam qaysi tarif qachon qo'llanishini hal qiladi.

Tavsiya etilgan class:

- `PricingRuleResolver`

Vazifalar:

- zone tariff
- promo tariff
- package/subscription override
- time window tariff
- day-part pricing
- holiday/special price

Kirish:

- tenant
- client
- pc
- zone
- timestamp
- active package/subscription

Chiqish:

- `pricing rule snapshot`
- `unit price`
- `rule type`
- `rule id`
- `billing strategy`

Bu juda muhim, chunki `iCafeCloud` uslubida sessiya ichida narx oynaga qarab almashishi mumkin.

### 3.4. Billing ledger qatlami

Haqiqiy charge faqat jamlangan `session.price_total` bilan cheklanib qolmasligi kerak.

Tavsiya etilgan table:

- `session_charge_events`

Minimal ustunlar:

- `id`
- `tenant_id`
- `session_id`
- `client_id`
- `pc_id`
- `zone_id`
- `source_type` (`wallet`, `package`, `subscription`, `mixed`)
- `rule_type`
- `rule_id`
- `period_started_at`
- `period_ended_at`
- `billable_units`
- `unit_kind` (`minute`, keyin `second` bo'lishi mumkin)
- `unit_price`
- `amount`
- `wallet_before`
- `wallet_after`
- `package_before_min`
- `package_after_min`
- `meta` (json)
- `created_at`

Bu jadvalning foydasi:

- nega qancha pul yechilganini ko'rsatadi
- support/debug ancha osonlashadi
- report va refund aniqroq bo'ladi
- future Go extraction paytida contract aniq bo'ladi

### 3.5. Projection qatlami

UI ga ko'rsatiladigan state charge engine'ning aks-sadosi bo'lishi kerak.

Tavsiya etilgan class:

- `SessionProjectionService`

Vazifalar:

- `seconds_left`
- `display price`
- `tariff label`
- `billing mode`
- `next charge at`
- `wallet/package state`

Qoidalar:

- bu qatlam DB yozmaydi
- read-only
- metering va pricing resolver natijasidan foydalanadi

## 4. Command va Query chegarasi

Quyidagi endpointlar write bo'lishi kerak:

- `POST start session`
- `POST stop session`
- `POST logout`
- `POST pause`
- `POST resume`
- scheduler `billing:sessions-tick`

Quyidagi endpointlar read-only bo'lishi kerak:

- `GET/POST session state`
- `GET me`
- `GET active sessions`
- `GET public settings`
- `heartbeat`
- `command poll`

Asosiy qoida:

- `read endpoint hech qachon balance, package yoki session price ni o'zgartirmaydi`

## 5. Hozirgi repo uchun tavsiya etilgan class xarita

Quyidagi classlar allaqachon to'g'ri yo'lga kirgan:

- [SessionBillingService](/Users/user/Desktop/GitHub/mycafecloud-api/app/Services/SessionBillingService.php)
- [SessionMeteringService](/Users/user/Desktop/GitHub/mycafecloud-api/app/Services/SessionMeteringService.php)
- [ClientSessionService](/Users/user/Desktop/GitHub/mycafecloud-api/app/Services/ClientSessionService.php)
- [LegacyShellService](/Users/user/Desktop/GitHub/mycafecloud-api/app/Services/LegacyShellService.php)
- [SessionStartService](/Users/user/Desktop/GitHub/mycafecloud-api/app/Services/SessionStartService.php)

Keyingi to'g'ri parchalanish:

1. `SessionBillingService`
   uni quyidagilarga bo'lish:
   - `PricingRuleResolver`
   - `BillingLedgerService`
   - `SessionFinalizeService`
   - `SessionChargeService`

2. `ClientSessionService`
   uni quyidagilarga bo'lish:
   - `SessionProjectionService`
   - `SessionCountdownService`

3. `LegacyShellService`
   uni faqat adapter sifatida qoldirish:
   - request parsing
   - shell-specific payload mapping
   - domain logic yo'q

## 6. Billing qoidasi

Hozirgi tavsiya etilgan amaliy model:

- authoritative unit: `minute`
- billing timestamp: `exact timestamp`
- rounding: `completed minute`
- countdown: shu modelning projection'i

Misol:

- sessiya `10:00:10` da boshlandi
- tariff `10000 / hour`
- `price_per_minute = ceil(10000 / 60) = 167`

Holatlar:

- `10:00:50` dagi state:
  - charge `0`
  - countdown `90 sec` agar balans `500` bo'lsa
- `10:01:11` dagi logout:
  - 1 minut charge
  - `167` yechiladi
- `10:02:12` dagi tick:
  - 2 minutgacha yopiladi

Muhim:

- state oldin charge qilmaydi
- logout `ceil` bilan ortiqcha charge qilmaydi
- `second(0)` bilan vaqt kesilmaydi

## 7. Package, wallet va subscription ustuvorligi

Tavsiya etilgan aniq qoida:

1. `active package` bo'lsa va zone mos bo'lsa, avval package ishlatiladi
2. package tugasa va `allow wallet fallback` yoqilgan bo'lsa, walletga o'tiladi
3. `subscription` bo'lsa, rule resolver uni avval baholaydi
4. har switch alohida `session_charge_event` bilan log qilinadi

Bu rule code ichida yoyilib ketmasligi kerak.
Faqat `PricingRuleResolver` va charge service ichida bo'lishi kerak.

## 8. Pause va resume semantics

Tavsiya:

- pause vaqtida billing to'xtaydi
- resume bo'lganda yangi billable anchor qayta hisoblanadi
- pause davri ledger yoki meta ichida log qilinadi

Bu `iCafeCloud`dagi "pause pushes forward the session clock" modeliga yaqinroq.

## 9. Dynamic tariff windows

Agar keyinroq kuchli billing xohlasangiz, aynan shu qatlamni kengaytirasiz.

Masalan:

- `08:00-18:00` bir narx
- `18:00-00:00` boshqa narx
- tun bo'yi promo narx

Sessiya bir necha bo'lakka bo'linadi:

- `18:40-19:00`
- `19:00-20:00`
- ...

Har bo'lak alohida `session_charge_event` bo'ladi.

Bu yondashuv keyingi bosqichda iCafeCloud'ga yaqin professional billing beradi.

## 10. DB darajadagi invariantlar

Quyidagilar DB bilan himoyalangan bo'lishi kerak:

- bitta PC uchun bitta active session
- bitta tenant uchun bitta open shift
- bitta client/zone uchun bitta active subscription

Qo'shimcha tavsiya:

- `session_charge_events` uchun `idempotency key`
- `finalize` operatsiyasi uchun transaction
- scheduler tick uchun lock yoki safe repeatable design

## 11. Go qachon kerak bo'ladi

`Go` quyidagi holatda to'g'ri qaror bo'ladi:

- offline billing kerak bo'lsa
- internet uzilganda ham sessiya authoritative yurishi kerak bo'lsa
- PC yonida local daemon kerak bo'lsa
- shell local machine state bilan juda chuqur ishlasa

`Go` hozircha kerak emas, agar:

- siz authoritative rule'ni hali formal qilmagan bo'lsangiz
- bug'lar formula va flowdan kelayotgan bo'lsa
- UI rewrite qilmoqchi bo'lmasangiz

To'g'ri ketma-ketlik:

1. Laravel ichida billing engine'ni formal qilish
2. ledger va projection ni to'g'ri qilish
3. test bilan yopish
4. shundan keyin xohlasangiz shu engine'ni `Go runtime service`ga ko'chirish

## 12. Bosqichma-bosqich migratsiya rejasi

### Phase 1. Stabilize

- `state` va `heartbeat`ni read-only qilish
- `SessionMeteringService` orqali countdown va billingni birlashtirish
- exact timestamp bilan ishlash
- tick/logout/start qoidalarini bir xil qilish

### Phase 2. Ledger

- `session_charge_events` migration qo'shish
- har charge eventni yozish
- refund/debug/report shu ledgerdan foydalanishi

### Phase 3. Rule engine

- `PricingRuleResolver` qo'shish
- package/subscription/wallet switch qoidalarini markazlashtirish
- tariff window support qo'shish

### Phase 4. Projection

- `SessionProjectionService`
- `next_charge_at`
- `seconds_left`
- `display mode`
- shell va operator panel uchun bir xil payload

### Phase 5. Optional Go runtime

- faqat kerak bo'lsa
- Laravel control-plane bo'lib qoladi
- Go local runtime authoritative timekeeper bo'ladi

## 13. Definition of Done

Arxitektura to'g'ri ishlayapti deb hisoblash uchun:

- read endpointlar hech narsa yozmaydi
- countdown va charge bir xil modeldan chiqadi
- logout va tick bir xil rounding ishlatadi
- har charge explainable ledger eventga ega
- package/wallet/subscription switch loglanadi
- pause/resume semantics aniq
- dynamic tariff qo'llab-quvvatlashga yo'l ochiq
- feature testlarda real vaqt edge-case lar yopilgan

## 14. Hozirgi amaliy tavsiya

Sizning hozirgi eng to'g'ri yo'lingiz:

1. Laravel ichida billing engine'ni kuchaytirish
2. `session_charge_events` ni qo'shish
3. `PricingRuleResolver` ni chiqarish
4. `SessionProjectionService` ni ajratish
5. shundan keyin kerak bo'lsa `Go` sidecar/runtime haqida qaror qilish

Qisqa xulosa:

- siz noto'g'ri yo'lda emassiz
- faqat `Laravel vs Go` emas, `authoritative billing engine` deb o'ylash kerak
- to'g'ri arxitektura hozircha Laravel ichida formal billing engine qurishdan boshlanadi
