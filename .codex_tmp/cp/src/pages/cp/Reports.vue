<template>
  <div class="reports-page fintech">
    <section class="hero glass">
      <div class="hero-left">
        <p class="eyebrow">Owner Analytics</p>
        <h1 class="title">Hisobotlar markazi</h1>
        <p class="subtitle">Tushum, sessiya, to'lov oqimi va risklar. Hammasi aniq va bir joyda.</p>
        <div v-if="overview" class="hero-meta">
          <span class="pill">Davr: {{ period.from }} - {{ period.to }}</span>
          <span class="pill subtle">Oldingi davr: {{ period.previous_from || '-' }} - {{ period.previous_to || '-' }}</span>
        </div>
      </div>

      <div class="hero-right">
        <label class="field">
          <span>Dan</span>
          <input v-model="filters.from" type="date" class="input" />
        </label>
        <label class="field">
          <span>Gacha</span>
          <input v-model="filters.to" type="date" class="input" />
        </label>
        <button class="btn primary" :disabled="loadingOverview" @click="refreshAll">
          {{ loadingOverview ? 'Yuklanmoqda...' : 'Yangilash' }}
        </button>
      </div>
    </section>

    <div v-if="overviewError" class="alert danger">{{ overviewError }}</div>

    <section class="tab-strip glass">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="tab-btn"
        :class="{ active: activeTab === tab.key }"
        @click="activeTab = tab.key"
      >
        <span class="tab-icon" v-html="tabIcons[tab.key]"></span>
        <span class="tab-label">{{ tab.label }}</span>
      </button>
    </section>

    <template v-if="overview">
      <div v-if="activeTab === 'overview'" class="section-head">
        <span>Snapshot</span>
        <b>Davr bo‘yicha asosiy ko‘rsatkichlar</b>
      </div>
      <section v-if="activeTab === 'overview'" class="panel glass growth-panel">
        <div class="panel-head">
          <div>
            <h3>Oldingi davr bilan taqqoslash</h3>
            <p>{{ periodLabel }}</p>
          </div>
        </div>
        <div class="growth-grid" v-if="growthRows.length">
          <div class="growth-item" v-for="g in growthRows" :key="g.key">
            <span>{{ g.label }}</span>
            <strong>{{ valueWithUnit(g.current, g.unit) }}</strong>
            <em :class="g.trend">{{ diffWithUnit(g.diff, g.unit) }}<template v-if="g.diff_pct !== null"> ({{ signed(g.diff_pct) }}%)</template></em>
          </div>
        </div>
      </section>

      <div v-if="activeTab === 'overview'" class="section-head">
        <span>Cash Integrity</span>
        <b>Hisob-kitoblarning to‘liq oqimi</b>
      </div>
      <section v-if="activeTab === 'overview'" class="ledger-grid">
        <article class="ledger-card">
          <h4>Hisob-kitoblar to‘liq ko‘rinadi</h4>
          <p>Gross → Returns → Expenses → Net oqimi</p>
          <div class="ledger-items">
            <div><span>Jami savdo</span><b>{{ money(summary.gross_sales) }} UZS</b></div>
            <div><span>Qaytarishlar</span><b>{{ money(summary.returns_total) }} UZS</b></div>
            <div><span>Xarajatlar</span><b>{{ money(summary.expenses_total) }} UZS</b></div>
            <div><span>Sof tushum</span><b>{{ money(summary.net_sales) }} UZS</b></div>
          </div>
        </article>
        <article class="ledger-card">
          <h4>Naqd / Karta balans</h4>
          <p>Net oqimlar va farqlar</p>
          <div class="ledger-items">
            <div><span>Naqd net</span><b>{{ money(payments.cash_net) }} UZS</b></div>
            <div><span>Karta net</span><b>{{ money(payments.card_net) }} UZS</b></div>
            <div><span>Kamomad</span><b>{{ money(shiftsMeta.diff_shortage_total) }} UZS</b></div>
            <div><span>Ortiqcha</span><b>{{ money(shiftsMeta.diff_overage_total) }} UZS</b></div>
          </div>
        </article>
      </section>

      <div v-if="activeTab === 'overview'" class="section-head">
        <span>Risk & Control</span>
        <b>Kamomad, ortiqcha va risk signallari</b>
      </div>
      <section v-if="activeTab === 'overview'" class="signal-grid">
        <article class="signal-card">
          <span>Qaytarishlar</span>
          <strong>{{ money(summary.returns_total) }} UZS</strong>
          <em>Jami qaytarilgan summa</em>
        </article>
        <article class="signal-card">
          <span>Xarajatlar</span>
          <strong>{{ money(summary.expenses_total) }} UZS</strong>
          <em>Davrdagi operatsion xarajatlar</em>
        </article>
        <article class="signal-card">
          <span>Kamomad</span>
          <strong>{{ money(shiftsMeta.diff_shortage_total) }} UZS</strong>
          <em>Smenalar bo'yicha jam</em>
        </article>
        <article class="signal-card">
          <span>Ortiqcha</span>
          <strong>{{ money(shiftsMeta.diff_overage_total) }} UZS</strong>
          <em>Smenalar bo'yicha jam</em>
        </article>
        <article class="signal-card">
          <span>Operatsiyalar</span>
          <strong>{{ int(summary.tx_count) }}</strong>
          <em>Davrdagi transaction soni</em>
        </article>
        <article class="signal-card">
          <span>Mijozlar</span>
          <strong>{{ int(activity.clients_in_period) }}</strong>
          <em>Davrdagi aktiv mijozlar</em>
        </article>
      </section>

      <div v-if="activeTab === 'overview'" class="section-head">
        <span>Flow & Mix</span>
        <b>Kunlik oqimlar, kanal ulushi va cash oqimi</b>
      </div>
      <section v-if="activeTab === 'overview'" class="overview-dashboard">
        <article class="panel glass flow-compact">
          <div class="panel-head">
            <div>
              <h3>Cash oqimi</h3>
              <p>Gross → Returns → Expenses → Net</p>
            </div>
          </div>
          <div class="flow-rows">
            <div><span>Jami savdo</span><b>{{ money(summary.gross_sales) }} UZS</b></div>
            <div><span>Qaytarishlar</span><b>{{ money(summary.returns_total) }} UZS</b></div>
            <div><span>Xarajatlar</span><b>{{ money(summary.expenses_total) }} UZS</b></div>
            <div class="flow-net"><span>Sof tushum</span><b>{{ money(summary.net_sales) }} UZS</b></div>
          </div>
        </article>

        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Kunlik trend</h3>
              <p>Savdo oqimi: Jami va Sof tushum</p>
            </div>
            <div class="legend">
              <span><i class="dot gross"></i> Jami</span>
              <span><i class="dot net"></i> Sof</span>
            </div>
          </div>

          <div class="chart-wrap" v-if="daily.length">
            <svg viewBox="0 0 760 260" class="line-chart" preserveAspectRatio="none">
              <g v-for="(tick, idx) in yTicks" :key="'tick-' + idx">
                <line
                  :x1="chartPad"
                  :y1="yToPoint(tick)"
                  :x2="chartWidth - chartPad"
                  :y2="yToPoint(tick)"
                  class="grid-line"
                />
              </g>

              <path :d="areaPath(netValues)" class="net-area" />
              <path :d="linePath(grossValues)" class="gross-line" />
              <path :d="linePath(netValues)" class="net-line" />
            </svg>

            <div class="x-labels">
              <span v-for="(d, idx) in xLabels" :key="'xl-' + idx">{{ d }}</span>
            </div>
          </div>

          <div v-else class="empty">Tanlangan davrda chart uchun ma'lumot topilmadi.</div>
        </article>

        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>To'lov kanallari</h3>
              <p>Qaysi kanal ko'proq ishlaganini ko'rsatadi</p>
            </div>
          </div>

          <div class="donut-wrap">
            <div class="donut" :style="donutStyle">
              <div class="donut-center">
                <p>{{ money(paymentTotal) }}</p>
                <span>UZS</span>
              </div>
            </div>

            <div class="donut-legend">
              <div class="lg-row">
                <span class="lg-title"><i class="dot cash"></i> Naqd</span>
                <strong>{{ money(payments.cash_sales_total) }}</strong>
                <em>{{ pct(payments.cash_sales_total, paymentTotal) }}%</em>
              </div>
              <div class="lg-row">
                <span class="lg-title"><i class="dot card"></i> Karta</span>
                <strong>{{ money(payments.card_sales_total) }}</strong>
                <em>{{ pct(payments.card_sales_total, paymentTotal) }}%</em>
              </div>
              <div class="lg-row">
                <span class="lg-title"><i class="dot balance"></i> Balans</span>
                <strong>{{ money(payments.balance_sales_total) }}</strong>
                <em>{{ pct(payments.balance_sales_total, paymentTotal) }}%</em>
              </div>
            </div>
          </div>
        </article>
      </section>

      <section v-if="activeTab === 'sales'" class="detail-grid">
        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Savdo tarkibi</h3>
              <p>Qaysi mahsulot qancha ulush olgan</p>
            </div>
          </div>

          <div class="mix-list">
            <div class="mix-item" v-for="item in salesMixRows" :key="item.key">
              <div class="mix-top">
                <span>{{ item.label }}</span>
                <strong>{{ money(item.value) }} UZS</strong>
              </div>
              <div class="mix-bar">
                <div class="mix-fill" :style="{ width: item.pct + '%', background: item.color }"></div>
              </div>
              <small>{{ item.pct }}%</small>
            </div>
          </div>
        </article>

        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Top mijozlar</h3>
              <p>Sessiyadagi xarajat bo'yicha TOP 10</p>
            </div>
          </div>

          <div v-if="topClients.length" class="top-clients">
            <div class="client-row" v-for="c in topClients" :key="c.client_id">
              <div class="client-meta">
                <strong>{{ c.login || c.account_id || c.phone || ('#' + c.client_id) }}</strong>
                <span>{{ int(c.sessions_count) }} sessiya</span>
              </div>
              <div class="client-bar">
                <div
                  class="client-fill"
                  :style="{ width: topClientMax > 0 ? Math.round((int(c.spent) / topClientMax) * 100) + '%' : '0%' }"
                ></div>
              </div>
              <b class="client-money">{{ money(c.spent) }}</b>
            </div>
          </div>
          <div v-else class="empty">Bu davrda top mijozlar yo'q.</div>
        </article>
      </section>

      <section v-if="activeTab === 'insights'" class="ops-grid">
        <article class="panel glass compact">
          <h3>Faollik</h3>
          <div class="kv">
            <span>PC jami</span><b>{{ int(activity.pcs_total) }}</b>
          </div>
          <div class="kv">
            <span>PC online</span><b>{{ int(activity.pcs_online) }}</b>
          </div>
          <div class="kv">
            <span>Mijozlar jami</span><b>{{ int(activity.clients_total) }}</b>
          </div>
          <div class="kv">
            <span>Davrdagi mijozlar</span><b>{{ int(activity.clients_in_period) }}</b>
          </div>
        </article>

        <article class="panel glass compact">
          <h3>Tozalashlar</h3>
          <div class="kv">
            <span>Qaytarishlar</span><b>{{ money(summary.returns_total) }} UZS</b>
          </div>
          <div class="kv">
            <span>Xarajatlar</span><b>{{ money(summary.expenses_total) }} UZS</b>
          </div>
          <div class="kv">
            <span>Naqd net</span><b>{{ money(payments.cash_net) }} UZS</b>
          </div>
          <div class="kv">
            <span>Karta net</span><b>{{ money(payments.card_net) }} UZS</b>
          </div>
        </article>

        <article class="panel glass compact">
          <h3>Operatsiyalar</h3>
          <div class="kv">
            <span>Topup soni</span><b>{{ int(sales.topup_count) }}</b>
          </div>
          <div class="kv">
            <span>Paket soni</span><b>{{ int(sales.package_count) }}</b>
          </div>
          <div class="kv">
            <span>Obuna soni</span><b>{{ int(sales.subscription_count) }}</b>
          </div>
          <div class="kv">
            <span>Umumiy tx</span><b>{{ int(summary.tx_count) }}</b>
          </div>
        </article>
      </section>

      <section v-if="activeTab === 'operations'" class="split-grid">
        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Soatlik yuklama</h3>
              <p>Qaysi soatda sessiya ko'proq bo'lgan</p>
            </div>
          </div>
          <div class="mini-bars" v-if="hourly.length">
            <div class="mini-bar-item" v-for="h in hourly" :key="h.hour">
              <div class="mini-label">{{ h.label }}</div>
              <div class="mini-track">
                <div class="mini-fill" :style="{ width: hourlyMax > 0 ? Math.round((h.sessions_count / hourlyMax) * 100) + '%' : '0%' }"></div>
              </div>
              <div class="mini-val">{{ h.sessions_count }}</div>
            </div>
          </div>
          <div v-else class="empty">Soatlik ma'lumot yo'q.</div>
        </article>

        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Haftalik taqsimot</h3>
              <p>Qaysi kunda yuklama yuqori</p>
            </div>
          </div>
          <div class="mini-bars" v-if="weekday.length">
            <div class="mini-bar-item" v-for="d in weekday" :key="d.weekday_no">
              <div class="mini-label">{{ d.label }}</div>
              <div class="mini-track">
                <div class="mini-fill alt" :style="{ width: weekdayMax > 0 ? Math.round((d.sessions_count / weekdayMax) * 100) + '%' : '0%' }"></div>
              </div>
              <div class="mini-val">{{ d.sessions_count }}</div>
            </div>
          </div>
          <div v-else class="empty">Haftalik ma'lumot yo'q.</div>
        </article>
      </section>

      <section v-if="activeTab === 'operations'" class="table-grid">
        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Zonalar bo'yicha</h3>
              <p>Sessiya, soat va tushum</p>
            </div>
          </div>
          <div class="table-wrap" v-if="zonesItems.length">
            <div class="thead trow cols-zone">
              <span>Zona</span><span>Sessiya</span><span>Soat</span><span>Tushum</span>
            </div>
            <div class="trow cols-zone" v-for="z in zonesItems" :key="z.zone">
              <span>{{ z.zone }}</span>
              <span>{{ int(z.sessions_count) }}</span>
              <span>{{ num(int(z.minutes) / 60, 1) }}</span>
              <span>{{ money(z.revenue) }}</span>
            </div>
          </div>
          <div v-else class="empty">Zona ma'lumotlari yo'q.</div>
        </article>

        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Operatorlar samaradorligi</h3>
              <p>Tx, sessiya va savdo kesimi</p>
            </div>
          </div>
          <div class="table-wrap" v-if="operatorItems.length">
            <div class="thead trow cols-op">
              <span>Operator</span><span>Tx</span><span>Sessiya</span><span>Savdo</span>
            </div>
            <div class="trow cols-op" v-for="o in operatorItems" :key="o.operator_id">
              <span>{{ o.operator }}</span>
              <span>{{ int(o.tx_count) }}</span>
              <span>{{ int(o.sessions_count) }}</span>
              <span>{{ money(o.sales_amount) }}</span>
            </div>
          </div>
          <div v-else class="empty">Operator ma'lumotlari yo'q.</div>
        </article>
      </section>

      <section v-if="activeTab === 'operations'" class="table-grid">
        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Top PC</h3>
              <p>Eng ko'p tushum qilgan kompyuterlar</p>
            </div>
          </div>
          <div class="table-wrap" v-if="pcsTop.length">
            <div class="thead trow cols-pc">
              <span>PC</span><span>Zona</span><span>Sessiya</span><span>Tushum</span>
            </div>
            <div class="trow cols-pc" v-for="p in pcsTop" :key="'tp-' + p.pc_id">
              <span>{{ p.pc_code }}</span>
              <span>{{ p.zone }}</span>
              <span>{{ int(p.sessions_count) }}</span>
              <span>{{ money(p.revenue) }}</span>
            </div>
          </div>
          <div v-else class="empty">PC ma'lumotlari yo'q.</div>
        </article>

        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Kam ishlatilgan PC</h3>
              <p>Yuklamasi past kompyuterlar</p>
            </div>
          </div>
          <div class="table-wrap" v-if="pcsUnderused.length">
            <div class="thead trow cols-pc">
              <span>PC</span><span>Zona</span><span>Sessiya</span><span>Tushum</span>
            </div>
            <div class="trow cols-pc" v-for="p in pcsUnderused" :key="'up-' + p.pc_id">
              <span>{{ p.pc_code }}</span>
              <span>{{ p.zone }}</span>
              <span>{{ int(p.sessions_count) }}</span>
              <span>{{ money(p.revenue) }}</span>
            </div>
          </div>
          <div v-else class="empty">PC ma'lumotlari yo'q.</div>
        </article>
      </section>

      <section v-if="activeTab === 'operations'" class="table-grid">
        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Qaytarishlar tahlili</h3>
              <p>Type bo'yicha taqsimot</p>
            </div>
          </div>
          <div class="table-wrap" v-if="returnItems.length">
            <div class="thead trow cols-small">
              <span>Type</span><span>Soni</span><span>Summasi</span>
            </div>
            <div class="trow cols-small" v-for="r in returnItems" :key="r.type">
              <span>{{ r.type }}</span>
              <span>{{ int(r.returns_count) }}</span>
              <span>{{ money(r.amount_total) }}</span>
            </div>
          </div>
          <div v-else class="empty">Qaytarish ma'lumotlari yo'q.</div>
        </article>

        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Transaksiya turi</h3>
              <p>Operatsiyalar soni va summasi</p>
            </div>
          </div>
          <div class="table-wrap" v-if="transactionItems.length">
            <div class="thead trow cols-small">
              <span>Type</span><span>Soni</span><span>Summasi</span>
            </div>
            <div class="trow cols-small" v-for="t in transactionItems" :key="t.type">
              <span>{{ t.type }}</span>
              <span>{{ int(t.tx_count) }}</span>
              <span>{{ money(t.amount_total) }}</span>
            </div>
          </div>
          <div v-else class="empty">Transaction ma'lumotlari yo'q.</div>
        </article>
      </section>

      <section v-if="activeTab === 'operations'" class="panel glass">
        <div class="panel-head">
          <div>
            <h3>Shift metrikalari</h3>
            <p>Tanlangan davr bo'yicha smena kesimi</p>
          </div>
        </div>
        <div class="shift-grid">
          <div class="kv"><span>Smena soni</span><b>{{ int(shiftsMeta.shifts_count) }}</b></div>
          <div class="kv"><span>Ochiq smena</span><b>{{ int(shiftsMeta.open_shifts) }}</b></div>
          <div class="kv"><span>Ochilish naqd</span><b>{{ money(shiftsMeta.opening_cash_total) }} UZS</b></div>
          <div class="kv"><span>Yopilish naqd</span><b>{{ money(shiftsMeta.closing_cash_total) }} UZS</b></div>
          <div class="kv"><span>Ortiqcha</span><b>{{ money(shiftsMeta.diff_overage_total) }} UZS</b></div>
          <div class="kv"><span>Kamomad</span><b>{{ money(shiftsMeta.diff_shortage_total) }} UZS</b></div>
        </div>
      </section>

      <section v-if="activeTab === 'insights'" class="insight-grid">
        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Forecast va reja</h3>
              <p>1 kunlik o'rtacha net tushumdan kelib chiqqan yillik prognoz</p>
            </div>
          </div>
          <div class="forecast-cards">
            <div class="forecast-card avg">
              <span>Yillik (o'rtacha)</span>
              <strong>{{ money(projection.yearly_from_average) }} UZS</strong>
              <em>Kunlik o'rtacha: {{ money(projection.daily_average_net) }} UZS</em>
            </div>
            <div class="forecast-card min">
              <span>Yillik (minimal kun)</span>
              <strong>{{ money(projection.yearly_from_min) }} UZS</strong>
              <em>Kunlik min: {{ money(projection.daily_min_net) }} UZS</em>
            </div>
            <div class="forecast-card max">
              <span>Yillik (maksimal kun)</span>
              <strong>{{ money(projection.yearly_from_max) }} UZS</strong>
              <em>Kunlik max: {{ money(projection.daily_max_net) }} UZS</em>
            </div>
          </div>
        </article>

        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Asosiy insightlar</h3>
              <p>Egaga tez qaror uchun eng muhim raqamlar</p>
            </div>
          </div>
          <div class="insight-kv">
            <div class="kv">
              <span>1 smenadagi o'rtacha tushum</span>
              <b>{{ money(summary.avg_revenue_per_shift) }} UZS</b>
            </div>
            <div class="kv">
              <span>O'rtacha topup cheki</span>
              <b>{{ money(clientsMeta.avg_topup_check) }} UZS</b>
            </div>
            <div class="kv">
              <span>Eng bonusli kun</span>
              <b>{{ peakBonusLabel }}</b>
            </div>
            <div class="kv">
              <span>Peak savdo kuni</span>
              <b>{{ peakSalesDayLabel }}</b>
            </div>
            <div class="kv">
              <span>Peak sessiya kuni</span>
              <b>{{ peakSessionDayLabel }}</b>
            </div>
            <div class="kv">
              <span>Peak soat</span>
              <b>{{ peakHourLabel }}</b>
            </div>
          </div>
        </article>
      </section>

      <section v-if="activeTab === 'insights'" class="panel glass">
        <div class="panel-head">
          <div>
            <h3>Bonus trend</h3>
            <p>Kunlar bo'yicha jami bonus oqimi (topup + tier bonus)</p>
          </div>
        </div>
        <div v-if="daily.length" class="bonus-bars">
          <div class="bonus-item" v-for="d in daily" :key="'bonus-' + d.date">
            <div class="bonus-col">
              <div
                class="bonus-fill"
                :style="{ height: bonusMax > 0 ? Math.max(3, Math.round((int(d.total_bonus) / bonusMax) * 100)) + '%' : '3%' }"
              ></div>
            </div>
            <small>{{ d.date.slice(5) }}</small>
            <b>{{ money(d.total_bonus) }}</b>
          </div>
        </div>
        <div v-else class="empty">Bonus trend uchun ma'lumot yo'q.</div>
      </section>
    </template>

    <section v-if="activeTab === 'autopilot'" class="panel glass autopilot">
      <div class="autopilot-head">
        <div>
          <h3>AI Profit Autopilot</h3>
          <p>Dinamik narx va promo tavsiyalari. 1 klik bilan qo'llash mumkin.</p>
          <small>Davr: {{ autopilotRangeLabel }}</small>
        </div>
        <div class="autopilot-controls">
          <label class="field mini">
            <span>Strategiya</span>
            <select v-model="autopilotStrategy" class="input">
              <option v-for="opt in strategyOptions" :key="opt.key" :value="opt.key">
                {{ opt.label }}
              </option>
            </select>
          </label>
          <button class="btn" :disabled="loadingAutopilot" @click="loadAutopilot">
            {{ loadingAutopilot ? 'Hisoblanmoqda...' : 'Qayta hisoblash' }}
          </button>
          <button class="btn primary" :disabled="applyingAutopilot || loadingAutopilot || !autopilotData" @click="applyAutopilot">
            {{ applyingAutopilot ? "Qo'llanmoqda..." : "Autopilotni qo'llash" }}
          </button>
        </div>
      </div>

      <div v-if="autopilotError" class="alert danger">{{ autopilotError }}</div>

      <template v-if="autopilotData">
        <div class="beast-hero">
          <div class="beast-copy">
            <p class="eyebrow">Beast Mode</p>
            <h3>{{ beastMode.headline || 'Profit Guarantee Engine' }}</h3>
            <p>{{ beastMode.subline || "Biz dastur emas, foyda natijasini boshqaramiz." }}</p>
            <div class="beast-pitch">
              <span>{{ beastPitch.owner }}</span>
              <span>{{ beastPitch.operator }}</span>
              <span>{{ beastPitch.sales }}</span>
            </div>
          </div>
          <div class="beast-state" :class="{ on: beastState.enabled }">
            <strong>{{ beastState.enabled ? 'BEAST MODE ON' : 'BEAST MODE OFF' }}</strong>
            <small>{{ beastState.enabled ? 'Profit Guard faol' : 'Profit Guard o‘chiq' }}</small>
            <button
              class="btn primary"
              :disabled="applyingAutopilot || loadingAutopilot"
              @click="toggleBeastMode(!beastState.enabled)"
            >
              {{ applyingAutopilot ? 'Saqlanmoqda...' : (beastState.enabled ? "Beast Mode o'chirish" : 'Beast Mode yoqish') }}
            </button>
          </div>
        </div>

        <div class="autopilot-summary">
          <article class="auto-card">
            <span>Ishonchlilik</span>
            <strong>{{ int(autopilotMeta.confidence_pct) }}%</strong>
            <small>Model confidence</small>
          </article>
          <article class="auto-card">
            <span>Zone update</span>
            <strong>{{ int(autopilotMeta.zones_to_update) }}</strong>
            <small>Narx yangilanishlari</small>
          </article>
          <article class="auto-card">
            <span>Promo</span>
            <strong>{{ autopilotMeta.promotion_enabled ? 'ON' : 'OFF' }}</strong>
            <small>Avto bonus kampaniya</small>
          </article>
          <article class="auto-card">
            <span>Oylik uplift</span>
            <strong>{{ money(autopilotWhatIf.monthly_uplift_estimate) }} UZS</strong>
            <small>Taxminiy qo'shimcha sof tushum</small>
          </article>
        </div>

        <div class="autopilot-grid">
          <article class="panel glass">
            <div class="panel-head">
              <div>
                <h3>Profit Guard (60 kun)</h3>
                <p>Kafolatli minimal sof tushum yo'lagi</p>
              </div>
            </div>
            <div class="guard-grid">
              <div class="guard-item">
                <span>Oldingi floor (oylik)</span>
                <strong>{{ money(beastProfitGuard.monthly_floor_before) }} UZS</strong>
              </div>
              <div class="guard-item">
                <span>Yangi floor (oylik)</span>
                <strong>{{ money(beastProfitGuard.monthly_floor_after) }} UZS</strong>
              </div>
              <div class="guard-item">
                <span>Kafolat uplift</span>
                <strong>{{ num(beastProfitGuard.guaranteed_uplift_pct, 1) }}%</strong>
              </div>
              <div class="guard-item">
                <span>Expected oylik net</span>
                <strong>{{ money(beastProfitGuard.expected_monthly_net) }} UZS</strong>
              </div>
            </div>
          </article>

          <article class="panel glass">
            <div class="panel-head">
              <div>
                <h3>Leak Watch</h3>
                <p>Naqd yo'qotish va operatsion risklar</p>
              </div>
            </div>
            <div class="guard-grid">
              <div class="guard-item">
                <span>Risk score</span>
                <strong>{{ int(beastLeakWatch.risk_score) }}/100</strong>
              </div>
              <div class="guard-item">
                <span>Taxminiy yo'qotish (oy)</span>
                <strong>{{ money(beastLeakWatch.estimated_monthly_leakage) }} UZS</strong>
              </div>
              <div class="guard-item">
                <span>Energy oynasi</span>
                <strong>{{ beastSleepWindowLabel }}</strong>
              </div>
              <div class="guard-item">
                <span>Energy savings (oy)</span>
                <strong>{{ money(beastEnergy.estimated_monthly_savings) }} UZS</strong>
              </div>
            </div>
          </article>
        </div>

        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Risk signallar</h3>
              <p>Yuqori signal ko'paygan joyni birinchi yopish kerak</p>
            </div>
          </div>
          <div class="table-wrap" v-if="beastSignals.length">
            <div class="thead trow cols-signal">
              <span>Signal</span><span>Qiymat</span><span>Status</span><span>Impact (oy)</span>
            </div>
            <div class="trow cols-signal" v-for="s in beastSignals" :key="s.key">
              <span>{{ s.label }}</span>
              <span>{{ s.value }}</span>
              <span><b :class="riskClass(s.status)">{{ s.status }}</b></span>
              <span>{{ money(s.impact_uzs) }} UZS</span>
            </div>
          </div>
          <div v-else class="empty">Risk signal topilmadi.</div>
        </article>

        <div class="autopilot-grid">
          <article class="panel glass">
            <div class="panel-head">
              <div>
                <h3>Dinamik tarif tavsiyasi</h3>
                <p>Zone bo'yicha narx optimizatsiyasi</p>
              </div>
            </div>

            <div class="table-wrap" v-if="autopilotZoneUpdates.length">
              <div class="thead trow cols-auto-zone">
                <span>Zona</span><span>Hozirgi</span><span>Tavsiya</span><span>Delta</span><span>Uplift</span>
              </div>
              <div class="trow cols-auto-zone" v-for="z in autopilotZoneUpdates" :key="'az-' + z.zone_id">
                <span>{{ z.zone_name }}</span>
                <span>{{ money(z.current_price_per_hour) }}</span>
                <span>{{ money(z.recommended_price_per_hour) }}</span>
                <span :class="Number(z.delta_pct) >= 0 ? 'up' : 'down'">{{ signed(z.delta_pct) }}%</span>
                <span>{{ money(z.expected_monthly_uplift) }}</span>
              </div>
            </div>
            <div v-else class="empty">Narxni o'zgartirishga ehtiyoj topilmadi.</div>
          </article>

          <article class="panel glass">
            <div class="panel-head">
              <div>
                <h3>Promo optimizer</h3>
                <p>Past yuklamali oynada bonus kampaniya</p>
              </div>
            </div>

            <div class="promo-plan" v-if="autopilotPromotion">
              <div class="kv"><span>Holat</span><b>{{ autopilotPromotion.enabled ? 'Tavsiya etiladi' : "Hozircha shart emas" }}</b></div>
              <div class="kv"><span>Nomi</span><b>{{ autopilotPromotion.name }}</b></div>
              <div class="kv"><span>Vaqt oynasi</span><b>{{ autopilotPromotion.time_from }} - {{ autopilotPromotion.time_to }}</b></div>
              <div class="kv"><span>Uplift (oylik)</span><b>{{ money(autopilotPromotion.expected_monthly_uplift) }} UZS</b></div>
              <div class="kv"><span>Sabab</span><b>{{ autopilotPromotion.reason }}</b></div>
            </div>
            <div v-else class="empty">Promo tavsiyasi topilmadi.</div>
          </article>
        </div>

        <div class="autopilot-grid">
          <article class="panel glass">
            <div class="panel-head">
              <div>
                <h3>Demand heatmap</h3>
                <p>Soat bo'yicha bandlik bosimi</p>
              </div>
            </div>
            <div class="heatmap" v-if="autopilotHeatmap.length">
              <div class="heat-cell" v-for="h in autopilotHeatmap" :key="'hh-' + h.hour">
                <span>{{ h.label }}</span>
                <b>{{ num(h.occupancy_pct, 1) }}%</b>
                <div class="heat-track">
                  <div class="heat-fill" :style="{ width: Math.max(3, Math.round(Number(h.occupancy_pct || 0))) + '%' }"></div>
                </div>
              </div>
            </div>
            <div v-else class="empty">Heatmap ma'lumotlari yo'q.</div>
          </article>

          <article class="panel glass">
            <div class="panel-head">
              <div>
                <h3>What-if simulyator</h3>
                <p>Autopilot qo'llansa taxminiy natija</p>
              </div>
            </div>
            <div class="scenario-list" v-if="autopilotScenarios.length">
              <div class="scenario-row" v-for="s in autopilotScenarios" :key="s.key">
                <div>
                  <strong>{{ s.label }}</strong>
                  <small>+{{ money(s.uplift_monthly) }} UZS / oy</small>
                </div>
                <div class="scenario-values">
                  <b>{{ money(s.monthly_net) }} UZS</b>
                  <em>{{ money(s.yearly_net) }} UZS / yil</em>
                </div>
              </div>
            </div>
            <div v-else class="empty">Scenario ma'lumotlari yo'q.</div>
          </article>
        </div>

        <div class="autopilot-grid">
          <article class="panel glass">
            <div class="panel-head">
              <div>
                <h3>Peak soatlar</h3>
                <p>Narx oshirishga mos bo'lgan vaqtlar</p>
              </div>
            </div>
            <div class="mini-bars" v-if="autopilotPeakHours.length">
              <div class="mini-bar-item" v-for="h in autopilotPeakHours" :key="'peak-' + h.hour">
                <div class="mini-label">{{ h.label }}</div>
                <div class="mini-track">
                  <div class="mini-fill" :style="{ width: Math.max(4, Math.round(Number(h.occupancy_pct || 0))) + '%' }"></div>
                </div>
                <div class="mini-val">{{ num(h.occupancy_pct, 1) }}%</div>
              </div>
            </div>
            <div v-else class="empty">Peak ma'lumot topilmadi.</div>
          </article>

          <article class="panel glass">
            <div class="panel-head">
              <div>
                <h3>Low soatlar</h3>
                <p>Promo yoqishga mos bo'lgan vaqtlar</p>
              </div>
            </div>
            <div class="mini-bars" v-if="autopilotLowHours.length">
              <div class="mini-bar-item" v-for="h in autopilotLowHours" :key="'low-' + h.hour">
                <div class="mini-label">{{ h.label }}</div>
                <div class="mini-track">
                  <div class="mini-fill alt" :style="{ width: Math.max(4, Math.round(Number(h.occupancy_pct || 0))) + '%' }"></div>
                </div>
                <div class="mini-val">{{ num(h.occupancy_pct, 1) }}%</div>
              </div>
            </div>
            <div v-else class="empty">Low ma'lumot topilmadi.</div>
          </article>
        </div>
      </template>
    </section>

    <section v-if="activeTab === 'exchange'" class="panel glass exchange">
      <div class="exchange-head">
        <div>
          <p class="eyebrow">Network</p>
          <h3>{{ exchangePitch.headline || 'MyCafe Exchange Network' }}</h3>
          <p>{{ exchangePitch.subline || "Klublar tarmog'i bo'ylab mijoz oqimini boshqarish." }}</p>
        </div>
        <button class="btn" :disabled="loadingExchange" @click="loadExchange">
          {{ loadingExchange ? 'Yuklanmoqda...' : 'Yangilash' }}
        </button>
      </div>

      <div v-if="exchangeError" class="alert danger">{{ exchangeError }}</div>

      <template v-if="exchangeData">
        <div class="exchange-top-grid">
          <article class="auto-card">
            <span>Joriy yuklama</span>
            <strong>{{ num(exchangeClub.load_pct, 1) }}%</strong>
            <small>{{ int(exchangeClub.active_sessions) }} aktiv / {{ int(exchangeClub.pcs_online) }} online</small>
          </article>
          <article class="auto-card">
            <span>Hamkorlar tayyor</span>
            <strong>{{ int(exchangeNetwork.partners_ready) }}</strong>
            <small>Jami: {{ int(exchangeNetwork.partners_total) }}</small>
          </article>
          <article class="auto-card">
            <span>MyCafe ID (cross-club)</span>
            <strong>{{ int(exchangePassport.cross_club_members) }}</strong>
            <small>Passportlar jami: {{ int(exchangePassport.members_total) }}</small>
          </article>
          <article class="auto-card">
            <span>Overflow recovery (oy)</span>
            <strong>{{ money(exchangeOverflow.projected_recovered_monthly) }} UZS</strong>
            <small>{{ exchangeOverflow.needed ? 'Yuklama bosimi bor' : 'Hozircha bosim past' }}</small>
          </article>
        </div>

        <div class="autopilot-grid">
          <article class="panel glass">
            <div class="panel-head">
              <div>
                <h3>Exchange sozlamalari</h3>
                <p>Networkga chiqish, radius va auction parametrlari</p>
              </div>
            </div>

            <div class="exchange-form">
              <label class="toggle-line">
                <input v-model="exchangeForm.enabled" type="checkbox" />
                <span>Exchange network yoqilgan</span>
              </label>
              <label class="toggle-line">
                <input v-model="exchangeForm.overflow_enabled" type="checkbox" />
                <span>Overflow paytida auto-yo'naltirish</span>
              </label>

              <label class="field mini">
                <span>Radius (km)</span>
                <input v-model.number="exchangeForm.radius_km" type="number" min="1" max="300" class="input" />
              </label>
              <label class="field mini">
                <span>Min free PC</span>
                <input v-model.number="exchangeForm.min_free_pcs" type="number" min="0" max="1000" class="input" />
              </label>
              <label class="field mini">
                <span>Referral bonus (UZS)</span>
                <input v-model.number="exchangeForm.referral_bonus_uzs" type="number" min="0" class="input" />
              </label>
              <label class="field mini">
                <span>Auction floor (UZS)</span>
                <input v-model.number="exchangeForm.auction_floor_uzs" type="number" min="0" class="input" />
              </label>
              <label class="field mini">
                <span>Auction ceiling (UZS)</span>
                <input v-model.number="exchangeForm.auction_ceiling_uzs" type="number" min="0" class="input" />
              </label>
            </div>

            <div class="exchange-actions">
              <button class="btn primary" :disabled="savingExchange" @click="saveExchangeConfig">
                {{ savingExchange ? 'Saqlanmoqda...' : 'Sozlamani saqlash' }}
              </button>
            </div>
          </article>

          <article class="panel glass">
            <div class="panel-head">
              <div>
                <h3>Traffic auction</h3>
                <p>Inbound trafik uchun tavsiya bid</p>
              </div>
            </div>
            <div class="guard-grid">
              <div class="guard-item">
                <span>Recommended bid</span>
                <strong>{{ money(exchangeAuction.recommended_bid_uzs) }} UZS</strong>
              </div>
              <div class="guard-item">
                <span>Floor / Ceiling</span>
                <strong>{{ money(exchangeAuction.floor_uzs) }} / {{ money(exchangeAuction.ceiling_uzs) }}</strong>
              </div>
              <div class="guard-item">
                <span>Overflow demand</span>
                <strong>{{ int(exchangeOverflow.demand_pcs) }} PC</strong>
              </div>
              <div class="guard-item">
                <span>Yo'naltirish mumkin</span>
                <strong>{{ int(exchangeOverflow.reroute_pcs) }} PC</strong>
              </div>
            </div>
            <div class="empty" style="margin-top:10px">{{ exchangeAuction.reason }}</div>
          </article>
        </div>

        <article class="panel glass">
          <div class="panel-head">
            <div>
              <h3>Top partner klublar</h3>
              <p>Eng yaqin va qabul qila oladigan hamkorlar</p>
            </div>
          </div>
          <div class="table-wrap" v-if="exchangeTopPartners.length">
            <div class="thead trow cols-exchange">
              <span>Klub</span><span>Masofa</span><span>Yuklama</span><span>Bo'sh PC</span><span>Bid</span>
            </div>
            <div class="trow cols-exchange" v-for="p in exchangeTopPartners" :key="'xp-' + p.tenant_id">
              <span>{{ p.club_name }}</span>
              <span>{{ p.distance_km === null ? '-' : num(p.distance_km, 1) + ' km' }}</span>
              <span><b :class="loadClass(p.load_pct)">{{ num(p.load_pct, 1) }}%</b></span>
              <span>{{ int(p.free_pcs) }}</span>
              <span>{{ money(p.suggested_bid_uzs) }}</span>
            </div>
          </div>
          <div v-else class="empty">Qabul qila oladigan partner topilmadi.</div>
        </article>
      </template>
    </section>

    <section v-if="activeTab === 'compare'" class="compare panel glass">
      <div class="compare-top">
        <div>
          <h3>Filial bilan solishtirish</h3>
          <p>Boshqa filialning license key ini kiriting.</p>
        </div>
        <div class="compare-form">
          <input
            v-model.trim="compareKey"
            class="input"
            placeholder="Filial license kaliti"
          />
          <button class="btn primary" :disabled="loadingCompare || !compareKey" @click="loadCompare">
            {{ loadingCompare ? 'Solishtirilmoqda...' : 'Solishtirish' }}
          </button>
        </div>
      </div>

      <div v-if="compareError" class="alert danger">{{ compareError }}</div>

      <template v-if="compareData">
        <div class="clubs">
          <div class="club-card">
            <span>Joriy klub</span>
            <strong>{{ compareData.left?.tenant?.name || '-' }}</strong>
          </div>
          <div class="club-card">
            <span>Taqqoslanayotgan filial</span>
            <strong>{{ compareData.right?.tenant?.name || '-' }}</strong>
            <small>License: {{ compareData.right?.license?.key || '-' }}</small>
          </div>
        </div>

        <div class="battle-list">
          <div class="battle-item" v-for="item in compareItems" :key="item.key">
            <div class="battle-head">
              <span>{{ item.label }}</span>
              <b :class="item.diff > 0 ? 'up' : item.diff < 0 ? 'down' : ''">
                {{ diffWithUnit(item.diff, item.unit) }}
              </b>
            </div>
            <div class="battle-bars">
              <div class="battle-bar left">
                <div class="fill left-fill" :style="{ width: item.leftPct + '%' }"></div>
                <em>{{ valueWithUnit(item.left, item.unit) }}</em>
              </div>
              <div class="battle-bar right">
                <div class="fill right-fill" :style="{ width: item.rightPct + '%' }"></div>
                <em>{{ valueWithUnit(item.right, item.unit) }}</em>
              </div>
            </div>
          </div>
        </div>
      </template>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { cpApi } from '../../api/cp'

const chartWidth = 760
const chartHeight = 260
const chartPad = 22

function dateToInput(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const to = new Date()
const from = new Date()
from.setDate(from.getDate() - 6)

const filters = reactive({
  from: dateToInput(from),
  to: dateToInput(to),
})

const loadingOverview = ref(false)
const loadingCompare = ref(false)
const loadingAutopilot = ref(false)
const applyingAutopilot = ref(false)
const loadingExchange = ref(false)
const savingExchange = ref(false)
const overviewError = ref('')
const compareError = ref('')
const autopilotError = ref('')
const exchangeError = ref('')
const tabs = [
  { key: 'overview', label: 'Umumiy' },
  { key: 'sales', label: 'Savdo' },
  { key: 'operations', label: 'Operatsiya' },
  { key: 'insights', label: 'Insight' },
  { key: 'autopilot', label: 'Autopilot AI' },
  { key: 'exchange', label: 'Exchange' },
  { key: 'compare', label: 'Filial taqqoslash' },
]
const tabIcons = {
  overview:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10.5V20h14v-9.5"/></svg>',
  sales:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19h16"/><path d="M7 16V8"/><path d="M12 16V5"/><path d="M17 16v-6"/></svg>',
  operations:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/></svg>',
  insights:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v6"/><path d="M5 21h14"/><path d="M8.5 14.5 12 11l3.5 3.5"/></svg>',
  autopilot:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8"/><path d="M12 12l4-2"/></svg>',
  exchange:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 7h10"/><path d="M7 7l3-3"/><path d="M17 17H7"/><path d="M17 17l-3 3"/></svg>',
  compare:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 4v16"/><path d="M17 4v16"/><path d="M4 12h16"/></svg>',
}
const strategyOptions = [
  { key: 'balanced', label: 'Balans' },
  { key: 'growth', label: 'O‘sish' },
  { key: 'aggressive', label: 'Aggressiv' },
]
const activeTab = ref('overview')

const overview = ref(null)
const compareData = ref(null)
const compareKey = ref('')
const autopilotData = ref(null)
const autopilotStrategy = ref('balanced')
const exchangeData = ref(null)
const exchangeForm = reactive({
  enabled: false,
  radius_km: 20,
  min_free_pcs: 2,
  referral_bonus_uzs: 12000,
  overflow_enabled: true,
  auction_floor_uzs: 6000,
  auction_ceiling_uzs: 26000,
})

function extractError(error) {
  const msg = error?.response?.data?.message
  const errs = error?.response?.data?.errors
  if (msg) return msg
  if (errs && typeof errs === 'object') {
    const first = Object.values(errs).flat()[0]
    if (first) return String(first)
  }
  return "So'rov bajarilmadi"
}

function int(v) {
  return Number(v || 0)
}
function num(v, digits = 0) {
  return Number(v || 0).toFixed(digits)
}
function money(v) {
  return new Intl.NumberFormat('en-US').format(Math.round(Number(v || 0)))
}
function signed(v) {
  const n = Number(v || 0)
  return `${n > 0 ? '+' : ''}${num(n, 1)}`
}
function pct(value, total) {
  const t = Number(total || 0)
  if (t <= 0) return 0
  return Math.round((Number(value || 0) / t) * 100)
}
function valueWithUnit(v, unit) {
  if (unit === '%') return `${num(v, 1)}%`
  if (unit === 'min') return `${num(v, 1)} min`
  if (unit === 'UZS') return `${money(v)} UZS`
  return String(int(v))
}
function diffWithUnit(v, unit) {
  const n = Number(v || 0)
  const prefix = n > 0 ? '+' : ''
  if (unit === '%') return `${prefix}${num(n, 1)}%`
  if (unit === 'min') return `${prefix}${num(n, 1)} min`
  if (unit === 'UZS') return `${prefix}${money(n)} UZS`
  return `${prefix}${int(n)}`
}
function riskClass(status) {
  if (status === 'high') return 'risk-high'
  if (status === 'medium') return 'risk-medium'
  return 'risk-ok'
}
function loadClass(v) {
  const n = Number(v || 0)
  if (n >= 85) return 'risk-high'
  if (n >= 65) return 'risk-medium'
  return 'risk-ok'
}

const report = computed(() => overview.value?.report || {})

const summaryKeys = [
  'gross_sales',
  'net_sales',
  'sessions_count',
  'expenses_total',
  'returns_total',
  'tx_count',
  'utilization_pct',
  'avg_session_minutes',
  'avg_revenue_per_shift',
]

function isSummaryEmpty(obj) {
  return summaryKeys.every((k) => Number(obj?.[k] || 0) === 0)
}

// Some backends return blocks at the top level of the response (not nested under report).
const summary = computed(() => {
  const primary = report.value.summary || overview.value?.summary || {}
  const fallback = report.value || overview.value || {}
  if (isSummaryEmpty(primary) && !isSummaryEmpty(fallback)) return fallback
  return primary
})
const sales = computed(() => report.value.sales || overview.value?.sales || report.value || {})
const payments = computed(() => report.value.payments || overview.value?.payments || report.value || {})
const transfers = computed(() => report.value.transfers || overview.value?.transfers || report.value || {})
const activity = computed(() => report.value.activity || overview.value?.activity || report.value || {})
const sessionsMeta = computed(() => report.value.sessions || {})
const clientsMeta = computed(() => report.value.clients || {})
const shiftsMeta = computed(() => report.value.shifts || {})
const returnsMeta = computed(() => report.value.returns || {})
const transactionsMeta = computed(() => report.value.transactions || {})
const zonesMeta = computed(() => report.value.zones || {})
const operatorsMeta = computed(() => report.value.operators || {})
const pcsMeta = computed(() => report.value.pcs || {})
const growthMeta = computed(() => report.value.growth || {})
const insightsMeta = computed(() => report.value.insights || {})
const period = computed(() => report.value.period || {})
const topClients = computed(() => report.value.top_clients || [])
const daily = computed(() => report.value.daily || [])
const projection = computed(() => insightsMeta.value.projection || {})
const hourly = computed(() => sessionsMeta.value.hourly_distribution || [])
const weekday = computed(() => sessionsMeta.value.weekday_distribution || [])
const zonesItems = computed(() => zonesMeta.value.items || [])
const operatorItems = computed(() => operatorsMeta.value.items || [])
const pcsTop = computed(() => pcsMeta.value.top || [])
const pcsUnderused = computed(() => pcsMeta.value.underused || [])
const returnItems = computed(() => returnsMeta.value.items || [])
const transactionItems = computed(() => transactionsMeta.value.items || [])

const growthLabelMap = {
  net_sales: { label: 'Sof tushum', unit: 'UZS' },
  gross_sales: { label: 'Jami savdo', unit: 'UZS' },
  sessions_count: { label: 'Sessiyalar', unit: 'count' },
  avg_session_minutes: { label: "O'rtacha sessiya", unit: 'min' },
  utilization_pct: { label: 'Bandlik', unit: '%' },
  cash_net: { label: 'Naqd net', unit: 'UZS' },
  card_net: { label: 'Karta net', unit: 'UZS' },
  clients_in_period: { label: 'Davrdagi mijozlar', unit: 'count' },
}

const growthRows = computed(() => {
  const src = growthMeta.value || {}
  return Object.keys(growthLabelMap)
    .filter((k) => src[k])
    .map((k) => ({
      key: k,
      label: growthLabelMap[k].label,
      unit: growthLabelMap[k].unit,
      current: Number(src[k].current || 0),
      previous: Number(src[k].previous || 0),
      diff: Number(src[k].diff || 0),
      diff_pct: src[k].diff_pct === null ? null : Number(src[k].diff_pct),
      trend: src[k].trend || 'equal',
    }))
})

const periodLabel = computed(() => {
  const p = period.value
  if (!p.from || !p.to) return "Davr ma'lumotlari"
  return `${p.from} - ${p.to} | Oldingi: ${p.previous_from || '-'} - ${p.previous_to || '-'}`
})

const grossValues = computed(() => daily.value.map((d) => int(d.gross_sales)))
const netValues = computed(() => daily.value.map((d) => int(d.net_sales)))
const chartMax = computed(() => Math.max(1, ...grossValues.value, ...netValues.value))
const yTicks = computed(() => {
  const max = chartMax.value
  return [max, Math.round(max * 0.75), Math.round(max * 0.5), Math.round(max * 0.25), 0]
})

const xLabels = computed(() => {
  const list = daily.value
  if (!list.length) return []
  if (list.length <= 7) return list.map((d) => d.date.slice(5))
  const step = Math.max(1, Math.floor(list.length / 6))
  return list.filter((_, i) => i % step === 0 || i === list.length - 1).map((d) => d.date.slice(5))
})

const paymentTotal = computed(() => {
  return int(payments.value.cash_sales_total) + int(payments.value.card_sales_total) + int(payments.value.balance_sales_total)
})

const donutStyle = computed(() => {
  const total = paymentTotal.value
  if (total <= 0) {
    return { background: 'conic-gradient(#32435e 0 100%)' }
  }
  const cashPct = (int(payments.value.cash_sales_total) / total) * 100
  const cardPct = (int(payments.value.card_sales_total) / total) * 100
  const balancePct = 100 - cashPct - cardPct

  const p1 = cashPct
  const p2 = cashPct + cardPct
  const p3 = p2 + balancePct

  return {
    background: `conic-gradient(#4f8cff 0 ${p1}%, #24c8a4 ${p1}% ${p2}%, #f3a63b ${p2}% ${p3}%)`,
  }
})

const salesMixRows = computed(() => {
  const topup = int(sales.value.topup_total)
  const pack = int(sales.value.package_total)
  const sub = int(sales.value.subscription_total)
  const total = topup + pack + sub
  const safe = total > 0 ? total : 1
  return [
    { key: 'topup', label: 'Topup', value: topup, pct: Math.round((topup / safe) * 100), color: 'linear-gradient(90deg,#4f8cff,#70a6ff)' },
    { key: 'package', label: 'Paket', value: pack, pct: Math.round((pack / safe) * 100), color: 'linear-gradient(90deg,#24c8a4,#57dfbf)' },
    { key: 'subscription', label: 'Obuna', value: sub, pct: Math.round((sub / safe) * 100), color: 'linear-gradient(90deg,#f3a63b,#ffc46b)' },
  ]
})

const topClientMax = computed(() => {
  if (!topClients.value.length) return 0
  return Math.max(...topClients.value.map((c) => int(c.spent)), 0)
})
const hourlyMax = computed(() => hourly.value.length ? Math.max(...hourly.value.map((h) => int(h.sessions_count)), 0) : 0)
const weekdayMax = computed(() => weekday.value.length ? Math.max(...weekday.value.map((d) => int(d.sessions_count)), 0) : 0)
const peakHourLabel = computed(() => {
  const p = sessionsMeta.value.peak_hour
  if (!p) return '-'
  return `${p.label} (${int(p.sessions_count)} sessiya)`
})
const peakWeekdayLabel = computed(() => {
  const p = sessionsMeta.value.peak_weekday
  if (!p) return '-'
  return `${p.label} (${int(p.sessions_count)} sessiya)`
})
const peakSalesDayLabel = computed(() => {
  const p = sessionsMeta.value.peak_sales_day
  if (!p) return '-'
  return `${p.date} (${money(p.net_sales)} UZS)`
})
const peakSessionDayLabel = computed(() => {
  const p = sessionsMeta.value.peak_session_day
  if (!p) return '-'
  return `${p.date} (${int(p.sessions_count)} sessiya)`
})
const peakBonusLabel = computed(() => {
  const p = sessionsMeta.value.peak_bonus_day
  if (!p) return '-'
  return `${p.date} (${money(p.total_bonus)} UZS)`
})
const bonusMax = computed(() => daily.value.length ? Math.max(...daily.value.map((d) => int(d.total_bonus)), 0) : 0)

const compareItems = computed(() => {
  const items = compareData.value?.comparison || []
  return items.map((item) => {
    const left = Number(item.left || 0)
    const right = Number(item.right || 0)
    const base = Math.max(Math.abs(left), Math.abs(right), 1)
    return {
      ...item,
      leftPct: Math.max(6, Math.round((Math.abs(left) / base) * 100)),
      rightPct: Math.max(6, Math.round((Math.abs(right) / base) * 100)),
    }
  })
})
const autopilotPlan = computed(() => autopilotData.value || {})
const autopilotActions = computed(() => autopilotPlan.value.actions || {})
const autopilotZoneUpdates = computed(() => autopilotActions.value.zone_price_updates || [])
const autopilotPromotion = computed(() => autopilotActions.value.promotion || null)
const autopilotWhatIf = computed(() => autopilotPlan.value.what_if || {})
const autopilotScenarios = computed(() => autopilotWhatIf.value.scenarios || [])
const autopilotDemand = computed(() => autopilotPlan.value.demand || {})
const autopilotPeakHours = computed(() => autopilotDemand.value.peak_hours || [])
const autopilotLowHours = computed(() => autopilotDemand.value.low_hours || [])
const autopilotHeatmap = computed(() => autopilotDemand.value.heatmap || [])
const autopilotMeta = computed(() => autopilotPlan.value.meta || {})
const beastMode = computed(() => autopilotPlan.value.beast_mode || {})
const beastState = computed(() => beastMode.value.state || { enabled: false })
const beastProfitGuard = computed(() => beastMode.value.profit_guard || {})
const beastLeakWatch = computed(() => beastMode.value.leak_watch || {})
const beastSignals = computed(() => beastLeakWatch.value.signals || [])
const beastEnergy = computed(() => beastMode.value.energy_optimizer || {})
const beastPitch = computed(() => beastMode.value.pitch || {})
const beastSleepWindowLabel = computed(() => {
  const win = beastEnergy.value.recommended_sleep_window || {}
  if (!win.from || !win.to) return '-'
  return `${win.from} - ${win.to}`
})
const exchangeClub = computed(() => exchangeData.value?.club || {})
const exchangeConfig = computed(() => exchangeData.value?.config || {})
const exchangePassport = computed(() => exchangeData.value?.passport || {})
const exchangePartners = computed(() => exchangeData.value?.partners || [])
const exchangeTopPartners = computed(() => exchangePartners.value.filter((p) => p.can_receive).slice(0, 8))
const exchangeOverflow = computed(() => exchangeData.value?.overflow || {})
const exchangeAuction = computed(() => exchangeData.value?.auction || {})
const exchangeNetwork = computed(() => exchangeData.value?.network || {})
const exchangePitch = computed(() => exchangeData.value?.pitch || {})
const autopilotRangeLabel = computed(() => {
  const r = autopilotPlan.value.range || {}
  if (!r.from || !r.to) return '-'
  return `${r.from} - ${r.to} (${int(r.days)} kun)`
})

function xToPoint(index, total) {
  if (total <= 1) return chartPad
  const freeWidth = chartWidth - chartPad * 2
  return chartPad + (index / (total - 1)) * freeWidth
}

function yToPoint(value) {
  const max = chartMax.value
  const safeMax = max <= 0 ? 1 : max
  const freeHeight = chartHeight - chartPad * 2
  return chartHeight - chartPad - (Number(value || 0) / safeMax) * freeHeight
}

function linePath(values) {
  if (!values.length) return ''
  return values
    .map((v, i) => `${i === 0 ? 'M' : 'L'} ${xToPoint(i, values.length)} ${yToPoint(v)}`)
    .join(' ')
}

function areaPath(values) {
  if (!values.length) return ''
  const baseline = chartHeight - chartPad
  const firstX = xToPoint(0, values.length)
  const lastX = xToPoint(values.length - 1, values.length)
  const line = values
    .map((v, i) => `${i === 0 ? 'L' : 'L'} ${xToPoint(i, values.length)} ${yToPoint(v)}`)
    .join(' ')
  return `M ${firstX} ${baseline} ${line} L ${lastX} ${baseline} Z`
}

async function loadOverview() {
  loadingOverview.value = true
  overviewError.value = ''
  try {
    const { data } = await cpApi.reportOverview({
      from: filters.from,
      to: filters.to,
    })
    overview.value = data?.data || null
  } catch (error) {
    overviewError.value = extractError(error)
    overview.value = null
  } finally {
    loadingOverview.value = false
  }
}

async function loadCompare() {
  if (!compareKey.value) return
  loadingCompare.value = true
  compareError.value = ''
  try {
    const { data } = await cpApi.reportBranchCompare({
      from: filters.from,
      to: filters.to,
      license_key: compareKey.value,
    })
    compareData.value = data?.data || null
  } catch (error) {
    compareError.value = extractError(error)
    compareData.value = null
  } finally {
    loadingCompare.value = false
  }
}

async function loadAutopilot() {
  loadingAutopilot.value = true
  autopilotError.value = ''
  try {
    const { data } = await cpApi.reportAutopilot({
      from: filters.from,
      to: filters.to,
      strategy: autopilotStrategy.value,
    })
    autopilotData.value = data?.data || null
  } catch (error) {
    autopilotError.value = extractError(error)
    autopilotData.value = null
  } finally {
    loadingAutopilot.value = false
  }
}

async function loadExchange() {
  loadingExchange.value = true
  exchangeError.value = ''
  try {
    const { data } = await cpApi.reportExchange({
      from: filters.from,
      to: filters.to,
    })
    exchangeData.value = data?.data || null

    const cfg = exchangeData.value?.config || {}
    exchangeForm.enabled = !!cfg.enabled
    exchangeForm.radius_km = int(cfg.radius_km || 20)
    exchangeForm.min_free_pcs = int(cfg.min_free_pcs || 2)
    exchangeForm.referral_bonus_uzs = int(cfg.referral_bonus_uzs || 12000)
    exchangeForm.overflow_enabled = cfg.overflow_enabled !== false
    exchangeForm.auction_floor_uzs = int(cfg.auction_floor_uzs || 6000)
    exchangeForm.auction_ceiling_uzs = int(cfg.auction_ceiling_uzs || 26000)
  } catch (error) {
    exchangeError.value = extractError(error)
    exchangeData.value = null
  } finally {
    loadingExchange.value = false
  }
}

async function saveExchangeConfig() {
  savingExchange.value = true
  exchangeError.value = ''
  try {
    await cpApi.reportExchangeConfig({
      enabled: !!exchangeForm.enabled,
      radius_km: int(exchangeForm.radius_km),
      min_free_pcs: int(exchangeForm.min_free_pcs),
      referral_bonus_uzs: int(exchangeForm.referral_bonus_uzs),
      overflow_enabled: !!exchangeForm.overflow_enabled,
      auction_floor_uzs: int(exchangeForm.auction_floor_uzs),
      auction_ceiling_uzs: int(exchangeForm.auction_ceiling_uzs),
    })
    await loadExchange()
  } catch (error) {
    exchangeError.value = extractError(error)
  } finally {
    savingExchange.value = false
  }
}

async function applyAutopilot() {
  applyingAutopilot.value = true
  autopilotError.value = ''
  try {
    const { data } = await cpApi.reportAutopilotApply({
      from: filters.from,
      to: filters.to,
      strategy: autopilotStrategy.value,
      apply_zone_prices: true,
      apply_promotion: true,
      enable_beast_mode: true,
      dry_run: false,
    })

    if (data?.data?.plan) {
      autopilotData.value = data.data.plan
    }
    await loadOverview()
  } catch (error) {
    autopilotError.value = extractError(error)
  } finally {
    applyingAutopilot.value = false
  }
}

async function toggleBeastMode(nextEnabled) {
  applyingAutopilot.value = true
  autopilotError.value = ''
  try {
    const { data } = await cpApi.reportAutopilotApply({
      from: filters.from,
      to: filters.to,
      strategy: autopilotStrategy.value,
      apply_zone_prices: false,
      apply_promotion: false,
      enable_beast_mode: !!nextEnabled,
      dry_run: false,
    })

    if (data?.data?.plan) {
      autopilotData.value = data.data.plan
    }
    await loadAutopilot()
  } catch (error) {
    autopilotError.value = extractError(error)
  } finally {
    applyingAutopilot.value = false
  }
}

async function refreshAll() {
  await loadOverview()
  await loadAutopilot()
  await loadExchange()
  if (compareKey.value) await loadCompare()
}

onMounted(() => {
  refreshAll()
})
</script>

<style scoped>
.reports-page {
  display: grid;
  gap: 14px;
  position: relative;
  padding: 6px 4px 18px;
  color: #e8f0ff;
  font-family: 'Manrope', 'Space Grotesk', 'Sora', 'Montserrat', sans-serif;
  overflow-x: hidden;
}

.reports-page::before {
  content: '';
  position: absolute;
  inset: -60px -60px 0;
  background:
    radial-gradient(120% 90% at 0% 0%, rgba(77, 138, 255, 0.18), transparent 60%),
    radial-gradient(120% 90% at 100% 0%, rgba(105, 226, 193, 0.14), transparent 62%),
    radial-gradient(130% 110% at 50% 100%, rgba(255, 192, 105, 0.08), transparent 60%);
  pointer-events: none;
  z-index: 0;
}

.reports-page.fintech::after {
  content: '';
  position: absolute;
  inset: 0;
  background:
    linear-gradient(transparent 95%, rgba(255, 255, 255, 0.03) 100%),
    linear-gradient(90deg, transparent 95%, rgba(255, 255, 255, 0.02) 100%);
  background-size: 34px 34px;
  pointer-events: none;
  opacity: 0.28;
  z-index: 0;
}

:global(body) {
  overflow-x: hidden;
}

.reports-page > * {
  position: relative;
  z-index: 1;
}

.glass {
  border-radius: 18px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background:
    radial-gradient(140% 140% at 0% 0%, rgba(79, 140, 255, 0.18), transparent 58%),
    radial-gradient(120% 120% at 100% 100%, rgba(36, 200, 164, 0.12), transparent 62%),
    linear-gradient(180deg, rgba(9, 16, 32, 0.92), rgba(8, 13, 26, 0.82));
  box-shadow:
    0 22px 36px rgba(0, 0, 0, 0.32),
    inset 0 1px 0 rgba(255, 255, 255, 0.06);
  backdrop-filter: blur(14px);
}

.hero {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 14px;
  padding: 22px;
}

.tab-strip {
  padding: 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  position: sticky;
  top: 10px;
  z-index: 3;
}

.tab-btn {
  height: 42px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 999px;
  padding: 0 16px 0 12px;
  background: rgba(10, 16, 32, 0.72);
  color: rgba(226, 236, 248, 0.82);
  font-size: 13px;
  font-weight: 800;
  cursor: pointer;
  transition: all 0.2s ease;
  letter-spacing: 0.02em;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.04);
}

.tab-btn:hover {
  border-color: rgba(129, 171, 255, 0.38);
  transform: translateY(-1px);
}

.tab-btn.active {
  border-color: rgba(93, 151, 255, 0.6);
  background: linear-gradient(135deg, rgba(79, 140, 255, 0.45), rgba(40, 110, 230, 0.38));
  color: #fff;
  box-shadow: 0 8px 20px rgba(40, 90, 200, 0.35);
}

.tab-icon {
  width: 22px;
  height: 22px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: rgba(210, 228, 255, 0.9);
  flex: 0 0 auto;
}

.tab-icon svg {
  width: 14px;
  height: 14px;
}

.tab-btn.active .tab-icon {
  background: rgba(255, 255, 255, 0.16);
  border-color: rgba(120, 170, 255, 0.6);
  color: #fff;
  box-shadow: 0 0 0 3px rgba(79, 140, 255, 0.2);
}

.eyebrow {
  margin: 0;
  font-size: 11px;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: rgba(170, 198, 250, 0.9);
}

.title {
  margin: 5px 0 0;
  font-size: 26px;
  font-weight: 900;
  color: rgba(255, 255, 255, 0.95);
}

.subtitle {
  margin: 7px 0 0;
  max-width: 700px;
  font-size: 13px;
  color: rgba(210, 224, 245, 0.76);
}

.hero-meta {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 10px;
}

.pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.14);
  background: rgba(10, 18, 34, 0.75);
  font-size: 11px;
  color: rgba(234, 240, 252, 0.92);
}

