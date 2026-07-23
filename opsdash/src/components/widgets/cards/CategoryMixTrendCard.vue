<template>
  <div
    class="card mix-card"
    :style="cardStyle"
    :class="{
      'mix-card--no-header': !showHeader,
      'mix-card--dense': density === 'dense',
      'mix-card--square': squareCells,
    }"
  >
    <div v-if="showHeader" class="mix-header">
      <div>
        <div class="mix-title">{{ titleText }}</div>
        <div class="mix-subtitle">{{ lookbackLabel }}</div>
      </div>
      <span v-if="trendBadge" class="mix-badge">{{ trendBadge }}</span>
    </div>
    <div class="mix-columns" :style="mixHeaderStyle" v-if="historyColumns.length || trendRows.length">
      <span class="mix-column-label mix-column-label--label">CAT</span>
      <span
        v-for="column in displayColumns"
        :key="column.key"
        class="mix-column-label"
        :class="{ 'mix-column-label--current': column.isCurrent }"
      >
        {{ column.label }}
      </span>
    </div>
    <ul class="mix-list" v-if="trendRows.length">
      <li v-for="row in trendRows" :key="row.id" class="mix-row">
        <span class="mix-label">{{ row.label }}:</span>
        <div class="mix-cells" :style="mixCellsStyle">
          <div
            v-for="(cell, idx) in row.cells"
            :key="`${row.id}-cell-${idx}`"
            class="mix-cell"
            :class="[
              `mix-cell--trend-${cell.trend}`,
              `mix-cell--mode-${colorMode}`,
              { 'mix-cell--current': cell.isCurrent },
            ]"
            :style="cell.style"
            :title="`${row.label} · ${cell.label} · ${formatShare(cell.share)}`"
          >
            <span class="mix-cell__value">{{ formatShare(cell.share) }}</span>
            <span v-if="cell.indicator" class="mix-cell__indicator">{{ cell.indicator }}</span>
          </div>
        </div>
      </li>
    </ul>
    <div v-else class="hint">No category mix history.</div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { addDaysUtc, addMonthsUtc, endOfMonthUtc, formatDateKey, formatDateRange, getWeekNumber, parseDateKey } from '../../../services/dateTime'

type TrendHistoryEntry = {
  offset: number
  label: string
  categories: Array<{ id: string; label: string; share: number }>
}

type TrendHistoryColumn = {
  offset: number
  label: string
  shares: Record<string, number>
}

type BalanceCategory = { id: string; label: string; share: number; prevShare?: number }

type BalanceTrend = {
  badge?: string
  history?: Array<{ offset: number; label: string; categories: Array<{ id: string; label: string; share: number }> }>
}

type BalanceOverview = {
  categories: BalanceCategory[]
  trend: BalanceTrend
}

type ColorMode = 'trend' | 'share' | 'hybrid'
type DensityMode = 'normal' | 'dense'
type LabelMode = 'period' | 'offset' | 'label' | 'compact' | 'date'
type TrendIndicatorMode = 'none' | 'symbol' | 'delta' | 'both'

const props = defineProps<{
  overview: BalanceOverview | null
  rangeMode: 'week' | 'month'
  lookbackWeeks: number
  showBadge?: boolean
  showHeader?: boolean
  colorMode?: ColorMode
  density?: DensityMode
  labelMode?: LabelMode
  squareCells?: boolean
  reverseOrder?: boolean
  trendIndicator?: TrendIndicatorMode | string
  rangeLabel?: string
  from?: string
  to?: string
  title?: string
  cardBg?: string | null
  toneLowColor?: string | null
  toneHighColor?: string | null
  shareLowColor?: string | null
  shareHighColor?: string | null
}>()

