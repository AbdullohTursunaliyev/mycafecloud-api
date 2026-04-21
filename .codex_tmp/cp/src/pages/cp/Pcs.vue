<template>
    <div class="page">
    <!-- Toolbar -->
    <div class="toolbar card-flat premium">
      <div class="title-wrap">
        <div class="h1">Zal • Kompyuterlar</div>
        <div class="muted sub">
          Kompyuterlar, sessiyalar va holatlarni boshqarish.
        </div>
      </div>

      <div class="tools">
        <div class="tool-group">
          <el-input v-model="q" placeholder="Qidirish PC..." style="width:220px" />
          <el-select v-model="zone" placeholder="Zona" style="width:170px">
            <el-option label="Barchasi" value="all" />
            <el-option v-for="z in zoneNames" :key="z" :label="z" :value="z" />
          </el-select>
        </div>

        <div class="tool-group actions">
          <el-button class="btn-premium" @click="reload" :loading="loading">Yangilash</el-button>
          <el-button v-if="canManagePcs" type="primary" @click="openCreate">
            + PC qo'shish
          </el-button>
        </div>
      </div>
    </div>

    <div class="spacer"></div>

    <!-- Summary chips -->
    <div class="chips">
      <div class="chip card-flat premium tone-green">
        <div class="chip-head">
          <span class="chip-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12h16"/></svg>
          </span>
          <div class="muted">Bo'sh</div>
        </div>
        <div class="val">{{ stats.online }}</div>
      </div>
      <div class="chip card-flat premium tone-blue">
        <div class="chip-head">
          <span class="chip-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="5" width="16" height="14" rx="2"/></svg>
          </span>
          <div class="muted">Band</div>
        </div>
        <div class="val">{{ stats.busy }}</div>
      </div>
      <div class="chip card-flat premium tone-amber">
        <div class="chip-head">
          <span class="chip-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
          </span>
          <div class="muted">Blok</div>
        </div>
        <div class="val">{{ stats.locked }}</div>
      </div>
      <div class="chip card-flat premium tone-purple">
        <div class="chip-head">
          <span class="chip-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/></svg>
          </span>
          <div class="muted">Jami</div>
        </div>
        <div class="val">{{ pcs.length }}</div>
      </div>
    </div>

    <div class="spacer"></div>

    <!-- Grid -->
    <div v-for="g in grouped" :key="g.zone" class="zone-block">
      <div class="zone-head card-flat premium">
        <div class="zone-title">
          <span class="zone-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8"/><path d="M12 16v4"/></svg>
          </span>
          {{ g.zone }}
        </div>
        <div class="zone-meta muted">PC: {{ g.pcs.length }}</div>
      </div>

      <div class="spacer"></div>

      <div class="grid">
        <PcTile
            v-for="pc in g.pcs"
            :key="pc.id"
            :pc="pc"
            @context="openMenu(pc, $event)"
            @start="onActionFromTile('start', pc)"
            @stop="onActionFromTile('stop', pc)"
            @topup="onActionFromTile('topup', pc)"
            @attach_package="onActionFromTile('attach_package', pc)"
        />
      </div>

      <div class="spacer"></div>
    </div>

    <PcContextMenu
        :open="menu.open"
        :x="menu.x"
        :y="menu.y"
        :pc="menu.pc"
        @action="onAction"
    />

    <TopupModal
        v-model="topupOpen"
        :pc="selectedPc"
        @confirm="onTopupConfirm"
    />

    <PackageModal
        v-model="pkgOpen"
        :pc="selectedPc"
        :packages="packages"
        @confirm="onPackageConfirm"
    />

    <AddPcModal
        v-model="create.open"
        :zones="zonesDb"
        :loading="create.loading"
        :error="create.err"
        :initial="{ zone_id: zonesDb?.[0]?.id ?? null }"
        @submit="createSaveFromModal"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import PcTile from '../../components/PcTile.vue'
import PcContextMenu from '../../components/PcContextMenu.vue'
import TopupModal from '../../components/cp/TopupModal.vue'
import PackageModal from '../../components/cp/PackageModal.vue'
import AddPcModal from '../../components/cp/AddPcModal.vue'
import { ElMessage } from 'element-plus'
import { cpApi } from '../../api/cp'

