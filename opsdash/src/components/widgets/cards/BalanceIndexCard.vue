<template>
  <div
    class="balance-card"
    :class="{ 'balance-card--compact': isCompact }"
    :style="cardStyle"
  >
    <div class="header" :class="{ compact: isCompact }">
      <div class="title-row" v-if="showHeader">
        <span class="title">{{ titleText }}</span>
      </div>
      <div class="index" :class="{ centered: isCompact }" v-if="overview">
        <div class="index-badge" :style="indexBadgeStyle" :title="indexTitle">
          <div class="index-badge__ring" aria-hidden="true">
            <span class="index-badge__value">{{ currentIndexDisplay }}</span>
          </div>
          <div class="index-badge__meta">
            <div class="index-badge__label">Current index</div>
            <div class="index-badge__range" v-if="rangeLabel">{{ rangeLabel }}</div>
          </div>
        </div>
      </div>
    </div>

    <div class="trend" v-if="showTrend && hasTrend">
      <div class="trend-line" :style="trendLineStyle">
        <div
          v-for="(pt, idx) in filteredPoints"
          :key="idx"
          :class="[
            'trend-block',
            {
              current: pt.offset === 0,
              'no-range': !hasRangeLabel(idx),
            },
          ]"
          :title="pt.label || ''"
          :style="trendBlockStyle(idx)"
        >
          <div class="trend-line-row">
            <span class="trend-value">{{ formatIndex(pt.index) }}</span>
            <span class="trend-range">{{ labelMode === 'date' ? (computedRange(idx) || displayRange(idx)) : '' }}</span>
            <span class="trend-offset">
              <template v-if="labelMode === 'period'">{{ computedPeriodTag(idx) }}</template>
              <template v-else-if="labelMode === 'offset'">{{ displayOffset(idx) }}</template>
            </span>
          </div>
        </div>
      </div>
    </div>

    <div class="messages" v-if="showMessages && limitedMessages.length">
      <div class="section-title">Messages</div>
      <ul>
        <li v-for="(msg, idx) in limitedMessages" :key="idx">{{ msg }}</li>
      </ul>
    </div>

    <div class="config" v-if="showConfig">
      <div>Basis: {{ configSummary.basis }}</div>
      <div>Notice ±: +{{ configSummary.noticeAbove ?? '—' }} / -{{ configSummary.noticeBelow ?? '—' }}</div>
      <div>Warn ±: +{{ configSummary.warnAbove ?? '—' }} / -{{ configSummary.warnBelow ?? '—' }}</div>
      <div>Warn index: {{ configSummary.warnIndex ?? '—' }}</div>
    </div>

    <div v-if="!overview" class="empty">No balance data.</div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { createDefaultBalanceConfig } from '../../../services/targets/config'
import { addDaysUtc, addMonthsUtc, endOfMonthUtc, formatDateKey, formatDateRange, getWeekNumber, parseDateKey } from '../../../services/dateTime'

const props = withDefaults(defineProps<{
  overview?: any
  rangeLabel?: string
  showBadge?: boolean
  showTrend?: boolean
  showMessages?: boolean
  showConfig?: boolean
  messageLimit?: number
  lookbackWeeks?: number
  loopbackCount?: number
  indexBasis?: string
  showCurrent?: boolean
  labelMode?: 'date' | 'period' | 'offset'
  reverseOrder?: boolean
  from?: string
  to?: string
  rangeMode?: 'week' | 'month' | string
  thresholds?: {
    noticeAbove?: number
    noticeBelow?: number
    warnAbove?: number
    warnBelow?: number
    warnIndex?: number
  }
  title?: string
  cardBg?: string | null
  showHeader?: boolean
  targetsCategories?: Array<{ id: string; targetHours: number }>
  trendColor?: string
}>(), {
  showCurrent: true,
})

