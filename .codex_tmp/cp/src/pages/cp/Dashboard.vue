<template>
  <div class="op-dashboard">
    <section class="top card-flat premium">
      <div>
        <h1>{{ t('dashboard.title') }}</h1>
        <p>{{ t('dashboard.subtitle') }}</p>
      </div>
      <div class="top-actions">
        <span class="stamp">{{ t('dashboard.updatedAt', { value: refreshedAtLabel }) }}</span>
        <el-button class="btn-premium" :loading="loading" @click="refreshAll">{{ t('common.refresh') }}</el-button>
      </div>
    </section>

    <section class="kpi-grid">
      <article class="kpi card-flat premium tone-green">
        <header><span class="kpi-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 13l5 5 11-11"/>
          </svg>
        </span>{{ t('dashboard.shiftStatus') }}</header>
        <strong :class="shiftOpen ? 'ok' : 'warn'">{{ shiftOpen ? t('dashboard.shiftOpen') : t('dashboard.shiftClosed') }}</strong>
        <div class="meta">{{ shiftOpen ? t('dashboard.shiftId', { id: shiftId }) : t('dashboard.shiftNeedOpen') }}</div>
        <div class="meta">{{ shiftOpen ? t('dashboard.shiftOpenedAt', { value: fmtDate(shiftOpenedAt) }) : '-' }}</div>
      </article>

      <article class="kpi card-flat premium tone-blue">
        <header><span class="kpi-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 12h18"/><path d="M7 7h10"/><path d="M7 17h10"/>
          </svg>
        </span>{{ t('dashboard.cashflow') }}</header>
        <strong>{{ money(shiftGross) }} UZS</strong>
        <div class="meta">{{ t('dashboard.cashCard', { cash: money(shiftCash), card: money(shiftCard) }) }}</div>
        <div class="meta">{{ t('dashboard.bonusOps', { bonus: money(shiftBonus), ops: shiftOps }) }}</div>
      </article>

      <article class="kpi card-flat premium tone-amber">
        <header><span class="kpi-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 3v18"/><path d="M7 8h10"/><path d="M7 16h10"/>
          </svg>
        </span>{{ t('dashboard.expectedCash') }}</header>
        <strong>{{ money(expectedCashNow) }} UZS</strong>
        <div class="meta">{{ t('dashboard.expenses', { value: money(shiftExpenses) }) }}</div>
        <div class="meta">{{ t('dashboard.closeCompare') }}</div>
      </article>

      <article class="kpi card-flat premium tone-cyan">
        <header><span class="kpi-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8"/><path d="M12 16v4"/>
          </svg>
        </span>{{ t('dashboard.pcState') }}</header>
        <strong>{{ t('dashboard.pcsCount', { value: pcTotal }) }}</strong>
        <div class="meta">{{ t('dashboard.pcLine1', { busy: pcBusy, reserved: pcReserved, online: pcOnline }) }}</div>
        <div class="meta">{{ t('dashboard.pcLine2', { offline: pcOffline, locked: pcLocked }) }}</div>
      </article>

      <article class="kpi card-flat premium tone-purple">
        <header><span class="kpi-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/>
          </svg>
        </span>{{ t('dashboard.activeSessions') }}</header>
        <strong>{{ t('dashboard.pcsCount', { value: sessions.length }) }}</strong>
        <div class="meta">{{ t('dashboard.avgDuration', { value: avgSessionMin }) }}</div>
        <div class="meta">{{ t('dashboard.maxDuration', { value: maxSessionMin }) }}</div>
      </article>

      <article class="kpi card-flat premium tone-pink">
        <header><span class="kpi-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4"/><path d="M16 3v4"/>
          </svg>
        </span>{{ t('dashboard.bookingsQueue') }}</header>
        <strong>{{ t('dashboard.pcsCount', { value: activeBookings.length }) }}</strong>
        <div class="meta">{{ t('dashboard.overdue', { value: bookingOverdue }) }}</div>
        <div class="meta">{{ t('dashboard.soon30', { value: bookingSoon }) }}</div>
      </article>
    </section>

    <section class="actions card-flat premium">
      <h2>{{ t('dashboard.quickActions') }}</h2>
      <div class="action-grid">
        <button class="action-btn premium" @click="go('/cp/layout')">
          <span class="action-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="3" width="8" height="8"/><rect x="13" y="3" width="8" height="8"/><rect x="3" y="13" width="18" height="8"/>
            </svg>
          </span>
          {{ t('dashboard.actionLayout') }}
        </button>
        <button class="action-btn premium" @click="go('/cp/clients')">
          <span class="action-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="9" cy="8" r="3"/><path d="M2 20c1.5-3 4-5 7-5"/><circle cx="17" cy="9" r="3"/><path d="M14 20c.6-2 2-3.5 4-4"/>
            </svg>
          </span>
          {{ t('dashboard.actionClients') }}
        </button>
        <button class="action-btn premium" @click="go('/cp/bookings')">
          <span class="action-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M7 11h10"/>
            </svg>
          </span>
          {{ t('dashboard.actionBookings') }}
        </button>
        <button class="action-btn premium" @click="go('/cp/shift')">
          <span class="action-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/><path d="M7 6v12"/>
            </svg>
          </span>
          {{ t('dashboard.actionShift') }}
        </button>
      </div>
    </section>

    <section class="split">
      <article class="panel card-flat premium">
        <div class="panel-head">
          <h2>{{ t('dashboard.activeSessions') }}</h2>
          <el-button class="btn-soft" text @click="go('/cp/layout')">{{ t('dashboard.goLayout') }}</el-button>
        </div>
        <div v-if="sessions.length" class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>{{ t('bookings.pc') }}</th>
                <th>{{ t('bookings.client') }}</th>
                <th>{{ t('dashboard.sessionStart') }}</th>
                <th>{{ t('dashboard.duration') }}</th>
                <th>{{ t('dashboard.tariff') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in sessionsTop" :key="s.id">
                <td>{{ s.pc?.code || '-' }}</td>
                <td>{{ s.client?.login || s.client?.account_id || s.client?.phone || '-' }}</td>
                <td>{{ fmtDate(s.started_at) }}</td>
                <td>{{ sessionMinutes(s.started_at) }} {{ t('common.minutesShort') }}</td>
                <td>{{ s.tariff?.name || '-' }}</td>
                <td>
                  <el-button size="small" type="danger" plain @click="stopSessionQuick(s.id)">{{ t('dashboard.finish') }}</el-button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="empty">{{ t('dashboard.noActiveSessions') }}</div>
      </article>

      <article class="panel card-flat premium">
        <div class="panel-head">
          <h2>{{ t('dashboard.activeBookings') }}</h2>
          <el-button class="btn-soft" text @click="go('/cp/bookings')">{{ t('common.all') }}</el-button>
        </div>
        <div v-if="activeBookings.length" class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>{{ t('bookings.pc') }}</th>
                <th>{{ t('bookings.client') }}</th>
                <th>{{ t('dashboard.arrival') }}</th>
                <th>{{ t('dashboard.state') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in bookingsTop" :key="b.id">
                <td>{{ b.pc?.code || `PC #${b.pc_id}` }}</td>
                <td>{{ b.client?.login || b.client?.account_id || b.client?.phone || `#${b.client_id}` }}</td>
                <td>{{ fmtDate(b.start_at) }}</td>
                <td>
                  <span class="badge" :class="bookingStateClass(b)">{{ bookingStateLabel(b) }}</span>
                </td>
                <td>
                  <el-button size="small" plain type="danger" @click="cancelBookingQuick(b.id)">{{ t('dashboard.cancel') }}</el-button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="empty">{{ t('dashboard.noActiveBookings') }}</div>
      </article>
    </section>

    <section class="panel card-flat premium">
      <div class="panel-head">
        <h2>{{ t('dashboard.signalsLogs') }}</h2>
        <el-button class="btn-soft" text @click="go('/cp/logs')">{{ t('dashboard.logsSection') }}</el-button>
      </div>

      <div class="signals">
        <div class="sig danger">{{ t('dashboard.failedCommand', { value: failedCommands }) }}</div>
        <div class="sig warn">{{ t('dashboard.offlinePc', { value: pcOffline }) }}</div>
        <div class="sig info">{{ t('dashboard.pendingCommand', { value: pendingCommands }) }}</div>
      </div>

      <div v-if="importantLogs.length" class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('dashboard.time') }}</th>
              <th>{{ t('dashboard.type') }}</th>
              <th>{{ t('dashboard.action') }}</th>
              <th>{{ t('bookings.pc') }}</th>
              <th>{{ t('dashboard.status') }}</th>
              <th>{{ t('dashboard.note') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in importantLogs" :key="l.id">
              <td>{{ fmtDate(l.happened_at) }}</td>
              <td>{{ l.type }}</td>
              <td>{{ l.action }}</td>
              <td>{{ l.pc?.code || '-' }}</td>
              <td>{{ l.status || '-' }}</td>
              <td>{{ l.note || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">{{ t('dashboard.noImportantLogs') }}</div>
    </section>

    <div v-if="error" class="err card-flat">{{ error }}</div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { cpApi } from '../../api/cp'
import { useI18n } from '../../i18n'

const router = useRouter()
const { t, locale } = useI18n()

const loading = ref(false)
const error = ref('')
const refreshedAt = ref(null)
const tickNow = ref(Date.now())

const shiftSummary = ref(null)
const pcs = ref([])
const sessions = ref([])
const activeBookings = ref([])
const logs = ref([])

let refreshTimer = null
let tickTimer = null

const localeCode = computed(() => {
  if (locale.value === 'ru') return 'ru-RU'
  if (locale.value === 'en') return 'en-US'
  return 'uz-UZ'
})

function go(path) {
  router.push(path)
}

function asArray(v) {
  return Array.isArray(v) ? v : []
}

function fmtDate(v) {
  if (!v) return '-'
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return String(v)
  return d.toLocaleString(localeCode.value)
}

function money(v) {
  return new Intl.NumberFormat(localeCode.value).format(Math.round(Number(v || 0)))
}

function sessionMinutes(startedAt) {
  if (!startedAt) return 0
  const d = new Date(startedAt)
  if (Number.isNaN(d.getTime())) return 0
  return Math.max(0, Math.floor((tickNow.value - d.getTime()) / 60000))
}

const refreshedAtLabel = computed(() => (refreshedAt.value ? fmtDate(refreshedAt.value) : '-'))

const shift = computed(() => shiftSummary.value?.shift || null)
const shiftTotals = computed(() => shiftSummary.value?.totals || {})
const shiftExpected = computed(() => shiftSummary.value?.expected || {})

const shiftOpen = computed(() => !!shift.value)
const shiftId = computed(() => shift.value?.id || '-')
const shiftOpenedAt = computed(() => shift.value?.opened_at || null)
const shiftCash = computed(() => Number(shiftTotals.value?.cash || 0))
const shiftCard = computed(() => Number(shiftTotals.value?.card || 0))
const shiftGross = computed(() => Number(shiftTotals.value?.gross || 0))
const shiftBonus = computed(() => Number(shiftTotals.value?.bonus || 0))
const shiftOps = computed(() => Number(shiftTotals.value?.ops_count || 0))
const shiftExpenses = computed(() => Number(shiftTotals.value?.expenses || 0))
const expectedCashNow = computed(() => Number(shiftExpected.value?.cash_now || 0))

const pcTotal = computed(() => pcs.value.length)
const pcBusy = computed(() => pcs.value.filter((p) => p.status === 'busy').length)
const pcReserved = computed(() => pcs.value.filter((p) => p.status === 'reserved').length)
const pcOffline = computed(() => pcs.value.filter((p) => p.status === 'offline').length)
const pcLocked = computed(() => pcs.value.filter((p) => p.status === 'locked').length)
const pcOnline = computed(() => pcs.value.filter((p) => ['online', 'busy', 'reserved'].includes(p.status)).length)

const avgSessionMin = computed(() => {
  if (!sessions.value.length) return 0
  const sum = sessions.value.reduce((acc, s) => acc + sessionMinutes(s.started_at), 0)
  return Math.round(sum / sessions.value.length)
})

const maxSessionMin = computed(() => {
  if (!sessions.value.length) return 0
  return Math.max(...sessions.value.map((s) => sessionMinutes(s.started_at)))
})

const sessionsTop = computed(() => sessions.value.slice(0, 8))

const bookingOverdue = computed(() => {
  const now = Date.now()
  return activeBookings.value.filter((b) => {
    const tms = new Date(b.start_at).getTime()
    return !Number.isNaN(tms) && tms <= now
  }).length
})

const bookingSoon = computed(() => {
  const now = Date.now()
  const limit = now + 30 * 60000
  return activeBookings.value.filter((b) => {
    const tms = new Date(b.start_at).getTime()
    return !Number.isNaN(tms) && tms > now && tms <= limit
  }).length
})

const bookingsTop = computed(() =>
  activeBookings.value
    .slice()
    .sort((a, b) => new Date(a.start_at).getTime() - new Date(b.start_at).getTime())
    .slice(0, 8),
)

const failedCommands = computed(() =>
  logs.value.filter((l) => l.type === 'pc_command' && ['failed', 'error', 'rejected'].includes(String(l.status || '').toLowerCase())).length,
)

const pendingCommands = computed(() =>
  logs.value.filter((l) => l.type === 'pc_command' && ['pending', 'sent'].includes(String(l.status || '').toLowerCase())).length,
)

const importantLogs = computed(() =>
  logs.value
    .filter((l) => {
      const status = String(l.status || '').toLowerCase()
      if (l.type === 'pc_command' && ['failed', 'error', 'rejected', 'pending', 'sent'].includes(status)) return true
      if (l.type === 'session' && l.action === 'ended') return true
      return false
    })
    .slice(0, 12),
)

function bookingStateLabel(b) {
  const tms = new Date(b.start_at).getTime()
  if (Number.isNaN(tms)) return t('dashboard.bookingStateActive')
  return tms <= Date.now() ? t('dashboard.bookingStateWaiting') : t('dashboard.bookingStatePlanned')
}

function bookingStateClass(b) {
  const tms = new Date(b.start_at).getTime()
  if (Number.isNaN(tms)) return 'state-active'
  return tms <= Date.now() ? 'state-warn' : 'state-active'
}

async function refreshAll() {
  loading.value = true
  error.value = ''
  try {
    const today = new Date()
    const from = new Date(today.getFullYear(), today.getMonth(), today.getDate()).toISOString().slice(0, 10)
    const to = from

    const [shiftResp, pcsResp, sessionsResp, bookingsResp, logsResp] = await Promise.all([
      cpApi.currentShiftSummary().catch(() => ({ data: { data: null } })),
      cpApi.pcs({}).catch(() => ({ data: { data: [] } })),
      cpApi.activeSessions().catch(() => ({ data: { data: [] } })),
      cpApi.bookingsList({ status: 'active', per_page: 120 }).catch(() => ({ data: { data: { data: [] } } })),
      cpApi.logsList({ from, to, per_page: 120 }).catch(() => ({ data: { data: [] } })),
    ])

    shiftSummary.value = shiftResp?.data?.data || null
    pcs.value = asArray(pcsResp?.data?.data)
    sessions.value = asArray(sessionsResp?.data?.data)

    const bookingsPayload = bookingsResp?.data?.data
    activeBookings.value = Array.isArray(bookingsPayload?.data)
      ? bookingsPayload.data
      : asArray(bookingsPayload)

    logs.value = asArray(logsResp?.data?.data)
    refreshedAt.value = new Date().toISOString()
  } catch (e) {
    error.value = e?.response?.data?.message || t('dashboard.dataLoadError')
  } finally {
    loading.value = false
  }
}

async function stopSessionQuick(sessionId) {
  try {
    await ElMessageBox.confirm(t('dashboard.stopSessionConfirm'), t('dashboard.confirm'), {
      type: 'warning',
      confirmButtonText: t('common.yes'),
      cancelButtonText: t('common.no'),
    })
  } catch (_) {
    return
  }

  try {
    await cpApi.stopSession(sessionId)
    await refreshAll()
  } catch (e) {
    error.value = e?.response?.data?.message || t('dashboard.stopSessionError')
  }
}

async function cancelBookingQuick(bookingId) {
  try {
    await ElMessageBox.confirm(t('dashboard.cancelBookingConfirm'), t('dashboard.confirm'), {
      type: 'warning',
      confirmButtonText: t('common.yes'),
      cancelButtonText: t('common.no'),
    })
  } catch (_) {
    return
  }

  try {
    await cpApi.bookingCancel(bookingId)
    await refreshAll()
  } catch (e) {
    error.value = e?.response?.data?.message || t('dashboard.cancelBookingError')
  }
}

onMounted(async () => {
  await refreshAll()
  refreshTimer = window.setInterval(refreshAll, 15000)
  tickTimer = window.setInterval(() => {
    tickNow.value = Date.now()
  }, 1000)
})

onBeforeUnmount(() => {
  if (refreshTimer) window.clearInterval(refreshTimer)
  if (tickTimer) window.clearInterval(tickTimer)
})
</script>

<style scoped>
.op-dashboard {
  display: grid;
  gap: 12px;
}

.card-flat.premium {
  border: 1px solid rgba(255, 255, 255, 0.08);
  background:
    radial-gradient(120% 120% at 0% 0%, rgba(79, 140, 255, 0.12), transparent 60%),
    linear-gradient(180deg, rgba(8, 14, 28, 0.9), rgba(7, 11, 22, 0.82));
  box-shadow:
    0 20px 34px rgba(0, 0, 0, 0.32),
    inset 0 1px 0 rgba(255, 255, 255, 0.05);
}

.top {
  padding: 14px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.top h1 {
  margin: 0;
  font-size: 22px;
}

.top p {
  margin: 5px 0 0;
  opacity: 0.72;
  font-size: 12px;
}

.top-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.stamp {
  font-size: 12px;
  opacity: 0.75;
}

.btn-premium {
  border-radius: 10px !important;
  background: linear-gradient(135deg, rgba(73, 140, 255, 0.7), rgba(40, 110, 230, 0.6)) !important;
  border-color: rgba(92, 152, 255, 0.5) !important;
  color: #fff !important;
  font-weight: 700;
}

.kpi-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.kpi {
  padding: 12px;
  display: grid;
  gap: 6px;
}

.kpi header {
  font-size: 12px;
  opacity: 0.75;
  display: flex;
  align-items: center;
  gap: 8px;
}

.kpi strong {
  font-size: 24px;
  line-height: 1.15;
}

.kpi strong.ok {
  color: rgba(34, 197, 94, 0.95);
}

.kpi strong.warn {
  color: rgba(251, 191, 36, 0.95);
}

.meta {
  font-size: 12px;
  opacity: 0.78;
}

.kpi-icon {
  width: 26px;
  height: 26px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: rgba(210, 228, 255, 0.9);
  flex: 0 0 auto;
}

.kpi-icon svg {
  width: 16px;
  height: 16px;
}

.tone-green .kpi-icon { border-color: rgba(34, 197, 94, 0.4); color: rgba(74, 222, 128, 0.95); }
.tone-blue .kpi-icon { border-color: rgba(59, 130, 246, 0.45); color: rgba(96, 165, 250, 0.95); }
.tone-amber .kpi-icon { border-color: rgba(245, 158, 11, 0.45); color: rgba(251, 191, 36, 0.95); }
.tone-cyan .kpi-icon { border-color: rgba(34, 211, 238, 0.45); color: rgba(34, 211, 238, 0.95); }
.tone-purple .kpi-icon { border-color: rgba(139, 92, 246, 0.45); color: rgba(168, 85, 247, 0.95); }
.tone-pink .kpi-icon { border-color: rgba(236, 72, 153, 0.45); color: rgba(244, 114, 182, 0.95); }

.actions {
  padding: 14px;
}

.actions h2 {
  margin: 0;
  font-size: 15px;
}

.action-grid {
  margin-top: 10px;
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.action-btn {
  border: 1px solid var(--stroke);
  border-radius: 10px;
  padding: 10px 12px;
  color: var(--text);
  background: rgba(2, 6, 23, 0.34);
  cursor: pointer;
  text-align: left;
}

.action-btn.premium {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font-weight: 700;
  border-radius: 12px;
  padding: 12px 14px;
  background: rgba(8, 14, 28, 0.7);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.05),
    0 12px 22px rgba(0, 0, 0, 0.28);
}

.action-btn.premium strong {
  font-size: 13px;
}

.action-icon {
  width: 28px;
  height: 28px;
  border-radius: 9px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: rgba(210, 228, 255, 0.9);
}

.action-icon svg {
  width: 16px;
  height: 16px;
}

.action-btn:hover {
  border-color: rgba(59, 130, 246, 0.42);
  background: rgba(59, 130, 246, 0.14);
}

.action-btn.premium:hover {
  border-color: rgba(120, 170, 255, 0.55);
  background: rgba(59, 130, 246, 0.18);
  transform: translateY(-1px);
}

.split {
  display: grid;
  gap: 12px;
  grid-template-columns: 1.15fr 1fr;
}

.panel {
  padding: 14px;
}

.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.panel h2 {
  margin: 0;
  font-size: 15px;
}

.btn-soft {
  border-radius: 10px !important;
  background: rgba(255, 255, 255, 0.06) !important;
  border: 1px solid rgba(255, 255, 255, 0.12) !important;
  color: rgba(226, 236, 248, 0.9) !important;
  font-weight: 600;
}

.table-wrap {
  margin-top: 10px;
  overflow: auto;
}

.table {
  width: 100%;
  min-width: 650px;
  border-collapse: collapse;
}

.table th,
.table td {
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  padding: 9px 8px;
  text-align: left;
  font-size: 12px;
}

.table thead th {
  border-top: none;
  font-size: 11px;
  text-transform: uppercase;
  opacity: 0.72;
}

.table tbody tr:hover {
  background: rgba(59, 130, 246, 0.08);
}

.empty {
  margin-top: 10px;
  border: 1px dashed var(--stroke);
  border-radius: 10px;
  padding: 16px;
  text-align: center;
  opacity: 0.75;
  font-size: 12px;
}

.signals {
  margin-top: 10px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.sig {
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  padding: 7px 12px;
  font-size: 12px;
  font-weight: 600;
}

.sig.danger {
  border-color: rgba(239, 68, 68, 0.4);
  background: rgba(239, 68, 68, 0.13);
}

.sig.warn {
  border-color: rgba(245, 158, 11, 0.42);
  background: rgba(245, 158, 11, 0.13);
}

.sig.info {
  border-color: rgba(59, 130, 246, 0.4);
  background: rgba(59, 130, 246, 0.13);
}

.badge {
  display: inline-block;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.14);
  padding: 2px 8px;
  font-size: 11px;
}

.badge.state-active {
  border-color: rgba(34, 197, 94, 0.45);
  background: rgba(34, 197, 94, 0.14);
}

.badge.state-warn {
  border-color: rgba(245, 158, 11, 0.45);
  background: rgba(245, 158, 11, 0.14);
}

.err {
  padding: 10px 12px;
  font-size: 12px;
  color: rgba(248, 113, 113, 0.95);
}

@media (max-width: 1280px) {
  .kpi-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .action-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .split {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 760px) {
  .kpi-grid {
    grid-template-columns: 1fr;
  }

  .action-grid {
    grid-template-columns: 1fr;
  }
}
</style>