/** вњ… universal parser (zones/pcs har xil formatda kelsa ham ushlaydi) */
const pickRows = (resp) => {
  const d = resp?.data

  if (Array.isArray(d?.data)) return d.data              // {data:[...]}
  if (Array.isArray(d?.data?.data)) return d.data.data   // {data:{data:[...]}}
  if (Array.isArray(d?.data?.items)) return d.data.items // {data:{items:[...]}}
  if (Array.isArray(d)) return d                         // [...]
  if (Array.isArray(d?.items)) return d.items            // {items:[...]}
  if (Array.isArray(d?.result)) return d.result          // {result:[...]}
  return []
}

const role = ref(null)
const canManagePcs = computed(() => {
  if (!role.value) return true
  return role.value === 'admin' || role.value === 'owner'
})

const q = ref('')
const zone = ref('all')
const loading = ref(false)

const packages = ref([])

const pcs = ref([])
const zonesDb = ref([])

const mapPc = (x) => ({
  id: x.id ?? x._id,
  name: x.name || x.code,
  zone: x.zone || x.zone_name,
  ip: x.ip || x.ip_address,
  code: x.code,
  status: x.status || 'locked',
  session_left: x.session_left,
  client_balance: x.client_balance,
  zone_price_per_hour: x.zone_price_per_hour,
  client: x.client || (x.active_session?.client?.login || x.active_session?.client?.phone),
})

const zoneNames = computed(() => zonesDb.value.map(z => z.name))

const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  return pcs.value.filter(p => {
    const byZone = zone.value === 'all' ? true : p.zone === zone.value
    const byQ = !s ? true : (p.name?.toLowerCase().includes(s) || p.ip?.includes(s))
    return byZone && byQ
  })
})

const stats = computed(() => {
  const all = pcs.value
  return {
    online: all.filter(p => p.status === 'online').length,
    busy: all.filter(p => p.status === 'busy').length,
    locked: all.filter(p => p.status === 'locked').length,
  }
})

const grouped = computed(() => {
  const list = filtered.value
  const map = new Map()
  for (const pc of list) {
    const z = pc.zone || 'Bez zona'
    if (!map.has(z)) map.set(z, [])
    map.get(z).push(pc)
  }
  const order = zoneNames.value.length ? zoneNames.value : []
  const keys = Array.from(map.keys())
  keys.sort((a,b) => {
    const ia = order.indexOf(a); const ib = order.indexOf(b)
    if (ia === -1 && ib === -1) return a.localeCompare(b)
    if (ia === -1) return 1
    if (ib === -1) return -1
    return ia - ib
  })
  return keys.map(k => ({ zone:k, pcs: map.get(k) }))
})

/* Menu + existing modals */
const menu = reactive({ open:false, x:0, y:0, pc:null })
const topupOpen = ref(false)
const pkgOpen = ref(false)
const selectedPc = ref(null)

const openMenu = (pc, ev) => {
  menu.open = true
  menu.pc = pc
  const pad = 10
  const w = 220
  const h = 340
  menu.x = Math.min(ev.clientX, window.innerWidth - w - pad)
  menu.y = Math.min(ev.clientY, window.innerHeight - h - pad)
}
const closeMenu = () => { menu.open = false; menu.pc = null }

const onAction = (action) => {
  const pc = menu.pc
  closeMenu()
  if (!pc) return

  if (action === 'topup') {
    selectedPc.value = pc
    topupOpen.value = true
    return
  }
  if (action === 'attach_package') {
    selectedPc.value = pc
    pkgOpen.value = true
    return
  }
  ElMessage.info(`Action: ${action} (mock)`)
}
const onActionFromTile = (action, pc) => {
  menu.pc = pc
  onAction(action)
}

const onTopupConfirm = ({ amount }) => {
  const pc = selectedPc.value
  if (!pc) return
  pc.client_balance = Number(pc.client_balance || 0) + Number(amount || 0)
  ElMessage.success(`Balans +${Number(amount || 0).toLocaleString('uz-UZ')} UZS`)
}