.pill.subtle {
  color: rgba(199, 213, 238, 0.7);
  border-color: rgba(255, 255, 255, 0.08);
}

.hero-right {
  display: flex;
  align-items: end;
  justify-content: flex-end;
  gap: 10px;
  flex-wrap: wrap;
}

.field {
  display: grid;
  gap: 5px;
  font-size: 12px;
  color: rgba(228, 237, 255, 0.74);
  min-width: 150px;
}

.input {
  height: 38px;
  border-radius: 11px;
  border: 1px solid rgba(255, 255, 255, 0.14);
  background: rgba(5, 11, 22, 0.7);
  color: rgba(255, 255, 255, 0.93);
  padding: 0 10px;
  outline: none;
}

.input:focus {
  border-color: rgba(79, 140, 255, 0.58);
  box-shadow: 0 0 0 3px rgba(79, 140, 255, 0.17);
}

.btn {
  height: 38px;
  border-radius: 11px;
  border: 1px solid rgba(255, 255, 255, 0.14);
  padding: 0 14px;
  cursor: pointer;
  font-weight: 800;
  color: rgba(255, 255, 255, 0.95);
  background: rgba(255, 255, 255, 0.08);
}

.btn.primary {
  border-color: rgba(92, 152, 255, 0.46);
  background: linear-gradient(135deg, rgba(73, 140, 255, 0.7), rgba(40, 110, 230, 0.6));
}