const titleText = computed(() => props.title || 'Balance Index')
const showHeader = computed(() => props.showHeader !== false)
const historyUnit = computed(() => (props.rangeMode || '').toLowerCase() === 'month' ? 'mo' : 'wk')
const historyRaw = computed(() => {
  if (Array.isArray(props.overview?.trend?.history)) return props.overview.trend.history
  if (Array.isArray((props.overview as any)?.trendHistory)) return (props.overview as any).trendHistory
  return []
})
const historyCount = computed(() => {
  const configured = Number.isFinite(props.lookbackWeeks)
    ? Number(props.lookbackWeeks)
    : Number.isFinite(props.loopbackCount)
      ? Number(props.loopbackCount)
      : null
  if (configured != null) {
    return Math.max(0, Math.min(6, Math.floor(configured)))
  }
  const historyEntries = historyRaw.value.filter((entry: any) => Number(entry?.offset ?? 0) > 0)
  return Math.max(0, Math.min(6, historyEntries.length))
})
const historyByOffset = computed(() => {
  const byOffset = new Map<number, { index: number; label: string }>()
  const targets = props.targetsCategories || []
  const basis = props.indexBasis
  historyRaw.value.forEach((entry: any, idx: number) => {
    const rawOffset = Number(entry?.offset ?? entry?.step)
    const offset = Number.isFinite(rawOffset) && rawOffset > 0 ? Math.round(rawOffset) : idx + 1
    const precomputedIndex = Number(entry?.index)
    if (Number.isFinite(precomputedIndex)) {
      byOffset.set(offset, {
        index: Math.max(0, Math.min(1, precomputedIndex)),
        label: entry?.label || '',
      })
      return
    }
    const shares: Record<string, number> = {}
    if (Array.isArray(entry?.categories)) {
      entry.categories.forEach((cat: any) => {
        shares[String(cat.id)] = Number(cat.share ?? 0)
      })
    }
    const values = Object.values(shares)
    const maxShare = values.length ? Math.max(...values) : 0
    const totalShare = values.reduce((a, b) => a + b, 0)
    if (maxShare > 1 || totalShare > 1.5) {
      Object.keys(shares).forEach((key) => {
        shares[key] = shares[key] / 100
      })
    }
    const totalAfter = Object.values(shares).reduce((a, b) => a + b, 0)
    if (totalAfter > 1.0001 && totalAfter > 0) {
      Object.keys(shares).forEach((key) => {
        shares[key] = shares[key] / totalAfter
      })
    }
    const index = computeIndexForSharesFallback({ shares, targets, basis })
    byOffset.set(offset, { index, label: entry?.label || '' })
  })
  return byOffset
})
const normalizedPoints = computed(() => {
  const points: Array<{ index?: number; label: string; offset: number }> = []
  const lookback = historyCount.value
  for (let offset = lookback; offset >= 1; offset -= 1) {
    const entry = historyByOffset.value.get(offset)
    points.push({
      offset,
      index: entry?.index,
      label: entry?.label || `-${offset} ${historyUnit.value}`,
    })
  }
  points.push({
    offset: 0,
    index: typeof props.overview?.index === 'number' ? props.overview.index : undefined,
    label: props.rangeLabel || '',
  })
  return points
})
const showCurrentPoint = computed(() => props.showCurrent !== false)
const filteredPoints = computed(() => {
  let pts = normalizedPoints.value.slice()
  if (!showCurrentPoint.value) {
    pts = pts.filter((pt: any) => (pt?.offset ?? 0) !== 0)
  }
  if (props.reverseOrder === true) {
    return pts.slice().reverse()
  }
  return pts
})
const hasTrend = computed(() => filteredPoints.value.length > 0)
const messages = computed(() => props.overview?.warnings || [])
const limitedMessages = computed(() => {
  const list = messages.value
  const limit = Number.isFinite(props.messageLimit) ? Number(props.messageLimit) : Infinity
  if (!Number.isFinite(limit) || limit <= 0) return list
  return list.slice(0, limit)
})
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const configSummary = computed(() => {
  const defaults = createDefaultBalanceConfig()
  const t = {
    noticeAbove: props.thresholds?.noticeAbove ?? defaults.thresholds.noticeAbove,
    noticeBelow: props.thresholds?.noticeBelow ?? defaults.thresholds.noticeBelow,
    warnAbove: props.thresholds?.warnAbove ?? defaults.thresholds.warnAbove,
    warnBelow: props.thresholds?.warnBelow ?? defaults.thresholds.warnBelow,
    warnIndex: props.thresholds?.warnIndex ?? defaults.thresholds.warnIndex,
  }
  return {
    basis: props.indexBasis || defaults.index.basis,
    ...t,
  }
})
const isCompact = computed(() => {
  const noTrend = props.showTrend === false || !hasTrend.value
  const noMessages = props.showMessages === false || limitedMessages.value.length === 0
  const noConfig = props.showConfig === false
  return noTrend && noMessages && noConfig
})
const currentIndex = computed(() => {
  const raw = props.overview?.index
  const num = typeof raw === 'number' ? raw : Number(raw ?? NaN)
  if (!Number.isFinite(num)) return null
  return Math.max(0, Math.min(1, num))
})
const currentIndexDisplay = computed(() => formatIndex(currentIndex.value ?? undefined))
const indexBadgeStyle = computed(() => {
  const value = currentIndex.value
  if (value == null) return {}
  const pct = Math.round(value * 100)
  let color = '#dc2626'
  if (value >= 0.8) {
    color = '#16a34a'
  } else if (value >= 0.65) {
    color = '#f59e0b'
  }
  return {
    '--index-fill': `${pct}%`,
    '--index-color': color,
  }
})
const indexTitle = computed(() => {
  if (currentIndex.value == null) return ''
  return `Balance index ${currentIndex.value.toFixed(2)}`
})