const showHeader = computed(() => props.showHeader !== false)
const density = computed<DensityMode>(() => (props.density === 'dense' ? 'dense' : 'normal'))
const labelMode = computed<LabelMode>(() => {
  const mode = (props.labelMode || 'period').toString().toLowerCase()
  if (mode === 'offset' || mode === 'label' || mode === 'compact' || mode === 'date') return mode as LabelMode
  return 'period'
})
const squareCells = computed(() => props.squareCells === true)
const colorMode = computed<ColorMode>(() => {
  const mode = props.colorMode
  return mode === 'trend' || mode === 'share' || mode === 'hybrid' ? mode : 'hybrid'
})
const trendIndicator = computed<TrendIndicatorMode>(() => {
  const mode = String(props.trendIndicator ?? '').toLowerCase()
  if (mode === 'symbol' || mode === 'delta' || mode === 'both') return mode
  return 'none'
})
const trendBadge = computed(() => (props.showBadge === false ? '' : props.overview?.trend?.badge ?? ''))
const historyUnit = computed(() => (props.rangeMode === 'month' ? 'MO' : 'WE'))
const historyShortUnit = computed(() => (props.rangeMode === 'month' ? 'M' : 'W'))
const currentColumnLabel = computed(() => formatCurrentLabel())
const lookbackCount = computed(() => Math.max(1, Math.min(6, props.lookbackWeeks || 1)))
const titleText = computed(() => props.title || 'Category mix trend')
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})

const rawHistoryEntries = computed<TrendHistoryEntry[]>(() => {
  const history = props.overview?.trend?.history ?? (props.overview as any)?.trendHistory ?? []
  if (!Array.isArray(history)) {
    return []
  }
  return history
    .map((entry: any) => ({
      offset: Number(entry?.offset ?? entry?.step ?? 0) || 0,
      label: String(entry?.label ?? ''),
      categories: Array.isArray(entry?.categories)
        ? entry.categories.map((cat: any) => ({
            id: String(cat?.id ?? ''),
            label: String(cat?.label ?? ''),
            share: Number(cat?.share ?? 0) || 0,
          }))
        : [],
    }))
    .filter((entry) => entry.offset > 0)
})

const fallbackHistoryLabel = (offset: number) => `-${offset} ${historyUnit.value}`
const formatHistoryLabel = (offset: number, entryLabel?: string) => {
  if (labelMode.value === 'label' && entryLabel) return entryLabel
  if (labelMode.value === 'date') {
    return computedRangeLabel(offset, entryLabel) || fallbackHistoryLabel(offset)
  }
  if (labelMode.value === 'offset') return `-${offset}`
  if (labelMode.value === 'compact') return `${historyShortUnit.value}-${offset}`
  return computedPeriodLabel(offset)
}

const historyColumns = computed<TrendHistoryColumn[]>(() => {
  if (!props.overview) {
    return []
  }
  const lookback = lookbackCount.value
  const byOffset = new Map<number, TrendHistoryColumn>()
  rawHistoryEntries.value.forEach((entry) => {
    const offset = Math.max(1, Math.min(12, Math.round(entry.offset)))
    if (!offset || offset > lookback) return
    const shares: Record<string, number> = {}
    entry.categories.forEach((cat) => {
      if (!cat.id) return
      shares[cat.id] = Number.isFinite(cat.share) ? cat.share : 0
    })
    byOffset.set(offset, {
      offset,
      label: formatHistoryLabel(offset, entry.label),
      shares,
    })
  })
  return Array.from(byOffset.values()).sort((a, b) => b.offset - a.offset)
})

const displayColumns = computed(() => {
  const columns = [
    ...historyColumns.value.map((column) => ({
      key: `col-${column.offset}`,
      offset: column.offset,
      label: column.label,
      isCurrent: false,
      shares: column.shares,
    })),
    {
      key: 'col-current',
      offset: 0,
      label: currentColumnLabel.value,
      isCurrent: true,
      shares: null,
    },
  ]
  if (props.reverseOrder === true) {
    return columns.slice().reverse()
  }
  return columns
})