.btn:disabled {
  opacity: 0.62;
  cursor: not-allowed;
}

.stats-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.stat-card {
  padding: 15px;
  position: relative;
  overflow: hidden;
}

.stat-card::after {
  content: '';
  position: absolute;
  inset: auto -20% -45% auto;
  width: 110px;
  height: 110px;
  border-radius: 99px;
  filter: blur(0.2px);
  opacity: 0.4;
}

.accent-blue::after { background: radial-gradient(circle, rgba(89, 154, 255, 0.85), transparent 70%); }
.accent-cyan::after { background: radial-gradient(circle, rgba(55, 202, 212, 0.85), transparent 70%); }
.accent-green::after { background: radial-gradient(circle, rgba(39, 214, 142, 0.85), transparent 70%); }
.accent-orange::after { background: radial-gradient(circle, rgba(247, 177, 78, 0.9), transparent 70%); }

.stat-label {
  margin: 0;
  font-size: 12px;
  color: rgba(224, 232, 245, 0.74);
}

.stat-value {
  margin: 9px 0 0;
  font-size: 28px;
  font-weight: 900;
  color: #fff;
  line-height: 1.05;
}

.stat-value span {
  font-size: 11px;
  font-weight: 700;
  opacity: 0.75;
}

.stat-note {
  margin: 8px 0 0;
  font-size: 11px;
  color: rgba(215, 228, 244, 0.66);
}

