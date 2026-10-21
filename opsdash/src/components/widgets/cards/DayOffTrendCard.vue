<template>
  <div class="card dayoff-card" :style="cardStyle">
    <div class="dayoff-card__header" v-if="showHeader">
      <span>{{ titleText }}</span>
    </div>
    <div
      v-if="tiles.length"
      ref="heatmapEl"
      class="dayoff-heatmap"
      :class="{
        'dayoff-heatmap--row': !stackTiles,
        'dayoff-heatmap--stacked': stackTiles,
      }"
      :style="{ '--dayoff-tile-count': String(Math.max(1, tiles.length)) }"
    >
      <div
        v-for="entry in tiles"
        :key="entry.offset"
        class="dayoff-tile"
        :class="{ 'dayoff-tile--current': entry.offset === 0 }"
        :style="tileStyle(entry)"
        :title="`${entry.label} · ${shareLabel(entry.share)}`"
      >
        <div class="dayoff-tile__label">{{ entry.label }}</div>
        <div class="dayoff-tile__value">{{ shareLabel(entry.share) }}</div>
        <div class="dayoff-tile__meta">
          {{ entry.daysOff }} off · {{ entry.daysWorked }} on
        </div>
      </div>
    </div>
    <div class="hint" v-else>No day-off history for this range.</div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { formatDateRange, parseDateKey, getWeekNumber } from '../../../services/dateTime'

type DayOffTrendEntry = {
  offset: number
  label: string
  from: string
  to: string
  totalDays: number
  daysOff: number
  daysWorked: number
}

type LabelMode = 'date' | 'period' | 'offset'
type InterpretationMode = 'more_off_positive' | 'more_off_warning'
type DayOffTrendTile = DayOffTrendEntry & { share: number }

const props = defineProps<{
  trend?: DayOffTrendEntry[]
  unit?: string
  lookback?: number
  title?: string
  cardBg?: string | null
  toneLowColor?: string | null
  toneHighColor?: string | null
  showHeader?: boolean
  labelMode?: LabelMode
  reverseOrder?: boolean
  interpretation?: InterpretationMode | string | null
}>()

const historyUnit = computed(() => (props.unit === 'mo' ? 'mo' : 'wk'))
const currentUnitLabel = computed(() => (props.unit === 'mo' ? 'This month' : 'This week'))
const labelMode = computed<LabelMode>(() => {
  const mode = (props.labelMode || 'period').toString().toLowerCase()
  if (mode === 'date' || mode === 'offset') return mode as LabelMode
  return 'period'
})
const interpretation = computed<InterpretationMode>(() => {
  const raw = String(props.interpretation ?? '').toLowerCase()
  return raw === 'more_off_warning' ? 'more_off_warning' : 'more_off_positive'
})
const historyCount = computed(() => {
  const configured = typeof props.lookback === 'number' ? props.lookback : null
  if (configured != null) {
    return Math.max(0, Math.min(6, Math.floor(configured)))
  }
  const historyEntries = (props.trend ?? []).filter((entry) => Number(entry?.offset ?? 0) > 0)
  return Math.max(0, Math.min(6, historyEntries.length))
})

const normalized = computed<DayOffTrendEntry[]>(() => {
  const raw = Array.isArray(props.trend) ? props.trend : []
  const byOffset = new Map<number, DayOffTrendEntry>()
  raw.forEach((entry) => {
    const offset = Number(entry?.offset ?? 0) || 0
    byOffset.set(offset, {
      offset,
      label: entry?.label ?? '',
      from: entry?.from ?? '',
      to: entry?.to ?? '',
      totalDays: Number(entry?.totalDays ?? entry?.total_days ?? 0) || 0,
      daysOff: Number(entry?.daysOff ?? entry?.days_off ?? 0) || 0,
      daysWorked: Number(entry?.daysWorked ?? entry?.days_worked ?? 0) || 0,
    })
  })
  const unit = historyUnit.value
  const nowLabel = currentUnitLabel.value
  const entries: DayOffTrendEntry[] = []
  const current = byOffset.get(0) ?? {
    offset: 0,
    label: nowLabel,
    from: '',
    to: '',
    totalDays: 0,
    daysOff: 0,
    daysWorked: 0,
  }
  entries.push(current)
  const lookback = historyCount.value
  for (let offset = 1; offset <= lookback; offset += 1) {
    const resolved = byOffset.get(offset) ?? {
      offset,
      label: `-${offset} ${unit}`,
      from: '',
      to: '',
      totalDays: 0,
      daysOff: 0,
      daysWorked: 0,
    }
    entries.push(resolved)
  }
  return entries
})