const computeIndexForSharesFallback = (opts: {
  shares: Record<string, number>
  targets: Array<{ id: string; targetHours: number }>
  basis?: string
}) => {
  const basis = opts.basis || 'category'
  if (basis === 'off') return 0
  const targetMap: Record<string, number> = {}
  const totalTarget = opts.targets?.length
    ? opts.targets.reduce((acc, cat) => acc + (cat.targetHours ?? 0), 0)
    : 0
  if (basis !== 'calendar' && totalTarget > 0) {
    opts.targets.forEach((cat) => {
      targetMap[String(cat.id)] = (cat.targetHours ?? 0) / totalTarget
    })
  }

  const keys = new Set<string>([...Object.keys(opts.shares || {}), ...Object.keys(targetMap)])
  if (keys.size === 0) return 0

  let maxDeviation = 0
  keys.forEach((key) => {
    const actual = Number(opts.shares?.[key] ?? 0)
    const expected = basis === 'calendar' ? (1 / keys.size) : (targetMap[key] ?? 0)
    maxDeviation = Math.max(maxDeviation, Math.abs(actual - expected))
  })

  return Math.max(0, Math.min(1, Number((1 - maxDeviation).toFixed(2))))
}

const formatIndex = (val?: number) => {
  return Number.isFinite(val) ? (val as number).toFixed(2) : '—'
}
const baseTrendColor = computed(() => props.trendColor || '#2563EB')
const labelMode = computed<'date' | 'period' | 'offset'>(() => {
  const mode = (props.labelMode || 'period').toString().toLowerCase()
  if (mode === 'date' || mode === 'offset') return mode as any
  return 'period'
})
const trendLineStyle = computed(() => {
  const mode = labelMode.value
  const min = mode === 'date' ? 108 : 88
  return { gridTemplateColumns: `repeat(auto-fit, minmax(${min}px, 1fr))` }
})

function shadeColor(hex: string, factor: number) {
  // factor: 0..1, 0 = original, 1 = darkest mix
  const h = hex.replace('#', '')
  const r = parseInt(h.substring(0, 2), 16)
  const g = parseInt(h.substring(2, 4), 16)
  const b = parseInt(h.substring(4, 6), 16)
  const mix = (c: number) => Math.max(0, Math.min(255, Math.round(c * (1 - factor))))
  return `rgb(${mix(r)}, ${mix(g)}, ${mix(b)})`
}

const trendBlockStyle = (idx: number) => {
  const base = baseTrendColor.value
  const pt = filteredPoints.value[idx]
  const idxVal = Number(pt?.index ?? 0)
  const deviation = Math.min(1, Math.max(0, 1 - idxVal)) // 0 when perfect, 1 when worst
  const color = shadeColor(base, deviation * 0.6)
  return {
    background: color,
  }
}

const resolvedRangeLabel = (idx: number) => {
  if (labelMode.value !== 'date') return ''
  const pt = filteredPoints.value[idx]
  const isCurrent = (pt?.offset ?? 0) === 0
  return pt?.label || (isCurrent ? props.rangeLabel || '' : '')
}
const hasRangeLabel = (idx: number) => !!resolvedRangeLabel(idx)

const displayOffset = (idx: number) => {
  if (labelMode.value !== 'offset') return ''
  const offsetFromCurrent = filteredPoints.value[idx]?.offset ?? idx
  if (offsetFromCurrent === 0) return 'Current'
  return `-${offsetFromCurrent}`
}
const displayRange = (idx: number) => {
  if (labelMode.value !== 'date') return ''
  const label = resolvedRangeLabel(idx)
  if (label) return label
  const offsetFromCurrent = filteredPoints.value[idx]?.offset ?? idx
  if (offsetFromCurrent === 0 && props.rangeLabel) return props.rangeLabel
  return ''
}