.overview-dashboard {
  display: grid;
  gap: 12px;
  grid-template-columns: 1.1fr 1.6fr 1fr;
}

.flow-compact .flow-rows {
  margin-top: 10px;
  display: grid;
  gap: 8px;
}

.flow-compact .flow-rows div {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 10px;
  background: rgba(5, 10, 20, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.06);
}

.flow-compact .flow-rows span {
  font-size: 12px;
  color: rgba(214, 226, 245, 0.78);
}

.flow-compact .flow-rows b {
  font-size: 12px;
  color: #fff;
}

.flow-compact .flow-rows .flow-net {
  border-color: rgba(106, 170, 255, 0.35);
  background: rgba(12, 22, 45, 0.65);
}

.panel {
  padding: 14px;
  position: relative;
  overflow: hidden;
}

.panel::before {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: 18px;
  padding: 1px;
  background: linear-gradient(135deg, rgba(120, 170, 255, 0.28), rgba(54, 204, 183, 0.18), rgba(255, 180, 110, 0.16));
  -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite: xor;
  mask-composite: exclude;
  pointer-events: none;
  opacity: 0.6;
}

.panel-head {
  display: flex;
  align-items: start;
  justify-content: space-between;
  gap: 10px;
}

.panel h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 900;
  color: rgba(255, 255, 255, 0.95);
}