const tiles = computed<DayOffTrendTile[]>(() => {
  const base = normalized.value.map((entry) => {
    const total = Math.max(0, Number(entry.totalDays) || 0)
    const daysOff = Math.max(0, Math.min(total, Number(entry.daysOff) || 0))
    const share = total > 0 ? daysOff / total : 0
    return { ...entry, label: formatLabel(entry), share }
  })
  if (props.reverseOrder === true) {
    return base
  }
  return base.slice().reverse()
})

const titleText = computed(() => props.title || 'Days off trend')
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const showHeader = computed(() => props.showHeader !== false)
const heatmapEl = ref<HTMLElement | null>(null)
const heatmapWidth = ref(0)

const toneRange = computed(() => {
  const low = normalizeColor(props.toneLowColor) || '#dc2626'
  const high = normalizeColor(props.toneHighColor) || '#16a34a'
  return { low, high }
})

const stackTiles = computed(() => {
  const width = Number(heatmapWidth.value) || 0
  if (width <= 0) return false
  const minInlineTileWidth = 124
  return width < tiles.value.length * minInlineTileWidth
})

let ro: ResizeObserver | null = null

function updateHeatmapWidth() {
  heatmapWidth.value = heatmapEl.value?.clientWidth || 0
}

onMounted(async () => {
  await nextTick()
  updateHeatmapWidth()
  if (typeof ResizeObserver !== 'function' || !heatmapEl.value) return
  ro = new ResizeObserver(() => updateHeatmapWidth())
  ro.observe(heatmapEl.value)
})

onBeforeUnmount(() => {
  ro?.disconnect()
  ro = null
})

function shareLabel(value: number) {
  const pct = Math.max(0, Math.min(1, value))
  return `${Math.round(pct * 100)}% off`
}

function tileStyle(entry: DayOffTrendTile) {
  const pct = clamp(Number(entry.share) || 0, 0, 1)
  const ratio = interpretation.value === 'more_off_warning' ? 1 - pct : pct
  const background = mixColor(toneRange.value.low, toneRange.value.high, ratio)
  const style = makeTone(background)
  return {
    '--tile-bg': style.background,
    '--tile-fg': style.color,
    background: style.background,
    color: style.color,
  }
}

function parseDate(value?: string) {
  if (!value) return null
  return parseDateKey(value)
}

function formatRange(from?: string, to?: string) {
  if (!from || !to) return ''
  return formatDateRange(from, to, { day: '2-digit', month: '2-digit' })
}

function fallbackLabel(offset: number, label?: string) {
  if (label) return label
  if (offset === 0) return currentUnitLabel.value
  return `-${offset} ${historyUnit.value}`
}

function formatPeriodLabel(entry: DayOffTrendEntry) {
  const baseDate = parseDate(entry.from) || parseDate(entry.to)
  if (!baseDate) {
    return fallbackLabel(entry.offset, entry.label)
  }
  if (historyUnit.value === 'mo') {
    return `MONTH ${baseDate.getUTCMonth() + 1}`
  }
  return `WEEK ${getWeekNumber(baseDate)}`
}

function formatLabel(entry: DayOffTrendEntry) {
  const offset = Number(entry.offset ?? 0) || 0
  if (labelMode.value === 'offset') {
    return offset === 0 ? 'Current' : `-${offset}`
  }
  if (labelMode.value === 'date') {
    return formatRange(entry.from, entry.to) || fallbackLabel(offset, entry.label)
  }
  return formatPeriodLabel(entry)
}

function normalizeColor(value?: string | null): string | null {
  if (!value || typeof value !== 'string') return null
  const hex = value.trim()
  if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex)) return hex
  return null
}

