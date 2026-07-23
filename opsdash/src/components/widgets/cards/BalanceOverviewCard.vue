<template>
  <div class="card balance-card" :style="cardStyle">
    <div class="balance-card__header" v-if="showHeader">
      <span>{{ titleText }} ({{ rangeLabel }})</span>
      <div
        v-if="balanceIndexDisplay"
        class="balance-index"
        :style="balanceIndexStyle"
        :title="balanceIndexTitle"
      >
        <div class="balance-index__ring" aria-hidden="true">
          <span class="balance-index__value">{{ balanceIndexDisplay }}</span>
        </div>
        <div class="balance-index__meta">
          <div class="balance-index__label">Balance index</div>
          <div class="balance-index__range">{{ rangeLabel }}</div>
        </div>
      </div>
    </div>
    <div v-if="activitySummary" class="balance-card__activity">
      <div class="activity-hero">
        <div class="activity-hero__title">Activity &amp; Schedule</div>
        <div class="activity-hero__line" v-if="activityHeroLine">{{ activityHeroLine }}</div>
        <div class="activity-subline" v-if="activitySecondLine">{{ activitySecondLine }}</div>
        <div class="activity-meta" v-if="activityMeta.length">
          <span v-for="item in activityMeta" :key="item.label">
            <strong>{{ item.label }}</strong> {{ item.value }}
          </span>
        </div>
        <div class="activity-meta activity-meta--extra" v-if="activityExtra.length">
          <span v-for="item in activityExtra" :key="item.label">
            <strong>{{ item.label }}</strong> {{ item.value }}
          </span>
        </div>
      </div>
      <div class="activity-dayoff" v-if="settingsActivity.showDayOffTrend && dayOffTiles.length">
        <div class="dayoff-header">
          <span>Days off trend</span>
          <span class="dayoff-meta" v-if="dayOffLookbackLabel">Last {{ dayOffLookbackLabel }}</span>
        </div>
        <div class="dayoff-heatmap">
          <div
            v-for="entry in dayOffTiles"
            :key="entry.offset"
            class="dayoff-tile"
            :class="[
              `dayoff-tile--${entry.tone}`,
              { 'dayoff-tile--current': entry.offset === 0 },
            ]"
            :title="`${entry.label} · ${shareLabel(entry.share)}`"
          >
            <div class="dayoff-tile__label">{{ entry.label }}</div>
            <div class="dayoff-tile__value">{{ shareLabel(entry.share) }}</div>
            <div class="dayoff-tile__meta">
              {{ entry.daysOff }} off · {{ entry.daysWorked }} on
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="balance-card__mix" v-if="trendRows.length">
      <div class="mix-header">
        <div>
          <div class="mix-title">Category mix trend</div>
          <div class="mix-subtitle">{{ lookbackLabel }}</div>
        </div>
        <span v-if="trendBadge" class="mix-badge">{{ trendBadge }}</span>
      </div>
      <div class="mix-columns" :style="mixGridStyle">
        <span class="mix-column-label mix-column-label--label">CAT</span>
        <span
          v-for="column in historyColumns"
          :key="`col-${column.offset}`"
          class="mix-column-label"
        >
          {{ column.label }}
        </span>
        <span class="mix-column-label mix-column-label--current">{{ currentColumnLabel }}</span>
      </div>
      <ul class="mix-list">
        <li v-for="row in trendRows" :key="row.id" class="mix-row">
          <span class="mix-label">{{ row.label }}:</span>
          <div class="mix-cells" :style="mixGridStyle">
            <div
              v-for="(cell, idx) in row.cells"
              :key="`${row.id}-cell-${idx}`"
              class="mix-cell"
              :class="[
                `mix-cell--trend-${cell.trend}`,
                { 'mix-cell--current': cell.isCurrent },
              ]"
              :title="`${row.label} · ${cell.label} · ${formatShare(cell.share)}`"
            >
              <span class="mix-cell__value">{{ formatShare(cell.share) }}</span>
            </div>
          </div>
        </li>
      </ul>
    </div>
    <ul class="balance-card__warnings" v-if="warnings.length">
      <li v-for="warn in warnings" :key="warn">⚠️ {{ warn }}</li>
    </ul>
    <div class="balance-card__note" v-if="noteText">
      📝 {{ noteText }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { createDefaultActivityCardConfig, type ActivityCardConfig } from '../../../services/targets'
import { formatDateOnly, formatTime } from '../../../services/dateTime'

type BalanceCategory = {
  id: string
  label: string
  hours: number
  share: number
  prevShare: number
  delta: number
  color?: string
}

type BalanceRelation = { label: string; value: string }
type BalanceTrend = {
  delta: Array<{ id: string; label: string; delta: number }>
  badge: string
  history?: Array<{
    offset: number
    label: string
    categories: Array<{ id: string; label: string; share: number }>
  }>
}
type BalanceDailyEntry = {
  date: string
  weekday: string
  total_hours: number
  categories: Array<{ id: string; label: string; hours: number; share: number }>
}

type BalanceOverview = {
  index: number
  categories: BalanceCategory[]
  relations: BalanceRelation[]
  trend: BalanceTrend
  daily: BalanceDailyEntry[]
  warnings: string[]
}

type BalanceCardConfig = {
  showNotes?: boolean
}

const defaultActivityConfig: ActivityCardConfig = createDefaultActivityCardConfig()

const props = defineProps<{
  overview: BalanceOverview | null
  rangeLabel: string
  rangeMode: 'week' | 'month'
  lookbackWeeks: number
  config?: Partial<BalanceCardConfig>
  note?: string
  activitySummary?: {
    rangeLabel: string
    events: number
    activeDays: number | null
    typicalStart: string | null
    typicalEnd: string | null
    weekendShare: number | null
    eveningShare: number | null
    earliestStart: string | null
    latestEnd: string | null
    overlapEvents: number | null
    longestSession: number | null
    lastDayOff: string | null
    lastHalfDayOff: string | null
  } | null
  activityConfig?: Partial<ActivityCardConfig>
  activityDayOffTrend?: Array<{
    offset: number
    label: string
    from: string
    to: string
    totalDays: number
    daysOff: number
    daysWorked: number
  }>
  activityTrendUnit?: 'wk' | 'mo'
  activityDayOffLookback?: number
  showHeader?: boolean
  title?: string
  cardBg?: string | null
}>()

const settings = computed<BalanceCardConfig>(() => Object.assign({}, props.config ?? {}))
const noteText = computed(() => (props.note ?? '').trim())
const settingsActivity = computed<ActivityCardConfig>(() =>
  Object.assign({}, defaultActivityConfig, props.activityConfig ?? {}),
)
const titleText = computed(() => props.title || 'Activity & Balance')
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const showHeader = computed(() => props.showHeader !== false)
const balanceIndex = computed(() => {
  const raw = props.overview?.index
  const num = typeof raw === 'number' ? raw : Number(raw ?? NaN)
  if (!Number.isFinite(num)) return null
  return Math.max(0, Math.min(1, num))
})
const balanceIndexDisplay = computed(() => {
  if (balanceIndex.value == null) return ''
  return balanceIndex.value.toFixed(2)
})
const balanceIndexStyle = computed(() => {
  if (balanceIndex.value == null) return {}
  const pct = Math.round(balanceIndex.value * 100)
  let color = '#dc2626'
  if (balanceIndex.value >= 0.8) {
    color = '#16a34a'
  } else if (balanceIndex.value >= 0.65) {
    color = '#f59e0b'
  }
  return {
    '--balance-index-fill': `${pct}%`,
    '--balance-index-color': color,
  }
})
const balanceIndexTitle = computed(() => {
  if (balanceIndex.value == null) return ''
  return `Balance index ${balanceIndex.value.toFixed(2)} for ${props.rangeLabel}`
})

const activityHeroLine = computed(() => {
  if (!props.activitySummary) return ''
  const parts: string[] = []
  parts.push(`Events ${props.activitySummary.events}`)
  if (props.activitySummary.activeDays != null) {
    parts.push(`Active Days ${props.activitySummary.activeDays}`)
  }
  const typical = typicalRange(props.activitySummary.typicalStart, props.activitySummary.typicalEnd)
  if (typical) {
    parts.push(`Typical ${typical}`)
  }
  return parts.join(' • ')
})

const activitySecondLine = computed(() => {
  if (!props.activitySummary) return ''
  const parts: string[] = []
  if (settingsActivity.value.showWeekendShare && props.activitySummary.weekendShare != null) {
    parts.push(`Weekend ${pct(props.activitySummary.weekendShare)}`)
  }
  if (settingsActivity.value.showEveningShare && props.activitySummary.eveningShare != null) {
    parts.push(`Evening ${pct(props.activitySummary.eveningShare)}`)
  }
  return parts.join(' • ')
})

const activityMeta = computed(() => {
  if (!props.activitySummary) return []
  const items: Array<{ label: string; value: string }> = []
  if (settingsActivity.value.showEarliestLatest) {
    const earliest = timeOf(props.activitySummary.earliestStart)
    const latest = timeOf(props.activitySummary.latestEnd)
    if (earliest || latest) {
      items.push({ label: 'Earliest/Late', value: `${earliest || '—'} / ${latest || '—'}` })
    }
  }
  if (settingsActivity.value.showOverlaps && props.activitySummary.overlapEvents != null) {
    items.push({ label: 'Overlaps', value: props.activitySummary.overlapEvents.toString() })
  }
  return items
})

const activityExtra = computed(() => {
  if (!props.activitySummary) return []
  const items: Array<{ label: string; value: string }> = []
  if (settingsActivity.value.showLongestSession && props.activitySummary.longestSession != null && props.activitySummary.longestSession > 0) {
    items.push({ label: 'Longest Session', value: `${Number(props.activitySummary.longestSession).toFixed(1)} h` })
  }
  if (settingsActivity.value.showLastDayOff && props.activitySummary.lastDayOff) {
    items.push({ label: 'Last day off', value: shortDate(props.activitySummary.lastDayOff) })
  }
  if (settingsActivity.value.showLastDayOff && props.activitySummary.lastHalfDayOff) {
    items.push({ label: 'Last half day', value: shortDate(props.activitySummary.lastHalfDayOff) })
  }
  return items
})

type DayOffTrendEntry = {
  offset: number
  label: string
  from: string
  to: string
  totalDays: number
  daysOff: number
  daysWorked: number
}
type DayOffTrendTile = DayOffTrendEntry & { share: number; tone: 'low' | 'mid' | 'high' }

const dayOffHistoryCount = computed(() => {
  const configured = typeof props.activityDayOffLookback === 'number' ? props.activityDayOffLookback : null
  if (configured != null) {
    return Math.max(0, Math.min(12, Math.floor(configured)))
  }
  const historyEntries = (props.activityDayOffTrend ?? []).filter((entry) => Number(entry?.offset ?? 0) > 0)
  return Math.max(0, Math.min(12, historyEntries.length))
})

const normalizedDayOffTrend = computed<DayOffTrendEntry[]>(() => {
  const raw = Array.isArray(props.activityDayOffTrend) ? props.activityDayOffTrend : []
  const byOffset = new Map<number, DayOffTrendEntry>()
  raw.forEach((entry) => {
    const offset = Number(entry?.offset ?? 0) || 0
    if (offset <= 0) return
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
  const unit = props.activityTrendUnit === 'mo' ? 'mo' : 'wk'
  const lookback = dayOffHistoryCount.value || 0
  return Array.from(byOffset.entries())
    .filter(([offset]) => offset <= lookback || lookback === 0)
    .map(([, entry]) => ({
      ...entry,
      label: `-${entry.offset} ${unit}`,
    }))
    .sort((a, b) => b.offset - a.offset)
})

const dayOffTiles = computed<DayOffTrendTile[]>(() => normalizedDayOffTrend.value.map((entry) => {
  const total = Math.max(0, Number(entry.totalDays) || 0)
  const daysOff = Math.max(0, Math.min(total, Number(entry.daysOff) || 0))
  const share = total > 0 ? daysOff / total : 0
  return { ...entry, share, tone: classifyDayOffTone(share) }
}))

const dayOffLookbackLabel = computed(() => {
  const history = dayOffHistoryCount.value
  if (history <= 0) return ''
  const unitWord = props.activityTrendUnit === 'mo' ? 'month' : 'week'
  return `${history} ${history === 1 ? unitWord : `${unitWord}s`}`
})

const warnings = computed(() => props.overview?.warnings ?? [])
const trendBadge = computed(() => props.overview?.trend?.badge ?? '')

const currentColumnLabel = computed(() => `CU. ${historyUnit.value}`)

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

// Clamp to keep at most 4 lookback columns (current + 4 = 5 cells total).
const lookbackCount = computed(() => Math.max(1, Math.min(4, props.lookbackWeeks || 1)))
const historyUnit = computed(() => (props.rangeMode === 'month' ? 'MO' : 'WE'))

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
      label: fallbackHistoryLabel(offset),
      shares,
    })
  })
  return Array.from(byOffset.values()).sort((a, b) => b.offset - a.offset)
})
const columnCount = computed(() => historyColumns.value.length + 1)
const mixGridStyle = computed(() => ({
  gridTemplateColumns: `52px repeat(${Math.max(columnCount.value, 1)}, minmax(32px, 1fr))`,
}))

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
    const historyEntries = historyColumns.value.map((column) => ({
      label: column.label,
      share: column.shares[cat.id] ?? 0,
      isCurrent: false,
    }))
    const currentShare = typeof cat.share === 'number' ? cat.share : 0
    const slots = [
      ...historyEntries,
      { label: currentColumnLabel.value, share: currentShare, isCurrent: true },
    ]
    const cells = slots.map((slot, idx) => {
      const prevShare = idx === 0 ? slot.share : slots[idx - 1].share
      const delta = slot.share - prevShare
      return {
        label: slot.label,
        share: slot.share,
        isCurrent: !!slot.isCurrent,
        trend: idx === 0 ? 'flat' : classifyTrend(delta),
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

function pct(value: number | null | undefined) {
  if (value == null) return '0%'
  const num = Math.max(0, Math.min(100, Number(value)))
  return `${num.toFixed(1)}%`
}

function typicalRange(start: string | null, end: string | null) {
  const s = timeOf(start)
  const e = timeOf(end)
  if (!s && !e) return ''
  if (s && e) return `${s}–${e}`
  return s || e || ''
}

function timeOf(value: string | null | undefined) {
  if (!value) return ''
  const formatted = formatTime(value)
  return formatted || ''
}

function shortDate(value: string): string {
  const str = value.trim()
  if (!str) return '—'
  const formatted = formatDateOnly(str, { weekday: 'short', month: 'short', day: 'numeric' })
  return formatted || str
}

function shareLabel(value: number) {
  const pctVal = Math.max(0, Math.min(1, value))
  return `${Math.round(pctVal * 100)}% off`
}

function classifyDayOffTone(value: number): 'low' | 'mid' | 'high' {
  if (value >= 0.5) return 'high'
  if (value >= 0.25) return 'mid'
  return 'low'
}
</script>

<style scoped>
.balance-card {
  display: flex;
  flex-direction: column;
  gap: var(--widget-gap, 8px);
  --widget-pad: calc(14px * var(--widget-space, 1));
  padding: var(--widget-pad, 14px);
  font-size: var(--widget-font, 13px);
  color: var(--muted);
}
.balance-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: calc(8px * var(--widget-space, 1));
  font-weight: 600;
  color: var(--fg);
  font-size: var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
}
.balance-index {
  display: flex;
  align-items: center;
  gap: calc(6px * var(--widget-space, 1));
  text-align: right;
}
.balance-index__ring {
  width: calc(34px * var(--widget-scale, 1));
  height: calc(34px * var(--widget-scale, 1));
  border-radius: 999px;
  background: conic-gradient(
    var(--balance-index-color, #2563eb) var(--balance-index-fill, 0%),
    color-mix(in srgb, var(--color-border, #e5e7eb), transparent 40%) 0
  );
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}
.balance-index__ring::after {
  content: '';
  position: absolute;
  inset: calc(4px * var(--widget-space, 1));
  border-radius: 999px;
  background: var(--color-main-background, #fff);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--balance-index-color, #2563eb) 20%, transparent);
}
.balance-index__value {
  position: relative;
  z-index: 1;
  font-weight: 700;
  font-size: calc(11px * var(--widget-scale, 1));
  font-variant-numeric: tabular-nums;
  color: var(--fg);
}
.balance-index__meta {
  display: flex;
  flex-direction: column;
  gap: 0;
}
.balance-index__label {
  font-size: calc(8.5px * var(--widget-scale, 1));
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted);
}
.balance-index__range {
  font-size: calc(10px * var(--widget-scale, 1));
  color: var(--fg);
}
.balance-card__activity {
  display: flex;
  flex-direction: column;
  gap: calc(6px * var(--widget-space, 1));
  padding: calc(6px * var(--widget-space, 1)) 0;
  border-bottom: 1px solid var(--border, rgba(125, 125, 125, 0.2));
}
.activity-hero__title {
  font-size: var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
  font-weight: 600;
  color: var(--fg);
}
.activity-hero__line {
  font-weight: 600;
  color: var(--fg);
}
.activity-subline {
  font-size: calc(12px * var(--widget-scale, 1));
  color: var(--muted);
}
.activity-meta {
  display: flex;
  flex-wrap: wrap;
  gap: calc(8px * var(--widget-space, 1)) calc(12px * var(--widget-space, 1));
  font-size: calc(12px * var(--widget-scale, 1));
  color: var(--muted);
}
.activity-meta strong {
  font-weight: 600;
  color: var(--fg);
}
.activity-meta--extra {
  font-size: calc(12px * var(--widget-scale, 1));
  color: var(--muted);
}
.activity-dayoff {
  display: flex;
  flex-direction: column;
  gap: calc(6px * var(--widget-space, 1));
}
.dayoff-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 600;
  font-size: calc(12px * var(--widget-scale, 1));
  color: var(--fg);
}
.dayoff-meta {
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--muted);
}
.dayoff-heatmap {
  display: grid;
  gap: calc(2px * var(--widget-space, 1));
  grid-template-columns: repeat(auto-fit, minmax(calc(90px * var(--widget-scale, 1)), 1fr));
}
.dayoff-tile {
  border: 1px solid var(--color-border, rgba(125, 125, 125, 0.2));
  border-radius: calc(6px * var(--widget-space, 1));
  padding: calc(3px * var(--widget-space, 1));
  display: grid;
  gap: calc(1px * var(--widget-space, 1));
  background: var(--color-main-background);
}
.dayoff-tile--current {
  border-color: color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 35%);
  outline: 1px solid color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 35%);
  outline-offset: 1px;
  box-shadow:
    0 0 0 1px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 72%),
    0 4px 12px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 84%);
}
.dayoff-tile--low {
  background: color-mix(in srgb, #10b981 12%, var(--color-main-background));
}
.dayoff-tile--mid {
  background: color-mix(in srgb, #f59e0b 12%, var(--color-main-background));
}
.dayoff-tile--high {
  background: color-mix(in srgb, #ef4444 12%, var(--color-main-background));
}
.dayoff-tile__label {
  font-weight: 600;
  color: var(--fg);
  font-size: calc(11px * var(--widget-scale, 1));
}
.dayoff-tile__value {
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--fg);
}
.dayoff-tile__meta {
  font-size: calc(9.5px * var(--widget-scale, 1));
  color: var(--muted);
}
.balance-card__warnings {
  margin: 0;
  padding-left: calc(16px * var(--widget-space, 1));
  font-size: calc(12px * var(--widget-scale, 1));
  display: flex;
  flex-direction: column;
  gap: calc(4px * var(--widget-space, 1));
}
.balance-card__warnings {
  color: var(--danger, #b91c1c);
}
.balance-card__note {
  font-size: calc(12px * var(--widget-scale, 1));
  color: var(--fg);
  background: color-mix(in oklab, var(--brand), transparent 88%);
  border-radius: calc(6px * var(--widget-space, 1));
  padding: calc(6px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  white-space: pre-line;
}
.badge {
  display: inline-flex;
  align-items: center;
  padding: calc(2px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  border-radius: 999px;
  font-size: calc(11px * var(--widget-scale, 1));
  background: color-mix(in srgb, var(--brand) 12%, white);
  color: var(--brand);
  text-transform: uppercase;
  letter-spacing: .05em;
}
.balance-card__mix {
  border-top: 1px solid var(--border, rgba(125, 125, 125, 0.2));
  padding-top: calc(8px * var(--widget-space, 1));
}
.mix-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: calc(6px * var(--widget-space, 1));
  margin-bottom: calc(6px * var(--widget-space, 1));
}
.mix-title {
  font-size: var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
  font-weight: 600;
  color: var(--fg);
}
.mix-subtitle {
  font-size: calc(9px * var(--widget-scale, 1));
  color: var(--muted);
}
.mix-badge {
  font-size: calc(8px * var(--widget-scale, 1));
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: calc(2px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  border-radius: 999px;
  background: color-mix(in oklab, var(--brand), transparent 80%);
  color: var(--brand);
  border: 1px solid color-mix(in oklab, var(--brand), transparent 60%);
}
.mix-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: calc(6px * var(--widget-space, 1));
  font-size: calc(11px * var(--widget-scale, 1));
}
.mix-row {
  display: grid;
  grid-template-columns: calc(52px * var(--widget-scale, 1)) repeat(auto-fit, minmax(calc(32px * var(--widget-scale, 1)), 1fr));
  gap: calc(1px * var(--widget-space, 1));
  align-items: stretch;
}
.mix-label {
  padding: calc(2px * var(--widget-space, 1)) calc(4px * var(--widget-space, 1));
  font-weight: 600;
  color: var(--fg);
  font-size: calc(9px * var(--widget-scale, 1));
  text-align: left;
}
.mix-columns {
  display: grid;
  align-items: center;
}
.mix-column-label {
  text-align: center;
  font-size: calc(8px * var(--widget-scale, 1));
  color: var(--muted);
  text-transform: uppercase;
  padding: calc(1px * var(--widget-space, 1)) 0;
}
.mix-column-label--current {
  color: var(--brand);
}
.mix-cells {
  display: contents;
}
.mix-cell {
  border-radius: calc(4px * var(--widget-space, 1));
  padding: calc(1px * var(--widget-space, 1)) calc(0.25px * var(--widget-space, 1));
  text-align: center;
  font-size: calc(9px * var(--widget-scale, 1));
  font-weight: 700;
  color: var(--fg);
  background: color-mix(in oklab, var(--muted), transparent 85%);
  transition: background .2s ease;
}
.mix-cell--current {
  outline: 1px solid color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 35%);
  outline-offset: 1px;
  box-shadow:
    0 0 0 1px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 72%),
    0 4px 12px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 84%);
}
.mix-cell--trend-up {
  background: color-mix(in oklab, #16a34a, white 65%);
  color: #14532d;
}
.mix-cell--trend-down {
  background: color-mix(in oklab, #dc2626, white 65%);
  color: #7f1d1d;
}
.mix-cell--trend-flat {
  background: color-mix(in oklab, #f97316, white 70%);
  color: #7c2d12;
}
.mix-cell__value {
  font-variant-numeric: tabular-nums;
}
</style>