.panel p {
  margin: 4px 0 0;
  font-size: 12px;
  color: rgba(205, 220, 244, 0.72);
}

.growth-panel {
  padding-bottom: 10px;
}

.growth-grid {
  margin-top: 10px;
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.growth-item {
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  background: rgba(5, 10, 20, 0.48);
  padding: 10px;
}

.growth-item span {
  font-size: 12px;
  color: rgba(220, 230, 246, 0.74);
}

.growth-item strong {
  display: block;
  margin-top: 6px;
  font-size: 14px;
  color: #fff;
}

.growth-item em {
  display: block;
  margin-top: 4px;
  font-style: normal;
  font-size: 12px;
  color: rgba(210, 220, 240, 0.72);
}

.signal-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(6, minmax(0, 1fr));
}

.signal-card {
  border-radius: 14px;
  padding: 12px 14px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(7, 12, 24, 0.68);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.05),
    0 8px 20px rgba(4, 8, 18, 0.4);
}

.signal-card span {
  font-size: 11px;
  color: rgba(203, 218, 242, 0.72);
}

.signal-card strong {
  display: block;
  margin-top: 6px;
  font-size: 14px;
  color: #fff;
}

.signal-card em {
  display: block;
  margin-top: 6px;
  font-style: normal;
  font-size: 11px;
  color: rgba(198, 214, 240, 0.6);
}

