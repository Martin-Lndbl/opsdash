<template>
  <div class="card activity-card" :style="cardStyle">
    <div class="activity-card__header" v-if="showHeader">
      <span>{{ titleText }} ({{ rangeLabel }})</span>
      <span v-if="summary.rangeLabel" class="pill">{{ summary.rangeLabel }}</span>
    </div>
    <div class="activity-card__hero" v-if="heroLine">
      <span>{{ heroLine }}</span>
    </div>
    <div class="activity-card__subline" v-if="secondLine">
      <span>{{ secondLine }}</span>
    </div>
    <div class="activity-card__meta" v-if="metaLine.length">
      <span v-for="item in metaLine" :key="item.label">
        <strong>{{ item.label }}</strong> {{ item.value }}
      </span>
    </div>
    <div class="activity-card__extra" v-if="extraLine.length">
      <span v-for="item in extraLine" :key="item.label">
        <strong>{{ item.label }}</strong> {{ item.value }}
      </span>
    </div>
    <div class="activity-card__trend" v-if="settings.showDayOffTrend && dayOffTiles.length">
      <div class="dayoff-header">
        <span>Days off trend</span>
        <span class="dayoff-meta" v-if="lookbackLabel">
          Last {{ lookbackLabel }}
        </span>
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
    <div class="activity-card__hint" v-if="settings.showHint && categoryHint">
      {{ categoryHint }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { formatDateOnly, formatTime } from '../../../services/dateTime'
import { createDefaultActivityCardConfig, type ActivityCardConfig } from '../../../services/targets'

type ActivitySummary = {
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
}

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

const defaultConfig: ActivityCardConfig = createDefaultActivityCardConfig()

const props = defineProps<{
  summary: ActivitySummary
  rangeLabel: string
  categoryHint?: string
  config?: Partial<ActivityCardConfig>
  dayOffTrend?: DayOffTrendEntry[]
  trendUnit?: string
  dayOffLookback?: number
  showHeader?: boolean
  title?: string
  cardBg?: string | null
}>()

const settings = computed<ActivityCardConfig>(() => Object.assign({}, defaultConfig, props.config ?? {}))
const titleText = computed(() => props.title || 'Activity & Schedule')
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const showHeader = computed(() => props.showHeader !== false)

const heroLine = computed(() => {
  const parts: string[] = []
  parts.push(`Events ${props.summary.events}`)
  if (props.summary.activeDays != null) {
    parts.push(`Active Days ${props.summary.activeDays}`)
  }
  const typical = typicalRange(props.summary.typicalStart, props.summary.typicalEnd)
  if (typical) {
    parts.push(`Typical ${typical}`)
  }
  return parts.join(' • ')
})

const secondLine = computed(() => {
  const parts: string[] = []
  if (settings.value.showWeekendShare && props.summary.weekendShare != null) {
    parts.push(`Weekend ${pct(props.summary.weekendShare)}`)
  }
  if (settings.value.showEveningShare && props.summary.eveningShare != null) {
    parts.push(`Evening ${pct(props.summary.eveningShare)}`)
  }
  return parts.join(' • ')
})

const metaLine = computed(() => {
  const items: Array<{ label: string; value: string }> = []
  if (settings.value.showEarliestLatest) {
    const earliest = timeOf(props.summary.earliestStart)
    const latest = timeOf(props.summary.latestEnd)
    if (earliest || latest) {
      items.push({ label: 'Earliest/Late', value: `${earliest || '—'} / ${latest || '—'}` })
    }
  }
  if (settings.value.showOverlaps && props.summary.overlapEvents != null) {
    items.push({ label: 'Overlaps', value: props.summary.overlapEvents.toString() })
  }
  return items
})

const extraLine = computed(() => {
  const items: Array<{ label: string; value: string }> = []
  if (settings.value.showLongestSession && props.summary.longestSession != null && props.summary.longestSession > 0) {
    items.push({ label: 'Longest Session', value: `${Number(props.summary.longestSession).toFixed(1)} h` })
  }
  if (settings.value.showLastDayOff && props.summary.lastDayOff) {
    items.push({ label: 'Last day off', value: shortDate(props.summary.lastDayOff) })
  }
  if (settings.value.showLastDayOff && props.summary.lastHalfDayOff) {
    items.push({ label: 'Last half day', value: shortDate(props.summary.lastHalfDayOff) })
  }
  return items
})

const dayOffHistoryCount = computed(() => {
  const configured = typeof props.dayOffLookback === 'number' ? props.dayOffLookback : null
  if (configured != null) {
    return Math.max(0, Math.min(12, Math.floor(configured)))
  }
  const historyEntries = (props.dayOffTrend ?? []).filter((entry) => Number(entry?.offset ?? 0) > 0)
  return Math.max(0, Math.min(12, historyEntries.length))
})

const normalizedDayOffTrend = computed<DayOffTrendEntry[]>(() => {
  const raw = Array.isArray(props.dayOffTrend) ? props.dayOffTrend : []
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
  const unit = props.trendUnit === 'mo' ? 'mo' : 'wk'
  const nowLabel = props.trendUnit === 'mo' ? 'This month' : 'This week'
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
  const lookback = dayOffHistoryCount.value
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

const dayOffTiles = computed<DayOffTrendTile[]>(() => {
  return normalizedDayOffTrend.value.map((entry) => {
    const total = Math.max(0, Number(entry.totalDays) || 0)
    const daysOff = Math.max(0, Math.min(total, Number(entry.daysOff) || 0))
    const share = total > 0 ? daysOff / total : 0
    return { ...entry, share, tone: classifyDayOffTone(share) }
  })
})

const lookbackLabel = computed(() => {
  const history = dayOffHistoryCount.value
  if (history <= 0) return ''
  const unitWord = props.trendUnit === 'mo' ? 'month' : 'week'
  return `${history} ${history === 1 ? unitWord : `${unitWord}s`}`
})

function typicalRange(start: string | null, end: string | null): string {
  if (start && end) return `${start}–${end}`
  if (start) return `${start}→`
  if (end) return `→${end}`
  return ''
}

function pct(value: number): string {
  return `${Number(value || 0).toFixed(1)}%`
}

function timeOf(value: string | null): string | null {
  if (!value) return null
  const formatted = formatTime(value)
  return formatted || null
}

function shortDate(value: string): string {
  const str = value.trim()
  if (!str) return '—'
  const formatted = formatDateOnly(str, { weekday: 'short', month: 'short', day: 'numeric' })
  return formatted || str
}

function shareLabel(value: number) {
  const pct = Math.max(0, Math.min(1, value))
  return `${Math.round(pct * 100)}% off`
}

function classifyDayOffTone(value: number): 'low' | 'mid' | 'high' {
  if (value >= 0.5) return 'high'
  if (value >= 0.25) return 'mid'
  return 'low'
}
</script>

<style scoped>
.activity-card {
  display: flex;
  flex-direction: column;
  gap: var(--widget-gap, 8px);
  padding: var(--widget-pad, 14px);
  font-size: var(--widget-font, 13px);
  color: var(--muted);
}
.activity-card__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
  color: var(--fg);
  font-size: var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
}
.activity-card__hero {
  font-weight: 600;
  color: var(--fg);
}
.activity-card__subline {
  font-size: calc(12px * var(--widget-scale, 1));
}
.activity-card__meta,
.activity-card__extra {
  display: flex;
  flex-wrap: wrap;
  gap: calc(8px * var(--widget-space, 1)) calc(14px * var(--widget-space, 1));
  font-size: calc(12px * var(--widget-scale, 1));
}
.activity-card__meta strong,
.activity-card__extra strong {
  font-weight: 600;
  color: var(--fg);
}
.activity-card__hint {
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--muted);
  opacity: .8;
}
.activity-card__trend {
  border-top: 1px solid var(--color-border-maxcontrast, rgba(125, 125, 125, 0.2));
  padding-top: calc(10px * var(--widget-space, 1));
  display: flex;
  flex-direction: column;
  gap: var(--widget-gap, 8px);
}
.dayoff-header {
  display: flex;
  justify-content: space-between;
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--muted);
}
.dayoff-meta {
  opacity: 0.85;
}
.dayoff-heatmap {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(calc(110px * var(--widget-scale, 1)), 1fr));
  gap: var(--widget-gap, 8px);
}
.dayoff-tile {
  border-radius: calc(10px * var(--widget-space, 1));
  padding: calc(10px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  display: flex;
  flex-direction: column;
  gap: calc(4px * var(--widget-space, 1));
  text-align: center;
  background: color-mix(in oklab, var(--muted), transparent 85%);
  color: var(--fg);
  transition: transform .2s ease, background .2s ease;
}
.dayoff-tile--current {
  outline: 1px solid color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 35%);
  outline-offset: 1px;
  box-shadow:
    0 0 0 1px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 72%),
    0 4px 12px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 84%);
}
.dayoff-tile--low {
  background: color-mix(in oklab, #dc2626, white 70%);
  color: #7f1d1d;
}
.dayoff-tile--mid {
  background: color-mix(in oklab, #f97316, white 68%);
  color: #7c2d12;
}
.dayoff-tile--high {
  background: color-mix(in oklab, #16a34a, white 65%);
  color: #14532d;
}
.dayoff-tile__label {
  font-size: calc(11px * var(--widget-scale, 1));
  font-weight: 600;
}
.dayoff-tile__value {
  font-size: calc(14px * var(--widget-scale, 1));
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.dayoff-tile__meta {
  font-size: calc(10px * var(--widget-scale, 1));
  color: color-mix(in oklab, currentColor, transparent 55%);
  font-variant-numeric: tabular-nums;
}
.pill {
  display: inline-flex;
  align-items: center;
  gap: calc(4px * var(--widget-space, 1));
  padding: calc(2px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  font-size: calc(11px * var(--widget-scale, 1));
  border-radius: 999px;
  background: color-mix(in srgb, var(--brand) 15%, white);
  color: var(--brand);
  text-transform: uppercase;
  letter-spacing: .05em;
}
</style>