const onPackageConfirm = ({ package: pkg }) => {
  const pc = selectedPc.value
  if (!pc || !pkg) return
  pc.active_package = pkg.name
  pc.client_balance = Number(pc.client_balance || 0) + Number(pkg.price || 0)
  ElMessage.success(`Paket: ${pkg.name}`)
}

/* Add PC */
const create = reactive({
  open: false,
  loading: false,
  err: '',
})

const openCreate = async () => {
  create.err = ''
  if (!zonesDb.value.length) await loadZones()
  create.open = true
}

const createSaveFromModal = async (payload) => {
  create.err = ''
  if (!payload?.code) { create.err = 'PC kodi kiriting'; return }
  if (!payload?.zone_id) { create.err = 'Zona tanlang'; return }

  create.loading = true
  try {
    await cpApi.createPc({
      code: payload.code,
      zone_id: payload.zone_id,
      ip_address: payload.ip_address,
      status: 'offline',
    })
    ElMessage.success('PC qo‘shildi')
    create.open = false
    await reload()
  } catch (e) {
    create.err = e?.response?.data?.message || 'PC qo‘shishda xatolik'
  } finally {
    create.loading = false
  }
}

/* API */
const loadMe = async () => {
  try {
    const r = await cpApi.me()
    const me = r?.data?.data || r?.data
    role.value = me?.role || me?.user?.role || null
  } catch (_) {}
}

const loadZones = async () => {
  const fn = cpApi.zoneList || cpApi.zonesList
  if (!fn) return

  const r = await fn({ per_page: 200 })
  const rows = pickRows(r)

  zonesDb.value = rows
      .filter(z => z && (z.id || z._id) && (z.name || z.title))
      .map(z => ({
        id: z.id ?? z._id,
        name: z.name ?? z.title,
        price_per_hour: z.price_per_hour ?? z.price ?? z.hour_price ?? null,
      }))
}

const loadPackages = async () => {
  try {
    const r = await cpApi.packagesList({ per_page: 200 })
    const rows = pickRows(r)
    packages.value = rows.map((p) => ({
      id: p.id ?? p._id,
      name: p.name ?? p.title,
      description: p.description ?? '',
      zone: p.zone ?? p.zone_name ?? p.zone_title ?? null,
      hours: p.hours ?? p.duration_hours ?? p.duration ?? 1,
      price: p.price ?? p.amount ?? 0,
      type: p.type ?? 'PC time',
    }))
  } catch (_) {
    packages.value = []
  }
}

const loadPcs = async () => {
  const params = {}
  if (zone.value !== 'all') params.zone = zone.value
  if (q.value?.trim()) params.search = q.value.trim()

  const r = await cpApi.pcs(params)
  const rows = pickRows(r)
  pcs.value = rows.map(mapPc)
}

const reload = async () => {
  loading.value = true
  try {
    await Promise.all([loadZones(), loadPcs(), loadPackages()])
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || 'Yuklashda xatolik')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  window.addEventListener('click', closeMenu)
  await loadMe()
  await reload()
})
</script>