.section-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 2px 2px;
  margin-top: 4px;
}

.section-head span {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.18em;
  color: rgba(150, 182, 240, 0.9);
}

.section-head b {
  font-size: 13px;
  color: rgba(235, 243, 255, 0.95);
  position: relative;
}

.section-head b::after {
  content: '';
  position: absolute;
  left: 0;
  bottom: -6px;
  width: 160px;
  height: 2px;
  background: linear-gradient(90deg, rgba(110, 160, 255, 0.8), rgba(62, 210, 190, 0.6), rgba(255, 180, 110, 0.45));
  border-radius: 999px;
}

.ledger-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: 1.1fr 1fr;
}

.ledger-card {
  border-radius: 16px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(7, 12, 24, 0.72);
  padding: 14px 16px;
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.05),
    0 10px 24px rgba(4, 8, 18, 0.45);
}

.ledger-card h4 {
  margin: 0;
  font-size: 14px;
  color: rgba(245, 250, 255, 0.95);
}

.ledger-card p {
  margin: 6px 0 0;
  font-size: 12px;
  color: rgba(205, 220, 244, 0.7);
}

.ledger-items {
  margin-top: 10px;
  display: grid;
  gap: 8px;
}

.ledger-items div {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 10px;
  background: rgba(5, 10, 20, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.06);
}