function mixColor(a: string, b: string, ratio = 0.5): string {
  const pa = parseColor(a)
  const pb = parseColor(b)
  if (!pa || !pb) return a
  const t = clamp(ratio, 0, 1)
  const r = clamp(Math.round(pa.r + (pb.r - pa.r) * t), 0, 255)
  const g = clamp(Math.round(pa.g + (pb.g - pa.g) * t), 0, 255)
  const bl = clamp(Math.round(pa.b + (pb.b - pa.b) * t), 0, 255)
  return `rgb(${r}, ${g}, ${bl})`
}

function parseColor(value: string): { r: number; g: number; b: number } | null {
  const hex = value.trim()
  const rgbMatch = hex.match(/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i)
  if (rgbMatch) {
    return {
      r: clamp(Number(rgbMatch[1]), 0, 255),
      g: clamp(Number(rgbMatch[2]), 0, 255),
      b: clamp(Number(rgbMatch[3]), 0, 255),
    }
  }
  const clean = hex.replace('#', '')
  if (clean.length === 3) {
    const [r, g, b] = clean.split('').map((v) => parseInt(v.repeat(2), 16))
    return { r, g, b }
  }
  if (clean.length === 6) {
    const r = parseInt(clean.slice(0, 2), 16)
    const g = parseInt(clean.slice(2, 4), 16)
    const b = parseInt(clean.slice(4, 6), 16)
    return { r, g, b }
  }
  return null
}

function luminance(hex: string): number {
  const p = parseColor(hex)
  if (!p) return 0
  return (0.2126 * p.r + 0.7152 * p.g + 0.0722 * p.b) / 255
}

function makeTone(bg: string): { background: string; color: string } {
  const text = luminance(bg) > 0.6 ? '#0f172a' : '#fff'
  return { background: bg, color: text }
}

function clamp(v: number, min: number, max: number) {
  return Math.min(max, Math.max(min, v))
}
</script>

<style scoped>
.dayoff-card{
  display:flex;
  flex-direction:column;
  gap:var(--widget-gap, 10px);
  container-type:inline-size;
}
.dayoff-card__header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  font-weight:600;
  color:var(--fg);
  font-size: var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
}
.dayoff-heatmap{
  display:grid;
  gap:var(--widget-gap, 8px);
}
.dayoff-heatmap--row{
  grid-template-columns:repeat(var(--dayoff-tile-count, 1), minmax(0, 1fr));
}
.dayoff-heatmap--stacked{
  grid-template-columns:minmax(0, 1fr);
}
.dayoff-tile{
  border-radius:calc(10px * var(--widget-space, 1));
  padding:calc(10px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  display:flex;
  flex-direction:column;
  gap:calc(4px * var(--widget-space, 1));
  text-align:center;
  background: var(--tile-bg, color-mix(in oklab, var(--muted), transparent 85%));
  color: var(--tile-fg, var(--fg));
  transition: transform .2s ease, background .2s ease;
}
.dayoff-tile--current{
  outline:1px solid color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 35%);
  outline-offset:1px;
  box-shadow:
    0 0 0 1px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 72%),
    0 4px 12px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 84%);
}
.dayoff-tile__label{
  font-size:calc(11px * var(--widget-scale, 1));
  font-weight:600;
}
.dayoff-tile__value{
  font-size:calc(14px * var(--widget-scale, 1));
  font-weight:700;
  font-variant-numeric: tabular-nums;
}
.dayoff-tile__meta{
  font-size:calc(10px * var(--widget-scale, 1));
  color: color-mix(in oklab, currentColor, transparent 55%);
  font-variant-numeric: tabular-nums;
}
.pill{
  display:inline-flex;
  align-items:center;
  gap:calc(4px * var(--widget-space, 1));
  padding:calc(2px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  font-size:calc(11px * var(--widget-scale, 1));
  border-radius:999px;
  background: color-mix(in srgb, var(--brand) 15%, white);
  color: var(--brand);
  text-transform: uppercase;
  letter-spacing: .05em;
}
.hint{
  font-size:calc(12px * var(--widget-scale, 1));
  color:var(--muted);
}
</style>