<style scoped>
.page{padding-bottom:20px}
.toolbar{padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:14px; position:relative; overflow:hidden;}
.toolbar.premium{
  border:1px solid rgba(255,255,255,0.08);
  background:
    radial-gradient(120% 120% at 0% 0%, rgba(79,140,255,0.12), transparent 60%),
    linear-gradient(180deg, rgba(8,14,28,0.9), rgba(7,11,22,0.82));
  box-shadow:
    0 20px 34px rgba(0,0,0,0.32),
    inset 0 1px 0 rgba(255,255,255,0.05);
}
.toolbar:after{
  content:"";
  position:absolute;
  right:-120px; top:-120px;
  width:240px; height:240px; border-radius:50%;
  background: radial-gradient(circle, rgba(79,140,255,0.18), transparent 60%);
  pointer-events:none;
}
.h1{font-size:20px;font-weight:900;letter-spacing:.2px}
.title-wrap{display:flex;flex-direction:column}
.sub{margin-top:4px}
.tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.tool-group{
  display:flex;gap:8px;align-items:center;flex-wrap:wrap;
  padding:6px;border-radius:12px;
  background: rgba(255,255,255,0.04);
  border:1px solid rgba(255,255,255,0.08);
}
.tool-group.actions{
  background: rgba(79,140,255,0.08);
  border-color: rgba(79,140,255,0.25);
}
.tool-group :deep(.el-input__wrapper),
.tool-group :deep(.el-select__wrapper){
  background: rgba(5,10,20,0.7);
  border:1px solid rgba(255,255,255,0.08);
  box-shadow: none;
}
.tool-group :deep(.el-input__wrapper.is-focus),
.tool-group :deep(.el-select__wrapper.is-focus){
  border-color: rgba(99,162,255,0.55);
}
.chips{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.chip{padding:12px;position:relative;overflow:hidden;transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;--tone: rgba(79,140,255,0.75);}
.chip.premium{
  border:1px solid rgba(255,255,255,0.08);
  background: rgba(8,14,28,0.75);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);
}
.chip:after{
  content:"";
  position:absolute;left:10px;right:10px;bottom:6px;height:3px;border-radius:999px;
  background: linear-gradient(90deg, transparent, var(--tone), transparent);
  opacity:.9;
}
.chip:hover{transform:translateY(-2px);box-shadow:0 10px 18px rgba(0,0,0,0.25);}
.chip-head{display:flex;align-items:center;gap:10px}
.chip-icon{
  width:28px;height:28px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;
  background: rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color: rgba(210,228,255,0.9);
}
.chip-icon svg{width:16px;height:16px}
.tone-green{--tone: rgba(34,197,94,0.9);}
.tone-blue{--tone: rgba(59,130,246,0.9);}
.tone-amber{--tone: rgba(245,158,11,0.9);}
.tone-purple{--tone: rgba(139,92,246,0.9);}
.tone-green .chip-icon{border-color: rgba(34,197,94,0.4); color: rgba(74,222,128,0.95);}
.tone-blue .chip-icon{border-color: rgba(59,130,246,0.45); color: rgba(96,165,250,0.95);}
.tone-amber .chip-icon{border-color: rgba(245,158,11,0.45); color: rgba(251,191,36,0.95);}
.tone-purple .chip-icon{border-color: rgba(139,92,246,0.45); color: rgba(168,85,247,0.95);}
.val{font-size:22px;font-weight:900;margin-top:6px}
.grid{display:grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap:12px;}
.zone-block{margin-bottom:14px}
.zone-head{padding:12px 14px; display:flex; align-items:center; justify-content:space-between; position:relative; overflow:hidden;}
.zone-head.premium{
  border:1px solid rgba(255,255,255,0.08);
  background: rgba(8,14,28,0.75);
}
.zone-head:after{
  content:"";
  position:absolute;left:0;right:0;bottom:0;height:1px;
  background: linear-gradient(90deg, rgba(99,162,255,0.45), transparent 60%);
  opacity:.5;
}
.zone-title{font-weight:900;letter-spacing:.2px;display:flex;align-items:center;gap:8px}
.zone-icon{
  width:26px;height:26px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;
  background: rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color: rgba(210,228,255,0.9);
}
.zone-icon svg{width:15px;height:15px}
.btn-premium{
  border-radius:10px !important;
  background: linear-gradient(135deg, rgba(73,140,255,0.7), rgba(40,110,230,0.6)) !important;
  border-color: rgba(92,152,255,0.5) !important;
  color:#fff !important;
  font-weight:700;
}
@media (max-width:1200px){
  .grid{grid-template-columns: repeat(3, minmax(0, 1fr));}
  .chips{grid-template-columns: repeat(2, minmax(0, 1fr));}
}
@media (max-width:700px){
  .grid{grid-template-columns: 1fr;}
  .chips{grid-template-columns: 1fr;}
  .toolbar{flex-direction:column;align-items:flex-start}
  .tool-group{width:100%;justify-content:space-between}
}
</style>