.ledger-items span {
  font-size: 12px;
  color: rgba(214, 226, 245, 0.78);
}

.ledger-items b {
  font-size: 12px;
  color: #fff;
}

.legend {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  font-size: 11px;
  color: rgba(220, 228, 245, 0.73);
}

.legend span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.dot {
  width: 9px;
  height: 9px;
  border-radius: 99px;
  display: inline-block;
}

.dot.gross { background: #66a1ff; }
.dot.net { background: #20c9aa; }
.dot.cash { background: #4f8cff; }
.dot.card { background: #24c8a4; }
.dot.balance { background: #f3a63b; }

.chart-wrap {
  margin-top: 10px;
}

.line-chart {
  width: 100%;
  height: 260px;
  border-radius: 12px;
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01)),
    radial-gradient(80% 80% at 10% 10%, rgba(79, 140, 255, 0.08), transparent 60%);
}

.grid-line {
  stroke: rgba(255, 255, 255, 0.08);
  stroke-width: 1;
}

.net-area {
  fill: rgba(36, 200, 164, 0.16);
}

.gross-line {
  fill: none;
  stroke: #6aa8ff;
  stroke-width: 3;
}

.net-line {
  fill: none;
  stroke: #30d2b4;
  stroke-width: 3;
}

.x-labels {
  margin-top: 8px;
  display: flex;
  justify-content: space-between;
  gap: 8px;
}

.x-labels span {
  font-size: 11px;
  color: rgba(220, 228, 245, 0.66);
}

.donut-wrap {
  margin-top: 14px;
  display: flex;
  align-items: center;
  gap: 16px;
}

.donut {
  width: 184px;
  height: 184px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  flex: 0 0 auto;
}

.donut-center {
  width: 114px;
  height: 114px;
  border-radius: 50%;
  background: rgba(6, 12, 24, 0.98);
  border: 1px solid rgba(255, 255, 255, 0.14);
  display: grid;
  place-content: center;
  text-align: center;
}

.donut-center p {
  margin: 0;
  font-size: 16px;
  font-weight: 900;
  color: #fff;
}

.donut-center span {
  font-size: 11px;
  color: rgba(220, 228, 245, 0.68);
}

.donut-legend {
  display: grid;
  gap: 9px;
  width: 100%;
}

.lg-row {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 10px;
  align-items: center;
}

.lg-title {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 12px;
  color: rgba(226, 235, 248, 0.88);
}

.lg-row strong {
  font-size: 13px;
  color: rgba(255, 255, 255, 0.95);
}

.lg-row em {
  font-size: 12px;
  color: rgba(205, 224, 250, 0.72);
  font-style: normal;
}

.detail-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: 1.2fr 1fr;
}

.mix-list {
  margin-top: 10px;
  display: grid;
  gap: 12px;
}

.mix-item {
  border: 1px solid rgba(255, 255, 255, 0.09);
  border-radius: 12px;
  background: rgba(5, 10, 20, 0.48);
  padding: 10px 11px;
}

.mix-top {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}

.mix-top span {
  font-size: 12px;
  color: rgba(222, 233, 248, 0.82);
}

.mix-top strong {
  font-size: 12px;
  color: #fff;
}

.mix-bar {
  margin-top: 8px;
  height: 9px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  overflow: hidden;
}

.mix-fill {
  height: 100%;
  border-radius: inherit;
}

.mix-item small {
  display: inline-block;
  margin-top: 6px;
  color: rgba(215, 225, 242, 0.65);
  font-size: 11px;
}

.top-clients {
  margin-top: 10px;
  display: grid;
  gap: 10px;
}

.client-row {
  border: 1px solid rgba(255, 255, 255, 0.09);
  border-radius: 12px;
  background: rgba(5, 10, 20, 0.5);
  padding: 10px;
}

.client-meta {
  display: flex;
  justify-content: space-between;
  gap: 9px;
}

.client-meta strong {
  font-size: 13px;
  color: #fff;
}

.client-meta span {
  font-size: 11px;
  color: rgba(211, 224, 246, 0.66);
}

.client-bar {
  margin-top: 8px;
  height: 8px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  overflow: hidden;
}

.client-fill {
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, #4f8cff, #20c9aa);
}

.client-money {
  display: block;
  margin-top: 8px;
  text-align: right;
  font-size: 12px;
  color: rgba(255, 255, 255, 0.94);
}

.split-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: 1fr 1fr;
}

.mini-bars {
  margin-top: 10px;
  display: grid;
  gap: 8px;
}

.mini-bar-item {
  display: grid;
  grid-template-columns: 64px 1fr 38px;
  gap: 10px;
  align-items: center;
}

.mini-label {
  font-size: 11px;
  color: rgba(220, 230, 246, 0.76);
}

.mini-track {
  height: 8px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  overflow: hidden;
}

.mini-fill {
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, #5b95ff, #86b7ff);
}

.mini-fill.alt {
  background: linear-gradient(90deg, #24c8a4, #60dfc5);
}

.mini-val {
  text-align: right;
  font-size: 11px;
  color: rgba(236, 241, 250, 0.85);
}

.ops-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.compact h3 {
  margin-bottom: 10px;
}

.kv {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 8px;
  align-items: center;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  padding: 8px 0;
}

.kv:first-of-type {
  border-top: none;
}

.kv span {
  font-size: 12px;
  color: rgba(214, 225, 244, 0.75);
}

.kv b {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.95);
}

.table-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: 1fr 1fr;
}

.table-wrap {
  margin-top: 10px;
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 14px;
  overflow: hidden;
  background: rgba(6, 11, 22, 0.62);
}

.trow {
  display: grid;
  padding: 10px 12px;
  gap: 8px;
  align-items: center;
}

.thead {
  background: rgba(255, 255, 255, 0.08);
}

.thead span {
  font-size: 11px;
  color: rgba(219, 230, 246, 0.75);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.trow:not(.thead) {
  border-top: 1px solid rgba(255, 255, 255, 0.06);
}

.trow:not(.thead) span {
  font-size: 12px;
  color: rgba(236, 241, 250, 0.9);
}

.cols-zone {
  grid-template-columns: 1.2fr .7fr .7fr .9fr;
}

.cols-op {
  grid-template-columns: 1.2fr .5fr .7fr .9fr;
}

.cols-pc {
  grid-template-columns: 1fr .8fr .6fr .9fr;
}

.cols-small {
  grid-template-columns: 1fr .6fr .9fr;
}

.shift-grid {
  margin-top: 10px;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px 14px;
}

.insight-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: 1.2fr 1fr;
}

.forecast-cards {
  margin-top: 10px;
  display: grid;
  gap: 10px;
}

.forecast-card {
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  background: rgba(5, 10, 20, 0.5);
  padding: 10px 12px;
}

.forecast-card span {
  font-size: 12px;
  color: rgba(216, 228, 246, 0.76);
}