function isDefaultOffsetLabel(label: string | undefined) {
  return !label ? true : /^-\d+\s*(wk|mo)$/i.test(label.trim())
}
function formatRange(from?: Date, to?: Date) {
  if (!from || !to) return ''
  const fromKey = formatDateKey(from, 'UTC')
  const toKey = formatDateKey(to, 'UTC')
  return formatDateRange(fromKey, toKey, { day: '2-digit', month: '2-digit' })
}
function computedRange(idx: number) {
  const offsetFromCurrent = filteredPoints.value[idx]?.offset ?? idx
  if (!props.from || !props.to) {
    const label = filteredPoints.value[idx]?.label
    return isDefaultOffsetLabel(label) ? '' : (label || '')
  }
  const mode = (props.rangeMode || '').toLowerCase()
  const currentFrom = parseDateKey(props.from)
  const currentTo = parseDateKey(props.to)
  if (!currentFrom || !currentTo) return ''
  if (mode === 'month') {
    const start = addMonthsUtc(currentFrom, -offsetFromCurrent)
    const end = endOfMonthUtc(start)
    return formatRange(start, end)
  }
  // default week
  const start = addDaysUtc(currentFrom, -7 * offsetFromCurrent)
  const end = addDaysUtc(currentTo, -7 * offsetFromCurrent)
  return formatRange(start, end)
}
function computedPeriodTag(idx: number) {
  const offsetFromCurrent = filteredPoints.value[idx]?.offset ?? idx
  const mode = (props.rangeMode || '').toLowerCase()
  if (!props.from) {
    return offsetFromCurrent === 0 ? 'Current' : `- ${offsetFromCurrent} wk`
  }
  const startBase = parseDateKey(props.from)
  const start = startBase
    ? (mode === 'month'
      ? addMonthsUtc(startBase, -offsetFromCurrent)
      : addDaysUtc(startBase, -7 * offsetFromCurrent))
    : null
  if (!start) {
    return offsetFromCurrent === 0 ? 'Current' : `- ${offsetFromCurrent} wk`
  }
  if (mode === 'month') {
    const targetMonth = start.getUTCMonth() + 1
    const currentMonth = startBase ? startBase.getUTCMonth() + 1 : targetMonth
    const displayMonth = offsetFromCurrent === 0 ? currentMonth : targetMonth
    return `MONTH ${displayMonth}`
  }
  const w = getWeekNumber(start)
  const currentWeek = startBase ? getWeekNumber(startBase) : w
  const displayWeek = offsetFromCurrent === 0 ? currentWeek : w
  return `WEEK ${displayWeek}`
}
</script>