const columnCount = computed(() => displayColumns.value.length)
const mixHeaderStyle = computed(() => {
  const count = Math.max(columnCount.value, 1)
  const labelCol = 'minmax(0, var(--mix-label-width, 52px))'
  return {
    gridTemplateColumns: `${labelCol} repeat(${count}, minmax(0, 1fr))`,
  }
})
const mixCellsStyle = computed(() => {
  const count = Math.max(columnCount.value, 1)
  return {
    gridTemplateColumns: `repeat(${count}, minmax(0, 1fr))`,
  }
})

const toneStyles = computed(() => {
  const low = normalizeColor(props.toneLowColor) || '#e11d48'
  const high = normalizeColor(props.toneHighColor) || '#10b981'
  const mid = mixColor(low, high, 0.5)
  return {
    down: makeTone(low),
    flat: makeTone(mid),
    up: makeTone(high),
  }
})

const sharePalette = computed(() => {
  const low = normalizeColor(props.shareLowColor) || '#e2e8f0'
  const high = normalizeColor(props.shareHighColor) || '#60a5fa'
  return { low, high }
})

const shareColor = (value: number) => {
  const ratio = clamp(value, 0, 100) / 100
  return mixColor(sharePalette.value.low, sharePalette.value.high, ratio)
}

const classifyTrend = (delta: number) => {
  const threshold = 1
  if (delta > threshold) return 'up'
  if (delta < -threshold) return 'down'
  return 'flat'
}

const trendRows = computed(() => {
  if (!props.overview) return []
  const categories = props.overview.categories ?? []
  return categories.map((cat) => {
    const currentShare = typeof cat.share === 'number' ? cat.share : 0
    const slots = displayColumns.value.map((column) => ({
      label: column.label,
      share: column.isCurrent ? currentShare : (column.shares?.[cat.id] ?? 0),
      isCurrent: column.isCurrent,
    }))
    const cells = slots.map((slot, idx) => {
      const hasPrevious = idx > 0
      const prevShare = hasPrevious ? slots[idx - 1].share : slot.share
      const delta = slot.share - prevShare
      const trend = hasPrevious ? classifyTrend(delta) : 'flat'
      const mode = colorMode.value
      const trendTone = toneStyles.value[trend]
      const shareBg = shareColor(slot.share)
      const shareTone = makeTone(shareBg)
      const bg = mode === 'trend' ? trendTone.background : shareBg
      const fg = mode === 'trend' ? trendTone.color : shareTone.color
      return {
        label: slot.label,
        share: slot.share,
        isCurrent: !!slot.isCurrent,
        trend,
        indicator: trendIndicatorLabel(trend, delta, hasPrevious),
        style: {
          '--mix-bg': bg,
          '--mix-fg': fg,
          '--mix-accent': trendTone.background,
        },
      }
    })
    return {
      id: cat.id,
      label: cat.label,
      cells,
    }
  })
})

const lookbackLabel = computed(() => {
  const historyCount = lookbackCount.value
  if (historyCount > 1) {
    const unit = props.rangeMode === 'month' ? 'months' : 'weeks'
    return `History · last ${historyCount} ${unit}`
  }
  if (historyCount === 1 && historyColumns.value.length) {
    return historyColumns.value[historyColumns.value.length - 1]?.label || fallbackHistoryLabel(1)
  }
  return fallbackHistoryLabel(historyCount || 1)
})

const formatShare = (value: number) => `${Math.max(0, Math.round(value))}%`

function trendSymbol(trend: 'up' | 'down' | 'flat') {
  if (trend === 'up') return '↑'
  if (trend === 'down') return '↓'
  return '→'
}

function formatDelta(delta: number) {
  const rounded = Math.round(delta)
  if (!Number.isFinite(rounded)) return '0pp'
  if (rounded === 0) return '0pp'
  return `${rounded > 0 ? '+' : ''}${rounded}pp`
}