.forecast-card strong {
  display: block;
  margin-top: 6px;
  font-size: 18px;
  color: #fff;
}

.forecast-card em {
  display: block;
  margin-top: 6px;
  font-style: normal;
  font-size: 12px;
  color: rgba(210, 224, 247, 0.68);
}

.forecast-card.avg {
  box-shadow: inset 0 0 0 1px rgba(79, 140, 255, 0.24);
}

.forecast-card.min {
  box-shadow: inset 0 0 0 1px rgba(243, 166, 59, 0.2);
}

.forecast-card.max {
  box-shadow: inset 0 0 0 1px rgba(36, 200, 164, 0.2);
}

.insight-kv {
  margin-top: 10px;
}

.bonus-bars {
  margin-top: 12px;
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(auto-fit, minmax(68px, 1fr));
}

.bonus-item {
  display: grid;
  gap: 6px;
  justify-items: center;
}

.bonus-col {
  width: 100%;
  height: 130px;
  border-radius: 10px;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01));
  display: flex;
  align-items: flex-end;
  overflow: hidden;
}

.bonus-fill {
  width: 100%;
  background: linear-gradient(180deg, #f3a63b, #ffbe63);
  transition: height 0.45s ease;
}

.bonus-item small {
  font-size: 11px;
  color: rgba(214, 224, 242, 0.74);
}

.bonus-item b {
  font-size: 11px;
  color: rgba(255, 255, 255, 0.9);
}

.autopilot {
  display: grid;
  gap: 12px;
}

.beast-hero {
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 14px;
  background:
    radial-gradient(120% 120% at 0% 0%, rgba(240, 92, 45, 0.16), transparent 58%),
    radial-gradient(110% 110% at 100% 100%, rgba(255, 195, 64, 0.15), transparent 64%),
    rgba(6, 12, 24, 0.68);
  padding: 12px;
  display: flex;
  align-items: stretch;
  justify-content: space-between;
  gap: 12px;
}

.beast-copy h3 {
  margin: 4px 0 0;
  font-size: 20px;
  color: #fff;
}

.beast-copy p {
  margin: 6px 0 0;
  color: rgba(234, 240, 250, 0.78);
}

.beast-pitch {
  margin-top: 10px;
  display: grid;
  gap: 6px;
}

.beast-pitch span {
  font-size: 12px;
  color: rgba(245, 249, 255, 0.87);
}

.beast-state {
  min-width: 230px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 12px;
  background: rgba(5, 10, 20, 0.56);
  padding: 10px;
  display: grid;
  align-content: space-between;
  gap: 8px;
}

.beast-state strong {
  font-size: 16px;
  color: #ffc672;
}

.beast-state.on strong {
  color: #54f5c6;
}

.beast-state small {
  color: rgba(213, 225, 245, 0.72);
  font-size: 12px;
}

.autopilot-head {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 12px;
}

.autopilot-head small {
  display: block;
  margin-top: 6px;
  font-size: 12px;
  color: rgba(202, 219, 244, 0.72);
}

.autopilot-controls {
  display: flex;
  align-items: end;
  gap: 8px;
  flex-wrap: wrap;
}

.field.mini {
  min-width: 160px;
}

.autopilot-summary {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.auto-card {
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  background: rgba(5, 10, 20, 0.52);
  padding: 10px 12px;
}

.auto-card span {
  font-size: 11px;
  color: rgba(215, 226, 244, 0.7);
}

.auto-card strong {
  display: block;
  margin-top: 6px;
  font-size: 19px;
  color: #fff;
}

.auto-card small {
  display: block;
  margin-top: 6px;
  font-size: 11px;
  color: rgba(204, 219, 241, 0.68);
}

.autopilot-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: 1fr 1fr;
}

.guard-grid {
  margin-top: 10px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.guard-item {
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 10px;
  background: rgba(5, 10, 20, 0.48);
  padding: 8px 10px;
}

.guard-item span {
  font-size: 11px;
  color: rgba(214, 226, 245, 0.72);
}

.guard-item strong {
  display: block;
  margin-top: 5px;
  font-size: 13px;
  color: #fff;
}

.cols-auto-zone {
  grid-template-columns: 1fr .8fr .8fr .6fr .9fr;
}

.cols-signal {
  grid-template-columns: 1fr .8fr .6fr .9fr;
}

.risk-ok {
  color: #4de1b0;
  text-transform: uppercase;
}

.risk-medium {
  color: #ffc76a;
  text-transform: uppercase;
}

.risk-high {
  color: #ff8f8f;
  text-transform: uppercase;
}

.promo-plan {
  margin-top: 10px;
}

.heatmap {
  margin-top: 10px;
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
}

.heat-cell {
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 10px;
  background: rgba(5, 10, 20, 0.52);
  padding: 8px;
}

.heat-cell span {
  font-size: 11px;
  color: rgba(214, 225, 243, 0.74);
}

.heat-cell b {
  display: block;
  margin-top: 4px;
  font-size: 12px;
  color: rgba(255, 255, 255, 0.95);
}

.heat-track {
  margin-top: 6px;
  height: 7px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  overflow: hidden;
}

.heat-fill {
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, #4f8cff, #24c8a4);
}

.scenario-list {
  margin-top: 10px;
  display: grid;
  gap: 10px;
}

.scenario-row {
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 11px;
  background: rgba(5, 10, 20, 0.5);
  padding: 10px;
  display: flex;
  justify-content: space-between;
  gap: 10px;
}

.scenario-row strong {
  font-size: 13px;
  color: #fff;
}

.scenario-row small {
  display: block;
  margin-top: 5px;
  font-size: 11px;
  color: rgba(211, 224, 245, 0.66);
}

.scenario-values {
  text-align: right;
}

.scenario-values b {
  display: block;
  font-size: 13px;
  color: #fff;
}

.scenario-values em {
  display: block;
  margin-top: 5px;
  font-style: normal;
  font-size: 11px;
  color: rgba(206, 220, 244, 0.68);
}

.exchange {
  display: grid;
  gap: 12px;
}

.exchange-head {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 12px;
}

.exchange-top-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.exchange-form {
  margin-top: 10px;
  display: grid;
  gap: 8px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.toggle-line {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: rgba(225, 236, 250, 0.84);
}

.toggle-line input {
  accent-color: #4f8cff;
}

.exchange-actions {
  margin-top: 10px;
  display: flex;
  justify-content: flex-end;
}

.cols-exchange {
  grid-template-columns: 1.3fr .8fr .7fr .6fr .8fr;
}

.compare-top {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 12px;
}

.compare-form {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}

.compare-form .input {
  min-width: 260px;
}

.clubs {
  margin-top: 12px;
  display: grid;
  gap: 10px;
  grid-template-columns: 1fr 1fr;
}

.club-card {
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  background: rgba(6, 11, 22, 0.56);
  padding: 10px 12px;
}

.club-card span {
  font-size: 11px;
  color: rgba(214, 225, 243, 0.68);
}

.club-card strong {
  display: block;
  margin-top: 5px;
  font-size: 15px;
  color: #fff;
}

.club-card small {
  display: block;
  margin-top: 5px;
  color: rgba(207, 220, 246, 0.62);
}

.battle-list {
  margin-top: 12px;
  display: grid;
  gap: 10px;
}

.battle-item {
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  background: rgba(6, 11, 22, 0.56);
  padding: 10px;
}

.battle-head {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 8px;
}

.battle-head span {
  font-size: 12px;
  color: rgba(228, 236, 249, 0.82);
}

.battle-head b {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.95);
}

.battle-bars {
  display: grid;
  gap: 8px;
}

.battle-bar {
  position: relative;
  height: 24px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.08);
  overflow: hidden;
  display: flex;
  align-items: center;
}

.fill {
  height: 100%;
}

.left-fill {
  background: linear-gradient(90deg, rgba(79, 140, 255, 0.86), rgba(120, 170, 255, 0.88));
}

.right-fill {
  background: linear-gradient(90deg, rgba(36, 200, 164, 0.84), rgba(101, 224, 198, 0.88));
}

.battle-bar em {
  position: absolute;
  right: 8px;
  top: 4px;
  font-style: normal;
  font-size: 11px;
  color: rgba(255, 255, 255, 0.92);
}

.up {
  color: #37d9a6;
}

.down {
  color: #ff9a9a;
}

.empty {
  margin-top: 8px;
  border: 1px dashed rgba(255, 255, 255, 0.2);
  border-radius: 11px;
  padding: 16px;
  text-align: center;
  color: rgba(224, 233, 246, 0.64);
  font-size: 12px;
}

.alert {
  border-radius: 12px;
  border: 1px solid rgba(255, 255, 255, 0.14);
  padding: 10px 12px;
  font-size: 12px;
}

.alert.danger {
  border-color: rgba(255, 88, 88, 0.35);
  background: rgba(255, 88, 88, 0.12);
  color: rgba(255, 255, 255, 0.94);
}

@keyframes floatIn {
  from {
    opacity: 0;
    transform: translateY(8px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 1440px) {
  .signal-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .growth-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .autopilot-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .heatmap { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .exchange-top-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .overview-dashboard { grid-template-columns: 1fr; }
}

@media (max-width: 1320px) {
  .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .signal-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .ledger-grid { grid-template-columns: 1fr; }
  .visual-grid { grid-template-columns: 1fr; }
  .overview-dashboard { grid-template-columns: 1fr; }
  .detail-grid { grid-template-columns: 1fr; }
  .ops-grid { grid-template-columns: 1fr 1fr; }
  .table-grid { grid-template-columns: 1fr; }
  .shift-grid { grid-template-columns: 1fr 1fr; }
  .insight-grid { grid-template-columns: 1fr; }
  .autopilot-grid { grid-template-columns: 1fr; }
  .beast-hero { flex-direction: column; }
  .exchange-form { grid-template-columns: 1fr; }
}

@media (max-width: 980px) {
  .hero { flex-direction: column; align-items: stretch; }
  .split-grid { grid-template-columns: 1fr; }
  .growth-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .signal-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .clubs { grid-template-columns: 1fr; }
  .autopilot-head { flex-direction: column; align-items: stretch; }
  .autopilot-controls { justify-content: flex-start; }
  .heatmap { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .exchange-head { flex-direction: column; align-items: stretch; }
}

@media (max-width: 680px) {
  .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .donut-wrap { flex-direction: column; align-items: flex-start; }
  .compare-form .input { min-width: 100%; width: 100%; }
  .ops-grid { grid-template-columns: 1fr; }
  .growth-grid { grid-template-columns: 1fr; }
  .signal-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .shift-grid { grid-template-columns: 1fr; }
  .autopilot-summary { grid-template-columns: 1fr; }
  .heatmap { grid-template-columns: 1fr; }
  .scenario-row { flex-direction: column; align-items: flex-start; }
  .scenario-values { text-align: left; }
  .guard-grid { grid-template-columns: 1fr; }
  .cols-signal { grid-template-columns: 1fr .8fr .6fr .9fr; }
  .exchange-top-grid { grid-template-columns: 1fr; }
  .cols-exchange { grid-template-columns: 1.3fr .9fr .8fr .6fr .8fr; }
}

@media (max-width: 520px) {
  .stats-grid { grid-template-columns: 1fr; }
  .signal-grid { grid-template-columns: 1fr; }
  .hero-right { flex-direction: column; align-items: stretch; }
  .field { min-width: unset; }
}
</style>


