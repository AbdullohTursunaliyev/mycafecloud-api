# MyCafeCloud Competitive Comparison

Date: 2026-02-18
Goal: MyCafeCloud funksionalini iCafeCloud, SmartShell va kuchli alternativalar bilan solishtirish.

## 1) MyCafeCloud hozirgi funksional (koddan)

Source: `routes/api.php`.

- Multi-tenant SaaS: tenant, license, operator boshqaruvi.
- CP auth: license resolve, login/logout, role-based access.
- Clients: create/list, topup, bulk topup, history, sessions, transfer, returns.
- PCs: create/update/delete, layout batch update.
- Layout: grid update, cell batch update.
- Sessions: active, start, stop.
- Shifts: open/close/current, summary, report, expenses, history.
- Reports: overview, branch compare (+ admin/owner reports).
- Promotions, zones, packages, subscription plans, client package/subscription.
- Bookings: list/create/cancel.
- PC commands: operator -> PC command send.
- Logs, Settings.
- Mobile: auth, club join, PC list, booking/unbooking, QR open.

## 2) iCafeCloud vs MyCafeCloud

### Bizda bor
- SaaS tenancy + license model.
- Branch compare analytics.
- Returns + transfers + packages + subscriptions in one flow.
- Mobile booking + QR open API.

### iCafeCloud da bor
- Cafe billing + game management stack.
- Centralized game updates and licensing workflows.
- Promotions/bonus logic for recharge and play-time.
- Earnings/expenses/member reporting.
- PC/consoles operation tied to session lifecycle.

### Bizda yoq yoki qisman (iCafeCloud ga nisbatan)
- Full productized game patch/update and game license distribution layer.
- Deep game-economy/prize workflows.
- Console lifecycle flows rich operator UX darajasida qisman.

## 3) SmartShell vs MyCafeCloud

### Bizda bor
- Multi-tenant SaaS control (tenant/license/operator).
- Strong finance workflows: transfers, returns, shift reports.
- Flexible monetization blocks: promotions + packages + subscriptions.

### SmartShell da bor
- Loyalty ecosystem and SmartGamer mobile flow.
- Open API documentation and partner integration approach.
- Rich monitoring/operations layer (interactive map, equipment monitoring, tasks).
- Security controls (USB/window/process policy style controls).

### Bizda yoq yoki qisman (SmartShell ga nisbatan)
- Staff task-manager and standard ops playbooks.
- Productized hardware/health telemetry dashboards (disk/alerts deeper level).
- Full hardening policy center (granular restrictions).
- Public growth funnel/mobile-native conversion workflows.

## 4) Kuchli alternativalar bilan taqqoslash

Benchmarks: SENET, ggLeap, Smartlaunch.

### SENET
- Ularda bor: cloud PC+console control, stronger operations tooling, API/integration positioning, booking economics patterns.
- Bizda yoq/qisman: public integration hub, diskless/game-distribution level productization.

### ggLeap
- Ularda bor: booking ecosystem depth, event/loyalty framing, payment integrations narrative.
- Bizda yoq/qisman: event/leaderboard/prize-driven product blocks, public booking funnel depth.

### Smartlaunch
- Ularda bor: mature POS/inventory/order workflows, broader infra modules and security controls.
- Bizda yoq/qisman: full POS+inventory stack, advanced service modules (ticket/credit style and similar options).

## 5) Consolidated gap list (eng muhim)

P0 gaps (eng tez yopish kerak):
1. POS + inventory + product order flow.
2. Advanced security policy center (USB/process/window/download restrictions).
3. Hardware telemetry and proactive alerting layer.
4. Booking economics: deposit/no-show/waitlist.

P1 gaps:
1. Public booking funnel + payment link flow.
2. Gamification layer (leaderboard/reward/event mechanics).
3. Integration hub (API/webhook docs + ready connectors).

## 6) Xulosa

- MyCafeCloud core operations juda kuchli: billing/session/client/shift/booking/report stack bor.
- Raqobatchilardan otish uchun asosiy farq nuqtasi: POS ecosystem, security hardening, telemetry, integration ecosystem.
- Shu 4 yo'nalish productized qilinsa, SmartShell/iCafeCloud bilan paritetdan otib ketish imkoniyati yuqori.

## 7) Sources

iCafeCloud:
- https://www.icafecloud.com/

SmartShell:
- https://smartshell.gg/en/features
- https://smartshell.gg/en/pricing
- https://smartshell.gg/en/api-docs
- https://support.smartshell.gg/smartshell-for-owners/

SENET:
- https://www.senet.cloud/features
- https://www.senet.cloud/pricing
- https://www.senet.cloud/faq

ggLeap (ggCircuit):
- https://help.ggcircuit.com/en/collections/1923077-ggleap
- https://help.ggcircuit.com/en/articles/4405396-how-do-bookings-in-ggleap-work
- https://www.ggcircuit.com/blog/integrating-square-and-stripe-with-ggleap2

Smartlaunch:
- https://smartlaunch.com/Features
- https://smartlaunch.com/Pricing
- https://smartlaunch.com/faq

Note:
- Competitor capability statements are based on publicly available product/marketing/docs pages.
- "Bizda yoq yoki qisman" punktlari kod bazadagi mavjud API scope bilan solishtirib inferensiya qilingan.