function trendIndicatorLabel(trend: 'up' | 'down' | 'flat', delta: number, hasPrevious: boolean) {
  if (!hasPrevious) return ''
  const mode = trendIndicator.value
  if (mode === 'none') return ''
  if (mode === 'symbol') return trendSymbol(trend)
  if (mode === 'delta') return formatDelta(delta)
  return `${trendSymbol(trend)} ${formatDelta(delta)}`
}

const dateRangeOptions = { day: '2-digit', month: '2-digit' }
function formatRange(from?: Date, to?: Date) {
  if (!from || !to) return ''
  const fromKey = formatDateKey(from, 'UTC')
  const toKey = formatDateKey(to, 'UTC')
  return formatDateRange(fromKey, toKey, dateRangeOptions)
}
function computedRangeLabel(offset: number, entryLabel?: string) {
  if (!props.from || !props.to) return entryLabel || ''
  const mode = (props.rangeMode || '').toLowerCase()
  const currentFrom = parseDateKey(props.from)
  const currentTo = parseDateKey(props.to)
  if (!currentFrom || !currentTo) return entryLabel || ''
  if (mode === 'month') {
    const start = addMonthsUtc(currentFrom, -offset)
    const end = endOfMonthUtc(start)
    return formatRange(start, end)
  }
  const start = addDaysUtc(currentFrom, -7 * offset)
  const end = addDaysUtc(currentTo, -7 * offset)
  return formatRange(start, end)
}
function computedPeriodLabel(offset: number) {
  const mode = (props.rangeMode || '').toLowerCase()
  if (!props.from) {
    if (offset === 0) return 'Current'
    return mode === 'month' ? `-${offset} mo` : `-${offset} wk`
  }
  const startBase = parseDateKey(props.from)
  const start = startBase
    ? (mode === 'month'
      ? addMonthsUtc(startBase, -offset)
      : addDaysUtc(startBase, -7 * offset))
    : null
  if (!start) {
    if (offset === 0) return 'Current'
    return mode === 'month' ? `-${offset} mo` : `-${offset} wk`
  }
  if (mode === 'month') {
    const currentMonth = startBase ? startBase.getUTCMonth() + 1 : start.getUTCMonth() + 1
    const displayMonth = offset === 0 ? currentMonth : start.getUTCMonth() + 1
    return `MONTH ${displayMonth}`
  }
  const currentWeek = startBase ? getWeekNumber(startBase) : getWeekNumber(start)
  const displayWeek = offset === 0 ? currentWeek : getWeekNumber(start)
  return `WEEK ${displayWeek}`
}
function formatCurrentLabel() {
  if (labelMode.value === 'offset') return 'Current'
  if (labelMode.value === 'compact') return 'CUR'
  if (labelMode.value === 'label') {
    return props.rangeLabel || (props.rangeMode === 'month' ? 'This month' : 'This week')
  }
  if (labelMode.value === 'date') {
    return computedRangeLabel(0, props.rangeLabel || '') || (props.rangeMode === 'month' ? 'This month' : 'This week')
  }
  return computedPeriodLabel(0)
}

function normalizeColor(value?: string | null): string | null {
  if (!value || typeof value !== 'string') return null
  const hex = value.trim()
  if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex)) return hex
  return null
}

function mixColor(a: string, b: string, ratio = 0.5): string {
  const pa = parseHex(a)
  const pb = parseHex(b)
  if (!pa || !pb) return a
  const r = clamp(Math.round(pa.r + (pb.r - pa.r) * ratio), 0, 255)
  const g = clamp(Math.round(pa.g + (pb.g - pa.g) * ratio), 0, 255)
  const bl = clamp(Math.round(pa.b + (pb.b - pa.b) * ratio), 0, 255)
  return `#${[r, g, bl].map((v) => v.toString(16).padStart(2, '0')).join('')}`
}