<style scoped>
.balance-card{
  background:var(--card,#fff);
  border:1px solid var(--color-border,#e5e7eb);
  border-radius:calc(12px * var(--widget-space, 1));
  padding:var(--widget-pad, 12px);
  color:var(--fg,#0f172a);
  display:flex;
  flex-direction:column;
  gap:var(--widget-gap, 10px);
  font-size:calc(14px * var(--widget-scale, 1));
}
.balance-card--compact{
  justify-content:center;
}
.header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:var(--widget-gap, 10px);
}
.header.compact{
  justify-content:center;
}
.title-row{
  display:flex;
  align-items:center;
  gap:calc(8px * var(--widget-space, 1));
}
.title{
  font-weight:600;
  font-size: var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
}
.pill{
  display:inline-flex;
  align-items:center;
  gap:calc(4px * var(--widget-space, 1));
  padding:calc(2px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  font-size:calc(10px * var(--widget-scale, 1));
  border-radius:999px;
  background: color-mix(in srgb, var(--brand, #2563eb) 15%, white);
  color:var(--brand, #2563eb);
  text-transform:uppercase;
  letter-spacing:.06em;
}
.index{
  text-align:right;
}
.index.centered{
  text-align:center;
  width:100%;
}
.index-badge{
  display:flex;
  align-items:center;
  gap:calc(8px * var(--widget-space, 1));
  padding:calc(6px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  border-radius:calc(12px * var(--widget-space, 1));
  background: color-mix(in oklab, var(--index-color, #2563eb) 14%, transparent);
  box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--index-color, #2563eb) 35%, transparent);
}
.index-badge__ring{
  width:calc(44px * var(--widget-scale, 1));
  height:calc(44px * var(--widget-scale, 1));
  border-radius:999px;
  background: conic-gradient(
    var(--index-color, #2563eb) var(--index-fill, 0%),
    color-mix(in srgb, var(--color-border, #e5e7eb), transparent 40%) 0
  );
  display:flex;
  align-items:center;
  justify-content:center;
  position:relative;
  flex-shrink:0;
}
.index-badge__ring::after{
  content:'';
  position:absolute;
  inset:calc(5px * var(--widget-space, 1));
  border-radius:999px;
  background: var(--color-main-background, #fff);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--index-color, #2563eb) 22%, transparent);
}
.index-badge__value{
  position:relative;
  z-index:1;
  font-weight:700;
  font-size:calc(14px * var(--widget-scale, 1));
  font-variant-numeric:tabular-nums;
  color:var(--index-color, #2563eb);
}
.index-badge__meta{
  display:flex;
  flex-direction:column;
  gap:calc(2px * var(--widget-space, 1));
  text-align:left;
}
.index-badge__label{
  font-size:calc(9px * var(--widget-scale, 1));
  text-transform:uppercase;
  letter-spacing:.08em;
  color:var(--muted);
}
.index-badge__range{
  font-size:calc(11px * var(--widget-scale, 1));
  color:var(--fg);
  white-space:nowrap;
}
.trend{
  display:flex;
  align-items:center;
  gap:var(--widget-gap, 10px);
}
.trend-line{
  flex:1;
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(calc(92px * var(--widget-scale, 1)), 1fr));
  gap:calc(6px * var(--widget-space, 1));
  justify-content:flex-start;
}
.trend-block{
  min-width:0;
  background:color-mix(in oklab, var(--color-primary,#2563eb), #e5e7eb 50%);
  border-radius:calc(8px * var(--widget-space, 1));
  padding:calc(4px * var(--widget-space, 1)) calc(5px * var(--widget-space, 1));
  display:flex;
  align-items:center;
  justify-content:center;
  position:relative;
  color:#fff;
  font-size:calc(11px * var(--widget-scale, 1));
  border:none;
  cursor:default;
}
.trend-block.no-range{
  grid-column: span 1;
  padding:calc(4px * var(--widget-space, 1)) calc(5px * var(--widget-space, 1));
}
.trend-block.no-range.no-offset{
  grid-column: span 1;
  padding:calc(4px * var(--widget-space, 1));
}
.trend-block.no-offset{
  grid-column: span 1;
  padding:calc(4px * var(--widget-space, 1)) calc(6px * var(--widget-space, 1));
}
.trend-block.current{
  background:var(--color-primary,#2563eb);
  outline:1px solid color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 35%);
  outline-offset:1px;
  box-shadow:
    0 0 0 1px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 72%),
    0 4px 12px color-mix(in oklab, var(--brand, var(--color-primary, #2563eb)), transparent 84%);
}
.trend-line-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:calc(4px * var(--widget-space, 1));
  width:100%;
}
.trend-value{
  font-weight:700;
  font-size:calc(13px * var(--widget-scale, 1));
  text-align:left;
  white-space:nowrap;
}
.trend-range{
  flex:1;
  text-align:center;
  font-size:calc(10px * var(--widget-scale, 1));
  color:#e5e7eb;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.trend-offset{
  min-width:calc(52px * var(--widget-scale, 1));
  text-align:right;
  font-size:calc(10px * var(--widget-scale, 1));
  color:#cbd5f5;
  white-space:nowrap;
}
.trend-delta{
  font-size:calc(12px * var(--widget-scale, 1));
  color:var(--muted);
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:calc(8px * var(--widget-space, 1));
}
.trend-left{
  font-weight:700;
  color:var(--fg,#0f172a);
}
.trend-center{
  flex:1;
  text-align:center;
  font-size:calc(11px * var(--widget-scale, 1));
}
.trend-right{
  min-width:calc(56px * var(--widget-scale, 1));
  text-align:right;
  font-size:calc(11px * var(--widget-scale, 1));
}
.section-title{
  font-weight:600;
  font-size:calc(13px * var(--widget-scale, 1));
  margin-bottom:calc(4px * var(--widget-space, 1));
}
.messages ul{
  margin:0;
  padding-left:calc(18px * var(--widget-space, 1));
  color:var(--muted);
  font-size:calc(13px * var(--widget-scale, 1));
}
.messages li{
  margin-bottom:calc(2px * var(--widget-space, 1));
}
.messages li:last-child{ margin-bottom:0; }
.config{
  font-size:calc(12px * var(--widget-scale, 1));
  color:var(--muted);
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(calc(140px * var(--widget-scale, 1)),1fr));
  gap:calc(6px * var(--widget-space, 1));
}
.empty{
  text-align:center;
  color:var(--muted);
  font-size:calc(13px * var(--widget-scale, 1));
}
</style>