function parseHex(hex: string): { r: number; g: number; b: number } | null {
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
  const p = parseHex(hex)
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
.mix-card{
  display:flex;
  flex-direction:column;
  gap:var(--widget-gap, 10px);
  --mix-label-width: clamp(34px, calc(52px * var(--widget-scale, 1)), 64px);
  --mix-gap: clamp(2px, calc(6px * var(--widget-space, 1)), 8px);
  --mix-row-gap: clamp(4px, calc(8px * var(--widget-space, 1)), 12px);
  --mix-cell-padding: clamp(2px, calc(6px * var(--widget-space, 1)), 8px);
  --mix-cell-font: clamp(9px, calc(12px * var(--widget-scale, 1)), 14px);
  --mix-label-font: clamp(9px, calc(12px * var(--widget-scale, 1)), 13px);
  --mix-column-font: clamp(9px, calc(12px * var(--widget-scale, 1)), 12px);
  --mix-cell-min: clamp(18px, calc(30px * var(--widget-scale, 1)), 36px);
  --mix-radius: clamp(4px, calc(8px * var(--widget-space, 1)), 10px);
}
.mix-card--dense{
  gap:calc(6px * var(--widget-space, 1));
  --mix-label-width: clamp(28px, calc(40px * var(--widget-scale, 1)), 52px);
  --mix-gap: clamp(1px, calc(3px * var(--widget-space, 1)), 6px);
  --mix-row-gap: clamp(3px, calc(5px * var(--widget-space, 1)), 8px);
  --mix-cell-padding: clamp(1px, calc(3px * var(--widget-space, 1)), 5px);
  --mix-cell-font: clamp(8px, calc(10px * var(--widget-scale, 1)), 12px);
  --mix-label-font: clamp(8px, calc(10px * var(--widget-scale, 1)), 11px);
  --mix-column-font: clamp(8px, calc(9px * var(--widget-scale, 1)), 10px);
  --mix-cell-min: clamp(16px, calc(22px * var(--widget-scale, 1)), 30px);
  --mix-radius: clamp(3px, calc(5px * var(--widget-space, 1)), 8px);
}
.mix-card--no-header{
  gap:calc(6px * var(--widget-space, 1));
}
.mix-header{
  display:flex;
  justify-content:space-between;
  align-items:center;
}
.mix-title{
  font-weight:700;
  color:var(--fg);
  font-size:var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
}
.mix-subtitle{
  font-size:calc(12px * var(--widget-scale, 1));
  color:var(--muted);
}
.mix-badge{
  padding:calc(4px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  border-radius:calc(8px * var(--widget-space, 1));
  font-size:calc(12px * var(--widget-scale, 1));
  font-weight:600;
  background: color-mix(in oklab, var(--brand), transparent 80%);
  border: 1px solid color-mix(in oklab, var(--brand), transparent 60%);
  color: var(--brand);
}
.mix-list{
  list-style:none;
  padding:0;
  margin:0;
  display:flex;
  flex-direction:column;
  gap:var(--mix-row-gap);
}
.mix-row{
  display:grid;
  grid-template-columns:var(--mix-label-width) 1fr;
  gap:var(--mix-gap);
  align-items:center;
}
.mix-label{
  font-weight:600;
  color:var(--fg);
  font-size:var(--mix-label-font);
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.mix-columns{
  display:grid;
  gap:var(--mix-gap);
  align-items:center;
  justify-content:start;
  font-size:var(--mix-column-font);
  color:var(--muted);
}
.mix-column-label{
  text-align:center;
  white-space:nowrap;
  font-variant-numeric: tabular-nums;
  line-height:1.1;
  overflow:hidden;
  text-overflow:ellipsis;
}
.mix-column-label--label{
  text-align:left;
}
.mix-column-label--current{
  font-weight:700;
  color:var(--fg);
}
.mix-cells{
  display:grid;
  gap:var(--mix-gap);
  align-items:stretch;
  justify-content:start;
  width:100%;
  min-width:0;
}
.mix-cell{
  position:relative;
  border-radius:var(--mix-radius);
  padding:var(--mix-cell-padding);
  background: linear-gradient(
    135deg,
    color-mix(in oklab, var(--mix-bg), var(--brand) 18%),
    color-mix(in oklab, var(--mix-bg), var(--card) 10%)
  );
  color: var(--mix-fg, var(--fg));
  text-align:center;
  font-size:var(--mix-cell-font);
  border: 1px solid color-mix(in oklab, var(--mix-bg), var(--brand) 22%);
  box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--mix-bg), transparent 55%);
  min-height:var(--mix-cell-min);
  display:flex;
  align-items:center;
  justify-content:center;
  flex-direction:column;
  gap:2px;
}
:global(#opsdash.opsdash-theme-dark .mix-cell){
  background: linear-gradient(
    135deg,
    color-mix(in oklab, var(--mix-bg), #0b1220 38%),
    color-mix(in oklab, var(--mix-bg), #0b1220 56%)
  );
  border-color: color-mix(in oklab, var(--mix-bg), #0b1220 28%);
  color: color-mix(in oklab, #f8fafc 92%, var(--mix-fg) 8%);
  box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--mix-bg), transparent 64%);
}
:global(#opsdash.opsdash-theme-dark .mix-cell.mix-cell--mode-hybrid){
  box-shadow:
    inset 0 0 0 1px color-mix(in oklab, var(--mix-bg), transparent 64%),
    inset 0 -3px 0 0 color-mix(in oklab, var(--mix-accent), transparent 18%);
}
.mix-cell--current{
  outline: 1px solid color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 35%);
  outline-offset:1px;
  box-shadow:
    inset 0 0 0 1px color-mix(in oklab, var(--mix-bg), transparent 55%),
    0 0 0 1px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 72%),
    0 4px 12px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 84%);
}
.mix-cell--current.mix-cell--mode-hybrid{
  box-shadow:
    inset 0 0 0 1px color-mix(in oklab, var(--mix-bg), transparent 55%),
    inset 0 -3px 0 0 color-mix(in oklab, var(--mix-accent), transparent 30%),
    0 0 0 1px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 72%),
    0 4px 12px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 84%);
}
:global(#opsdash.opsdash-theme-dark .mix-cell.mix-cell--current){
  box-shadow:
    inset 0 0 0 1px color-mix(in oklab, var(--mix-bg), transparent 64%),
    0 0 0 1px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 72%),
    0 4px 12px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 84%);
}
:global(#opsdash.opsdash-theme-dark .mix-cell.mix-cell--current.mix-cell--mode-hybrid){
  box-shadow:
    inset 0 0 0 1px color-mix(in oklab, var(--mix-bg), transparent 64%),
    inset 0 -3px 0 0 color-mix(in oklab, var(--mix-accent), transparent 18%),
    0 0 0 1px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 72%),
    0 4px 12px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 84%);
}
.mix-cell--mode-hybrid{
  box-shadow: inset 0 -3px 0 0 color-mix(in oklab, var(--mix-accent), transparent 30%);
}
.mix-cell--mode-share{
  box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--mix-bg), transparent 40%);
}
.mix-card--square .mix-cell{
  aspect-ratio:1 / 1;
  min-height:0;
}
.mix-cell__value{
  font-weight:700;
  font-variant-numeric: tabular-nums;
  white-space:nowrap;
}
.mix-cell__indicator{
  font-size:clamp(8px, calc(9px * var(--widget-scale, 1)), 11px);
  font-weight:600;
  line-height:1;
  font-variant-numeric: tabular-nums;
  opacity:.88;
  white-space:nowrap;
}
.pill{
  display:inline-flex;
  align-items:center;
  gap:4px;
  padding:2px 8px;
  font-size:11px;
  border-radius:999px;
  background: color-mix(in srgb, var(--brand) 15%, white);
  color: var(--brand);
  text-transform: uppercase;
  letter-spacing: .05em;
}
.hint{
  font-size:12px;
  color:var(--muted);
}
</style>
