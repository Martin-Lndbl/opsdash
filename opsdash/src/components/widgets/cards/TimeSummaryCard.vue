<template>
    <div class="card time-summary compact" :style="cardStyle">
      <div class="time-summary-firstline" v-if="showHeader">
      <span>{{ headerText }}</span>
      </div>
    <div v-if="showOverviewPanel" class="time-summary-daily">
      <div class="time-summary-hero" v-if="showToday && todayTotal !== null">
        <div>
          <div class="time-summary-hero__label">Today</div>
          <div class="time-summary-hero__value">{{ n1(todayTotal) }}<span>h</span></div>
          <div v-if="todayPlannedHours > 0" class="time-summary-hero__planned">{{ n1(todayPlannedHours) }} h planned later</div>
        </div>
        <div class="time-summary-hero__side">
          <strong>{{ todayEvents }}</strong>
          <span>events</span>
          <span v-if="todayEvents > 0 && longestSessionLabel !== '—'">{{ longestSessionLabel }} longest</span>
        </div>
      </div>

      <div v-if="showTabs" class="time-summary-tabs" :class="`time-summary-tabs--${availableViews.length}`">
        <button
          v-for="view in availableViews"
          :key="view"
          type="button"
          :class="{ active: activeOverviewView === view }"
          @click="activeOverviewView = view"
        >{{ viewLabel(view) }}</button>
      </div>

      <div class="time-summary-tab-panel">
        <div v-if="activeOverviewView === 'daily' && showDailyKpis" class="time-summary-kpis">
          <div class="time-summary-kpi time-summary-kpi--compare">
            <strong>{{ n2(summary.avgDay) }} h</strong>
            <span>avg/day</span>
            <em v-if="avgDayDeltaLabel">{{ avgDayDeltaLabel }}</em>
          </div>
          <div class="time-summary-kpi time-summary-kpi--compare">
            <strong>{{ n2(summary.medianDay) }} h</strong>
            <span>median/day</span>
          </div>
          <div class="time-summary-kpi">
            <strong>{{ n2(todayAvgEvent) }} h</strong>
            <span>avg/event</span>
          </div>
          <div class="time-summary-kpi">
            <strong>{{ latestEndShort }}</strong>
            <span>latest end</span>
          </div>
          <div class="time-summary-kpi">
            <strong>{{ activeDaysLabel }}</strong>
            <span>active days</span>
          </div>
          <div class="time-summary-kpi">
            <strong>{{ longestSessionLabel }}</strong>
            <span>longest</span>
          </div>
        </div>

        <div v-else-if="activeOverviewView === 'calendars'" class="time-summary-lanes">
          <div class="time-summary-section-head">
            <strong>Today by calendar</strong>
            <span>{{ calendarTodayVisible.length }} calendar{{ calendarTodayVisible.length === 1 ? '' : 's' }}</span>
          </div>
          <div
            class="time-summary-lane"
            v-for="item in calendarTodayVisible"
            :key="item.id"
            :style="{ '--lane-color': item.color || 'var(--brand)' }"
          >
            <span class="dot" :style="{ background: item.color || 'var(--brand)' }"></span>
            <span class="name">{{ item.label }}</span>
            <span class="value">{{ n1(item.todayHours) }} h</span>
          </div>
          <div v-if="calendarTodayMoreCount > 0" class="time-summary-more">+ {{ calendarTodayMoreCount }} more</div>
        </div>

        <div v-else-if="activeOverviewView === 'categories'" class="time-summary-lanes">
          <div class="time-summary-section-head">
            <strong>Today by category</strong>
            <span>{{ categoryTodayVisible.length }} lane{{ categoryTodayVisible.length === 1 ? '' : 's' }}</span>
          </div>
          <div
            class="time-summary-lane"
            v-for="item in categoryTodayVisible"
            :key="item.id"
            :style="{ '--lane-color': item.color || 'var(--brand)' }"
          >
            <span class="dot" :style="{ background: item.color || 'var(--brand)' }"></span>
            <span class="name">
              {{ item.label }}
              <span
                v-if="(item as any).isUnassigned"
                class="unassigned-hint"
                title="Hours from calendars not assigned to a category. Open Settings → Categories to assign them."
              >ⓘ</span>
            </span>
            <span class="value">{{ n1(item.todayHours) }} h</span>
          </div>
          <div v-if="categoryTodayMoreCount > 0" class="time-summary-more">+ {{ categoryTodayMoreCount }} more</div>
        </div>
      </div>

      <div v-if="showWeekMiniChart && weekDays.length" class="time-summary-week">
        <div
          v-for="day in weekDays"
          :key="day.date"
          class="time-summary-week__day"
          :class="{ active: day.isToday }"
          :title="`${day.label}: ${n1(day.hours)} h`"
        >
          <i class="time-summary-week__bar" :style="{ height: `${dayHeight(day.hours)}%` }">
            <strong>{{ n1(day.hours) }}h</strong>
          </i>
          <span>{{ day.label }}</span>
        </div>
      </div>

    </div>
    <div class="time-summary-history" v-if="showLookbackPanel && historyRows.length">
      <div class="time-summary-history__header">
        <div class="time-summary-history__title">Lookback</div>
        <div class="time-summary-history__mode">{{ historyViewLabel }}</div>
      </div>
      <div v-if="historyView === 'timeline'" class="time-summary-history__timeline">
        <div class="history-timeline-entry" v-for="item in historyRows" :key="item.offset">
          <div class="history-timeline-entry__header">
            <span class="history-timeline-entry__title">{{ item.label }}</span>
            <span v-if="item.range" class="history-timeline-entry__range">{{ item.range }}</span>
            <span v-if="showDelta && item.deltaHours" class="history-timeline-entry__delta">
              {{ item.deltaHours }}<template v-if="item.deltaPercent"> · {{ item.deltaPercent }}</template>
            </span>
          </div>
          <div class="history-timeline-entry__metrics">
            <div v-if="summaryConfig.showTotal" class="history-timeline-cell">
              <span class="history-timeline-cell__label">Total</span>
              <span class="history-timeline-cell__value">{{ item.totalHours }}</span>
            </div>
            <div v-if="summaryConfig.showAverage" class="history-timeline-cell">
              <span class="history-timeline-cell__label">Avg/day</span>
              <span class="history-timeline-cell__value">{{ item.avgDay }}</span>
            </div>
            <div v-if="summaryConfig.showBalance" class="history-timeline-cell">
              <span class="history-timeline-cell__label">Balance</span>
              <span class="history-timeline-cell__value">{{ item.balanceIndex }}</span>
            </div>
            <div v-if="summaryConfig.showWeekendShare" class="history-timeline-cell">
              <span class="history-timeline-cell__label">Weekend %</span>
              <span class="history-timeline-cell__value">{{ item.weekendShare }}</span>
            </div>
            <div v-if="summaryConfig.showTopCategory" class="history-timeline-cell">
              <span class="history-timeline-cell__label">Top category</span>
              <span class="history-timeline-cell__value">{{ item.topCategoryBrief }}</span>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="time-summary-history__accordion">
        <div class="history-accordion-entry" v-for="item in historyRows" :key="item.offset">
          <button class="history-accordion-entry__header" type="button" @click="toggleAccordion(item.offset)">
            <span class="history-accordion-entry__title">{{ item.label }}</span>
            <span v-if="item.range" class="history-accordion-entry__range">{{ item.range }}</span>
            <span v-if="summaryConfig.showTotal" class="history-accordion-entry__summary">{{ item.totalHours }}</span>
            <span v-if="showDelta && item.deltaHours" class="history-accordion-entry__delta">
              {{ item.deltaHours }}<template v-if="item.deltaPercent"> · {{ item.deltaPercent }}</template>
            </span>
            <span class="history-accordion-entry__caret">{{ isAccordionOpen(item.offset) ? '−' : '+' }}</span>
          </button>
          <div v-if="isAccordionOpen(item.offset)" class="history-accordion-entry__body">
            <div class="history-accordion-group" v-for="section in item.sections" :key="section.key">
              <div class="history-accordion-group__title">{{ section.label }}</div>
              <div class="history-accordion-group__metrics">
                <div class="history-accordion-group__metric" v-for="metric in section.metrics" :key="metric.label">
                  <span class="history-accordion-group__metric-label">{{ metric.label }}</span>
                  <span class="history-accordion-group__metric-value">{{ metric.value }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div v-else-if="showLookbackPanel" class="time-summary-history time-summary-history--empty">
      <div class="time-summary-history__header">
        <div class="time-summary-history__title">Lookback</div>
      </div>
      <div class="time-summary-history__empty">
        <div class="time-summary-history__empty-title">{{ lookbackEmptyTitle }}</div>
        <div class="time-summary-history__empty-copy">{{ lookbackEmptyCopy }}</div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { formatDateOnly, formatDateRange, formatTime } from '../../../services/dateTime'

type Mode = 'active' | 'all'

type SummaryConfig = {
  showTotal: boolean
  showAverage: boolean
  showMedian: boolean
  showBusiest: boolean
  showWorkday: boolean
  showWeekend: boolean
  showWeekendShare: boolean
  showCalendarSummary: boolean
  showTopCategory: boolean
  showBalance: boolean
}

type DisplayMode = 'single_goal' | 'calendar_goals' | 'category_and_calendar_goals'
type OverviewView = 'daily' | 'calendars' | 'categories'

type TodayLane = {
  id: string
  label: string
  todayHours: number
  color?: string | null
  isUnassigned?: boolean
}

type WeekDay = {
  date: string
  label: string
  hours: number
  events?: number
  isToday?: boolean
}

type HistoryEntry = {
  offset: number
  label: string
  rangeStart: string
  rangeEnd: string
  totalHours: number
  avgDay: number
  avgEvent: number
  medianDay: number
  busiest: { date?: string; hours?: number } | null
  workdayAvg: number
  workdayMedian: number
  weekendAvg: number
  weekendMedian: number
  weekendShare: number | null
  activeCalendars: number
  calendarSummary: string
  topCategory: { label: string; actualHours: number; targetHours: number; percent: number; color?: string } | null
  balanceIndex: number | null
  activity: {
    events: number
    activeDays: number
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
}

type HistoryMetric = {
  label: string
  value: string
}

type HistorySection = {
  key: string
  label: string
  metrics: HistoryMetric[]
}

type HistoryRow = {
  offset: number
  label: string
  range: string
  totalHours: string
  avgDay: string
  balanceIndex: string
  weekendShare: string
  topCategoryBrief: string
  deltaHours: string
  deltaPercent: string
  sections: HistorySection[]
}

const defaultConfig: SummaryConfig = {
  showTotal: true,
  showAverage: true,
  showMedian: true,
  showBusiest: true,
  showWorkday: true,
  showWeekend: true,
  showWeekendShare: true,
  showCalendarSummary: true,
  showTopCategory: true,
  showBalance: true,
}

const props = withDefaults(defineProps<{
  summary: {
    rangeLabel: string
    rangeStart: string
    rangeEnd: string
    offset: number
    totalHours: number
    futureHours: number
    avgDay: number
    avgEvent: number
    medianDay: number
    todayActualHours: number
    todayPlannedHours: number
    busiest: { date?: string; hours?: number } | null
    workdayAvg: number
    workdayMedian: number
    weekendAvg: number
    weekendMedian: number
    weekendShare: number | null
    activeCalendars: number
    calendarSummary: string
    balanceIndex: number | null
    delta: {
      totalHours: number
      avgPerDay: number
      avgPerEvent: number
      events: number
    } | null
    topCategory: {
      label: string
      actualHours: number
      targetHours: number
      percent: number
      statusLabel: string
      status: string
      color?: string
    } | null
  }
  activitySummary?: {
    events: number
    activeDays: number | null
    typicalStart: string | null
    typicalEnd: string | null
    weekendShare: number | null
    eveningShare: number | null
    delta: {
      weekendShare: number | null
      eveningShare: number | null
    } | null
    earliestStart: string | null
    latestEnd: string | null
    overlapEvents: number | null
    longestSession: number | null
    lastDayOff: string | null
    lastHalfDayOff: string | null
  } | null
  mode: Mode
  config?: SummaryConfig
  todayGroups?: TodayLane[]
  calendarTodayItems?: TodayLane[]
  categoryTodayItems?: TodayLane[]
  weekDays?: WeekDay[]
  allowedViews?: OverviewView[]
  defaultView?: OverviewView
  showWeekMiniChart?: boolean
  showDailyKpis?: boolean
  showEmptyLanes?: boolean
  maxLanes?: number
  showActivityNote?: boolean
  showRangeInTitle?: boolean
  title?: string
  cardBg?: string | null
  rangeMode?: 'week' | 'month' | string
  rangeStart?: string
  rangeEnd?: string
  offset?: number
  lookbackWeeks?: number
  showHeader?: boolean
  showToday?: boolean
  showActivity?: boolean
  history?: HistoryEntry[]
  showHistoryCoreMetrics?: boolean
  historyView?: 'timeline' | 'accordion' | 'list' | 'pills'
  showActivityDetails?: boolean
  showOverview?: boolean
  showLookback?: boolean
  showDelta?: boolean
  displayMode?: DisplayMode
}>(), {
  showHeader: true,
  showToday: true,
  showActivity: true,
  showHistoryCoreMetrics: true,
  showActivityDetails: true,
  showOverview: true,
  showLookback: false,
  showDelta: true,
  showWeekMiniChart: true,
  showDailyKpis: true,
  showEmptyLanes: true,
  maxLanes: 4,
  showActivityNote: true,
  showRangeInTitle: true,
})

const summaryConfig = computed<SummaryConfig>(() => Object.assign({}, defaultConfig, props.config ?? {}))
const displayMode = computed<DisplayMode>(() => {
  const value = String(props.displayMode ?? '')
  if (value === 'calendar_goals' || value === 'category_and_calendar_goals') return value
  return 'single_goal'
})

const modeLabel = computed(() => (props.mode === 'active' ? 'active days' : 'all days'))

const todayItems = computed(() =>
  (props.todayGroups || [])
    .filter((g) => Number(g.todayHours) > 0)
    .map((g) => ({
      ...g,
      todayHours: Number(g.todayHours) || 0,
      color: g.color || 'var(--brand)',
    })),
)

const todayTotal = computed(() => {
  if (todayItems.value.length) {
    return todayItems.value.reduce((sum, g) => sum + g.todayHours, 0)
  }
  if (Number.isFinite(props.summary.todayActualHours)) {
    return props.summary.todayActualHours
  }
  const v = (props.summary as any)?.todayHours
  return typeof v === 'number' && Number.isFinite(v) ? v : null
})
const todayPlannedHours = computed(() => {
  const value = Number(props.summary?.todayPlannedHours ?? 0)
  return Number.isFinite(value) ? Math.max(0, value) : 0
})

const titleText = computed(() => props.title || 'Time Summary')
const calendarRowLabel = computed(() => displayMode.value === 'single_goal' ? '' : `${props.summary.activeCalendars} calendars`)
const topCategoryLabel = computed(() => displayMode.value === 'category_and_calendar_goals' ? 'Top category' : 'Top focus')
const historyGroupLabel = computed(() => {
  if (displayMode.value === 'calendar_goals') return 'Calendars'
  if (displayMode.value === 'category_and_calendar_goals') return 'Categories & calendars'
  return 'Focus'
})
const headerText = computed(() => {
  const base = titleText.value
  const range = props.summary?.rangeLabel || ''
  return props.showRangeInTitle !== false && range ? `${base} · ${range}` : base
})
const cardStyle = computed(() => ({ background: props.cardBg || undefined }))
const showHeader = computed(() => props.showHeader)
const showToday = computed(() => props.showToday)
const showActivity = computed(() => props.showActivity)
const showHistoryCoreMetrics = computed(() => props.showHistoryCoreMetrics)
const historyView = computed(() => {
  const value = String(props.historyView ?? '').toLowerCase()
  if (value === 'timeline' || value === 'list') return 'timeline'
  return 'accordion'
})
const historyViewLabel = computed(() => (historyView.value === 'accordion' ? 'Accordion' : 'Timeline'))
const showActivityDetails = computed(() => props.showActivityDetails)
const showOverviewPanel = computed(() => props.showOverview !== false)
const showLookbackPanel = computed(() => props.showLookback !== false)
const showDelta = computed(() => props.showDelta !== false)
const showWeekMiniChart = computed(() => props.showWeekMiniChart !== false)
const showDailyKpis = computed(() => props.showDailyKpis !== false)
const showEmptyLanes = computed(() => props.showEmptyLanes !== false)
const showActivityNote = computed(() => props.showActivityNote !== false)
const maxLanes = computed(() => {
  const value = Number(props.maxLanes ?? 4)
  return Number.isFinite(value) ? Math.max(1, Math.min(12, Math.trunc(value))) : 4
})
const availableViews = computed<OverviewView[]>(() => {
  const raw = Array.isArray(props.allowedViews) ? props.allowedViews : []
  const valid = raw.filter((view): view is OverviewView => view === 'daily' || view === 'calendars' || view === 'categories')
  if (valid.length) return valid
  if (displayMode.value === 'category_and_calendar_goals') return ['daily', 'calendars', 'categories']
  if (displayMode.value === 'calendar_goals') return ['daily', 'calendars']
  return ['daily']
})
const activeOverviewView = ref<OverviewView>('daily')
const overviewViewInitialized = ref(false)
const defaultOverviewView = computed<OverviewView>(() => {
  const requested = props.defaultView
  if (requested && availableViews.value.includes(requested)) return requested
  if (displayMode.value === 'category_and_calendar_goals' && availableViews.value.includes('categories')) return 'categories'
  if (displayMode.value === 'calendar_goals' && availableViews.value.includes('calendars')) return 'calendars'
  return 'daily'
})
watch(
  [availableViews, defaultOverviewView],
  ([views, view]) => {
    if (!overviewViewInitialized.value) {
      activeOverviewView.value = view
      overviewViewInitialized.value = true
      return
    }
    if (!views.includes(activeOverviewView.value)) {
      activeOverviewView.value = view
    }
  },
  { immediate: true },
)
const showTabs = computed(() => availableViews.value.length > 1)
const weekDays = computed<WeekDay[]>(() => Array.isArray(props.weekDays) ? props.weekDays : [])
const todayWeekEntry = computed(() => weekDays.value.find((day) => day.isToday) ?? null)
const todayEvents = computed(() => {
  const events = Number(todayWeekEntry.value?.events ?? NaN)
  if (Number.isFinite(events)) return Math.max(0, Math.trunc(events))
  return Math.max(0, Math.trunc(Number(activity.value?.events ?? 0)))
})
const todayAvgEvent = computed(() => {
  if (todayEvents.value > 0 && todayTotal.value != null) return todayTotal.value / todayEvents.value
  if (todayTotal.value != null) return 0
  return props.summary.avgEvent
})
const weekMaxHours = computed(() => Math.max(0, ...weekDays.value.map((day) => Number(day.hours) || 0)))
const avgDayDeltaLabel = computed(() => {
  const delta = props.summary.delta?.avgPerDay
  if (delta == null || !Number.isFinite(delta) || Math.abs(delta) < 0.005) return ''
  return `${delta > 0 ? '+' : '−'}${n2(Math.abs(delta))}h`
})
const calendarTodayItems = computed<TodayLane[]>(() => normalizeLaneList(props.calendarTodayItems ?? todayItems.value))
const categoryTodayItems = computed<TodayLane[]>(() => normalizeLaneList(props.categoryTodayItems ?? todayItems.value))
const calendarTodayFiltered = computed(() => filterLaneList(calendarTodayItems.value))
const categoryTodayFiltered = computed(() => filterLaneList(categoryTodayItems.value))
const calendarTodayVisible = computed(() => calendarTodayFiltered.value.slice(0, maxLanes.value))
const categoryTodayVisible = computed(() => categoryTodayFiltered.value.slice(0, maxLanes.value))
const calendarTodayMoreCount = computed(() => Math.max(0, calendarTodayFiltered.value.length - calendarTodayVisible.value.length))
const categoryTodayMoreCount = computed(() => Math.max(0, categoryTodayFiltered.value.length - categoryTodayVisible.value.length))
const configuredLookbackWeeks = computed(() => {
  const value = Number(props.lookbackWeeks ?? 1)
  return Number.isFinite(value) ? Math.max(1, Math.trunc(value)) : 1
})
const lookbackNeedsConfig = computed(() => configuredLookbackWeeks.value <= 1)
const lookbackUnitLabel = computed(() => String(props.rangeMode).toLowerCase() === 'month' ? 'months' : 'weeks')
const lookbackEmptyTitle = computed(() =>
  lookbackNeedsConfig.value ? 'Lookback data required' : 'No lookback data available',
)
const lookbackEmptyCopy = computed(() => {
  if (lookbackNeedsConfig.value) {
    return `Increase trend lookback above 1 to compare previous ${lookbackUnitLabel.value} here.`
  }
  return `No previous ${lookbackUnitLabel.value} are available for this comparison yet.`
})
const offsetBase = computed(() =>
  Number.isFinite(props.summary.offset) ? props.summary.offset : (props.offset ?? 0),
)
const comparisonOffset = computed(() => offsetBase.value - 1)
const comparisonOffsetLabel = computed(() => formatOffset(comparisonOffset.value))
const activityOffsetSuffix = computed(() => {
  if (offsetBase.value === 0) return ''
  return ` (offset ${formatOffset(offsetBase.value)})`
})

const weekendShareText = computed(() => {
  if (!summaryConfig.value.showWeekendShare) return ''
  const v = props.summary.weekendShare
  return v == null ? '' : `${n1(v)}%`
})

const topCategoryInfo = computed(() => {
  if (!summaryConfig.value.showTopCategory) return null
  const cat = props.summary.topCategory
  if (!cat) return null
  const actual = n2(cat.actualHours)
  const percent = Number(cat.percent ?? 0).toFixed(0)
  const targetPart = cat.targetHours > 0 ? ` (${percent}% of ${n2(cat.targetHours)} h)` : ''
  return {
    text: `${cat.label} — ${actual} h${targetPart}`,
    badge: cat.statusLabel || '',
    badgeClass: statusClass(cat.status as any),
  }
})

const busiestText = computed(() => {
  if (!summaryConfig.value.showBusiest) return ''
  const b = props.summary.busiest
  if (!b || !b.date) {
    return ''
  }
  const hours = Number(b.hours ?? 0)
  return `Busiest ${b.date} — ${n2(hours)} h`
})

const inlineStats = computed(() => {
  const parts: string[] = []
  if (summaryConfig.value.showAverage) {
    parts.push(`${n2(props.summary.avgDay)} h/day (${modeLabel.value})`)
    parts.push(`${n2(props.summary.avgEvent)} h/event`)
  }
  if (summaryConfig.value.showMedian) {
    parts.push(`${n2(props.summary.medianDay)} h median/day`)
  }
  return parts.filter(Boolean).join(' · ')
})

const activity = computed(() => props.activitySummary ?? null)
const lastDayOffLabel = computed(() => activity.value?.lastDayOff || '—')
const typicalWindow = computed(() => {
  const start = timeOf(activity.value?.typicalStart ?? null)
  const end = timeOf(activity.value?.typicalEnd ?? null)
  if (start && end) return `${start}–${end}`
  if (start) return `${start}→`
  if (end) return `→${end}`
  return '—'
})
const earliestLatestLabel = computed(() => {
  const earliest = timeOf(activity.value?.earliestStart ?? null)
  const latest = timeOf(activity.value?.latestEnd ?? null)
  if (!earliest && !latest) return '—'
  return `${earliest || '—'} / ${latest || '—'}`
})
const longestSessionLabel = computed(() => {
  const longest = activity.value?.longestSession
  if (longest == null) return '—'
  return `${Number(longest).toFixed(1)} h`
})
const latestEndShort = computed(() => {
  const value = timeOf(activity.value?.latestEnd ?? null)
  return value || '—'
})
const activeDaysLabel = computed(() => {
  const value = activity.value?.activeDays
  if (value == null || !Number.isFinite(Number(value))) return '—'
  return String(Math.max(0, Math.trunc(Number(value))))
})
const activityNote = computed(() => {
  const parts: string[] = []
  if (busiestText.value) parts.push(busiestText.value.replace(/^Busiest\s+/, 'busiest '))
  if (activity.value?.activeDays != null) parts.push(`${activity.value.activeDays} active days`)
  if (activity.value?.weekendShare != null) parts.push(`weekend ${pct(activity.value.weekendShare)}`)
  if (activity.value?.eveningShare != null) parts.push(`evening ${pct(activity.value.eveningShare)}`)
  return parts.join(' · ')
})
const weekendDeltaLabel = computed(() => {
  if (!activity.value?.delta) return ''
  return shareDeltaLabel(activity.value.weekendShare, activity.value.delta.weekendShare)
})
const eveningDeltaLabel = computed(() => {
  if (!activity.value?.delta) return ''
  return shareDeltaLabel(activity.value.eveningShare, activity.value.delta.eveningShare)
})

const historyRows = computed<HistoryRow[]>(() => {
  const history = Array.isArray(props.history) ? props.history : []
  return history.map((entry, idx) => {
    const previous = history[idx + 1]
    const deltaHours = previous ? Number(entry.totalHours) - Number(previous.totalHours) : null
    const deltaPercent = previous && Number(previous.totalHours) !== 0
      ? (Number(deltaHours) / Number(previous.totalHours)) * 100
      : null

    const coreMetrics: HistoryMetric[] = []
    if (summaryConfig.value.showTotal) {
      coreMetrics.push({ label: 'Total', value: `${n2(entry.totalHours)} h` })
    }
    if (summaryConfig.value.showAverage) {
      coreMetrics.push({ label: `Avg/day (${modeLabel.value})`, value: `${n2(entry.avgDay)} h` })
      coreMetrics.push({ label: 'Avg/event', value: `${n2(entry.avgEvent)} h` })
    }
    if (summaryConfig.value.showMedian) {
      coreMetrics.push({ label: 'Median/day', value: `${n2(entry.medianDay)} h` })
    }
    if (summaryConfig.value.showBalance) {
      coreMetrics.push({ label: 'Balance index', value: entry.balanceIndex == null ? '—' : entry.balanceIndex.toFixed(2) })
    }

    const paceMetrics: HistoryMetric[] = []
    if (summaryConfig.value.showWorkday) {
      paceMetrics.push({
        label: 'Workdays',
        value: `${n2(entry.workdayAvg)} h avg · ${n2(entry.workdayMedian)} h median`,
      })
    }
    if (summaryConfig.value.showWeekend) {
      const share = summaryConfig.value.showWeekendShare && entry.weekendShare != null
        ? ` (${n1(entry.weekendShare)}%)`
        : ''
      paceMetrics.push({
        label: 'Weekend',
        value: `${n2(entry.weekendAvg)} h avg · ${n2(entry.weekendMedian)} h median${share}`,
      })
    }

    const categoryMetrics: HistoryMetric[] = []
    if (summaryConfig.value.showTopCategory) {
      categoryMetrics.push({ label: 'Top category', value: formatTopCategory(entry.topCategory) })
    }
    if (summaryConfig.value.showCalendarSummary) {
      categoryMetrics.push({
        label: `${entry.activeCalendars} calendars`,
        value: entry.calendarSummary || '—',
      })
    }

    const patternMetrics: HistoryMetric[] = []
    if (summaryConfig.value.showBusiest) {
      patternMetrics.push({ label: 'Busiest', value: formatBusiest(entry.busiest) })
    }
    if (showHistoryCoreMetrics.value) {
      patternMetrics.push({ label: 'Events', value: String(entry.activity?.events ?? 0) })
      patternMetrics.push({ label: 'Active days', value: String(entry.activity?.activeDays ?? 0) })
      patternMetrics.push({ label: 'Typical', value: formatTypical(entry.activity?.typicalStart, entry.activity?.typicalEnd) })
    }
    if (showActivityDetails.value) {
      patternMetrics.push({
        label: 'Weekend share',
        value: entry.activity?.weekendShare == null ? '—' : `${n1(entry.activity.weekendShare)}%`,
      })
      patternMetrics.push({
        label: 'Evening share',
        value: entry.activity?.eveningShare == null ? '—' : `${n1(entry.activity.eveningShare)}%`,
      })
      patternMetrics.push({
        label: 'Earliest/Late',
        value: formatEarliestLatest(entry.activity?.earliestStart, entry.activity?.latestEnd),
      })
      patternMetrics.push({ label: 'Overlaps', value: String(entry.activity?.overlapEvents ?? 0) })
      patternMetrics.push({ label: 'Longest', value: formatLongest(entry.activity?.longestSession) })
      patternMetrics.push({ label: 'Last day off', value: entry.activity?.lastDayOff || '—' })
      patternMetrics.push({ label: 'Last half day', value: entry.activity?.lastHalfDayOff || '—' })
    }

    const sections: HistorySection[] = [
      { key: 'core', label: 'Core', metrics: coreMetrics },
      { key: 'pace', label: 'Pace', metrics: paceMetrics },
      { key: 'category', label: historyGroupLabel.value, metrics: categoryMetrics },
      { key: 'pattern', label: 'Pattern', metrics: patternMetrics },
    ].filter((section) => section.metrics.length > 0)

    return {
      offset: entry.offset,
      label: entry.label || `Offset ${formatOffset(entry.offset)}`,
      range: formatRangeSpan(entry.rangeStart, entry.rangeEnd),
      totalHours: `${n2(entry.totalHours)} h`,
      avgDay: `${n2(entry.avgDay)} h`,
      balanceIndex: entry.balanceIndex == null ? '—' : entry.balanceIndex.toFixed(2),
      weekendShare: entry.weekendShare == null ? '—' : `${n1(entry.weekendShare)}%`,
      topCategoryBrief: formatTopCategoryBrief(entry.topCategory),
      deltaHours: formatSignedHours(deltaHours),
      deltaPercent: formatSignedPercent(deltaPercent),
      sections,
    }
  })
})

const activeAccordionOffset = ref<number | null>(null)
watch(
  historyRows,
  (rows) => {
    if (!rows.length) {
      activeAccordionOffset.value = null
      return
    }
    if (!rows.some((row) => row.offset === activeAccordionOffset.value)) {
      activeAccordionOffset.value = rows[0].offset
    }
  },
  { immediate: true },
)

function isAccordionOpen(offset: number) {
  return activeAccordionOffset.value === offset
}

function toggleAccordion(offset: number) {
  activeAccordionOffset.value = activeAccordionOffset.value === offset ? null : offset
}

function viewLabel(view: OverviewView) {
  if (view === 'calendars') return 'Calendars'
  if (view === 'categories') return 'Categories'
  return 'Daily'
}

function normalizeLaneList(input: TodayLane[]) {
  return (Array.isArray(input) ? input : [])
    .map((item) => ({
      ...item,
      id: String(item?.id ?? ''),
      label: String(item?.label ?? item?.id ?? ''),
      todayHours: Number(item?.todayHours ?? 0) || 0,
      color: item?.color || 'var(--brand)',
    }))
    .filter((item) => item.id && item.label)
    .sort((left, right) => Number(right.todayHours ?? 0) - Number(left.todayHours ?? 0))
}

function filterLaneList(input: TodayLane[]) {
  return showEmptyLanes.value ? input : input.filter((item) => Number(item.todayHours) > 0)
}

function dayHeight(hours: number) {
  const value = Math.max(0, Number(hours) || 0)
  const max = weekMaxHours.value
  if (max <= 0) return 8
  return Math.max(8, Math.min(100, (value / max) * 100))
}

function n1(v: unknown) {
  return Number(v ?? 0).toFixed(1)
}
function n2(v: unknown) {
  return Number(v ?? 0).toFixed(2)
}

function timeOf(value: string | null | undefined) {
  if (!value) return ''
  if (/^\d{2}:\d{2}$/.test(value)) return value
  return formatTime(value)
}

function statusClass(status: 'on_track' | 'at_risk' | 'behind' | 'done' | 'none' | string | undefined): string {
  switch (status) {
    case 'on_track': return 'status-on'
    case 'at_risk': return 'status-risk'
    case 'behind': return 'status-behind'
    case 'done': return 'status-done'
  default: return 'status-none'
  }
}

function pct(value: number | null | undefined) {
  if (value == null) return '0.0%'
  const num = Math.max(0, Math.min(100, Number(value)))
  return `${num.toFixed(1)}%`
}

function formatOffset(offset: number) {
  if (offset === 0) return '0'
  return offset > 0 ? `+${offset}` : `${offset}`
}

function formatRangeSpan(startValue?: string, endValue?: string) {
  if (!startValue || !endValue) return ''
  return formatDateRange(startValue, endValue, { month: 'short', day: 'numeric' })
}

function formatBusiest(busiest: { date?: string; hours?: number } | null) {
  if (!busiest?.date) return '—'
  const hours = Number(busiest?.hours ?? 0)
  const label = formatDateOnly(busiest.date, { month: 'short', day: 'numeric' }) || busiest.date
  return `${label} · ${n2(hours)} h`
}

function formatTopCategory(cat: HistoryEntry['topCategory']) {
  if (!cat) return '—'
  const targetPart = cat.targetHours > 0 ? ` (${Math.round(cat.percent)}% of ${n2(cat.targetHours)} h)` : ''
  return `${cat.label} — ${n2(cat.actualHours)} h${targetPart}`
}

function formatTopCategoryBrief(cat: HistoryEntry['topCategory']) {
  if (!cat) return '—'
  return `${cat.label} ${Math.round(cat.percent)}%`
}

function formatSignedHours(value: number | null) {
  if (value == null || !Number.isFinite(value)) return ''
  const sign = value >= 0 ? '+' : '−'
  return `${sign}${n2(Math.abs(value))} h`
}

function formatSignedPercent(value: number | null) {
  if (value == null || !Number.isFinite(value)) return ''
  const sign = value >= 0 ? '+' : '−'
  return `${sign}${Math.abs(value).toFixed(1)}%`
}

function formatTypical(start?: string | null, end?: string | null) {
  const s = timeOf(start || null)
  const e = timeOf(end || null)
  if (s && e) return `${s}–${e}`
  if (s) return `${s}→`
  if (e) return `→${e}`
  return '—'
}

function formatEarliestLatest(earliest?: string | null, latest?: string | null) {
  const s = timeOf(earliest || null)
  const e = timeOf(latest || null)
  if (!s && !e) return '—'
  return `${s || '—'} / ${e || '—'}`
}

function formatLongest(value?: number | null) {
  if (value == null) return '—'
  return `${Number(value).toFixed(1)} h`
}

function shareDeltaLabel(current: number | null | undefined, delta: number | null | undefined) {
  if (delta == null || current == null) return ''
  const prev = current - delta
  return `Δ vs. offset ${comparisonOffsetLabel.value} → ${pct(prev)}`
}
</script>

<style scoped>
.card.time-summary {
  display: flex;
  flex-direction: column;
  gap: var(--widget-gap, 6px);
  font-size: var(--widget-font, 13px);
  color: var(--muted);
  --widget-pad: calc(14px * var(--widget-space, 1));
  padding: var(--widget-pad, 14px);
}
.time-summary-daily {
  display: grid;
  gap: calc(12px * var(--widget-space, 1));
}
.time-summary-hero {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: calc(14px * var(--widget-space, 1));
  align-items: end;
  padding: calc(14px * var(--widget-space, 1));
  border-radius: calc(14px * var(--widget-space, 1));
  background-color: var(--card, #fff);
  background-image: radial-gradient(120% 90% at 0% 0%, color-mix(in oklab, var(--brand, #2563eb) 10%, transparent), transparent 32%);
  border: 1px solid color-mix(in oklab, var(--brand, #2563eb) 20%, var(--line, #e5e7eb));
  box-shadow: inset 2px 0 0 color-mix(in oklab, var(--brand, #2563eb) 55%, transparent);
  color: var(--fg);
}
.time-summary-hero__label {
  margin-bottom: calc(6px * var(--widget-space, 1));
  color: color-mix(in oklab, var(--fg), transparent 38%);
  font-size: calc(11px * var(--widget-scale, 1));
  font-weight: 800;
  letter-spacing: .08em;
  text-transform: uppercase;
}
.time-summary-hero__value {
  color: var(--fg);
  font-size: calc(42px * var(--widget-scale, 1));
  line-height: .9;
  letter-spacing: -.07em;
  font-weight: 850;
}
.time-summary-hero__value span {
  margin-left: calc(4px * var(--widget-space, 1));
  color: var(--muted);
  font-size: calc(17px * var(--widget-scale, 1));
  letter-spacing: -.03em;
}
.time-summary-hero__planned {
  margin-top: calc(6px * var(--widget-space, 1));
  color: var(--muted);
  font-size: calc(12px * var(--widget-scale, 1));
}
.time-summary-hero__side {
  display: grid;
  justify-items: end;
  color: var(--muted);
  font-size: calc(11px * var(--widget-scale, 1));
  line-height: 1.35;
  text-align: right;
}
.time-summary-hero__side strong {
  color: var(--fg);
  font-size: calc(22px * var(--widget-scale, 1));
  line-height: 1;
  letter-spacing: -.04em;
}
.time-summary-tabs {
  display: grid;
  gap: calc(6px * var(--widget-space, 1));
  padding: calc(5px * var(--widget-space, 1));
  border-radius: calc(14px * var(--widget-space, 1));
  background: color-mix(in oklab, var(--fg) 5%, transparent);
  border: 1px solid color-mix(in oklab, var(--line, #e5e7eb), transparent 35%);
}
.time-summary-tabs--2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.time-summary-tabs--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.time-summary-tabs button {
  border: 0;
  border-radius: calc(10px * var(--widget-space, 1));
  padding: calc(7px * var(--widget-space, 1)) calc(6px * var(--widget-space, 1));
  background: transparent;
  color: var(--muted);
  font: inherit;
  font-size: calc(11px * var(--widget-scale, 1));
  font-weight: 800;
  cursor: pointer;
}
.time-summary-tabs button.active {
  color: var(--brand);
  background: color-mix(in oklab, var(--brand, #2563eb) 16%, transparent);
  box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--brand, #2563eb), transparent 72%);
}
.time-summary-section-head {
  display: flex;
  justify-content: space-between;
  gap: calc(10px * var(--widget-space, 1));
  align-items: end;
  color: var(--muted);
  font-size: calc(11px * var(--widget-scale, 1));
  letter-spacing: .06em;
  text-transform: uppercase;
}
.time-summary-section-head strong {
  color: var(--fg);
  font-size: calc(12px * var(--widget-scale, 1));
  letter-spacing: 0;
  text-transform: none;
}
.time-summary-lanes {
  display: grid;
  gap: calc(8px * var(--widget-space, 1));
}
.time-summary-tab-panel {
  display: grid;
  min-height: calc(142px * var(--widget-space, 1));
}
.time-summary-lane {
  --lane-color: var(--brand, #2563eb);
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: calc(9px * var(--widget-space, 1));
  align-items: center;
  min-height: calc(36px * var(--widget-space, 1));
  padding: calc(8px * var(--widget-space, 1)) calc(10px * var(--widget-space, 1));
  border-radius: calc(10px * var(--widget-space, 1));
  background: color-mix(in oklab, var(--card, #fff) 96%, var(--fg) 4%);
  border: 1px solid color-mix(in oklab, var(--lane-color) 18%, var(--line, #e5e7eb));
  box-shadow: inset 2px 0 0 color-mix(in oklab, var(--lane-color) 60%, transparent);
}
.time-summary-lane .name {
  min-width: 0;
  color: var(--fg);
  font-weight: 700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.time-summary-lane .value {
  color: var(--muted);
  font-size: calc(12px * var(--widget-scale, 1));
  font-variant-numeric: tabular-nums;
}
.time-summary-more {
  color: var(--muted);
  font-size: calc(12px * var(--widget-scale, 1));
}
.time-summary-kpis {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: calc(9px * var(--widget-space, 1));
}
.time-summary-kpi {
  min-height: calc(62px * var(--widget-space, 1));
  padding: calc(10px * var(--widget-space, 1));
  border-radius: calc(14px * var(--widget-space, 1));
  border: 1px solid color-mix(in oklab, var(--line, #e5e7eb), transparent 28%);
  background: color-mix(in oklab, var(--card, #fff) 94%, var(--fg) 6%);
}
.time-summary-kpi strong {
  display: block;
  color: var(--fg);
  font-size: calc(18px * var(--widget-scale, 1));
  letter-spacing: -.04em;
}
.time-summary-kpi span {
  color: var(--muted);
  font-size: calc(11px * var(--widget-scale, 1));
}
.time-summary-kpi--compare {
  position: relative;
  background:
    linear-gradient(135deg, color-mix(in oklab, var(--brand, #2563eb) 10%, transparent), transparent 62%),
    color-mix(in oklab, var(--card, #fff) 94%, var(--fg) 6%);
}
.time-summary-kpi--compare em {
  position: absolute;
  right: calc(10px * var(--widget-space, 1));
  bottom: calc(10px * var(--widget-space, 1));
  color: color-mix(in oklab, var(--brand, #2563eb) 78%, var(--fg));
  font-size: calc(11px * var(--widget-scale, 1));
  font-style: normal;
  font-weight: 850;
  letter-spacing: -.02em;
}
.time-summary-week {
  --week-chart-accent: var(--brand, #2563eb);
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: calc(6px * var(--widget-space, 1));
  align-items: end;
  height: calc(88px * var(--widget-space, 1));
  padding: calc(10px * var(--widget-space, 1)) calc(10px * var(--widget-space, 1)) calc(6px * var(--widget-space, 1));
  border-radius: calc(12px * var(--widget-space, 1));
  border: 1px solid color-mix(in oklab, var(--line, #e5e7eb), transparent 45%);
  background: color-mix(in oklab, var(--fg) 3%, transparent);
}
.time-summary-week__day {
  height: 100%;
  display: flex;
  flex-direction: column;
  justify-content: end;
  gap: calc(7px * var(--widget-space, 1));
  color: var(--muted);
  font-size: calc(10px * var(--widget-scale, 1));
  text-align: center;
  position: relative;
}
.time-summary-week__day::before {
  content: '';
  position: absolute;
  left: 6%;
  right: 6%;
  bottom: calc(20px * var(--widget-space, 1));
  height: 1px;
  background: color-mix(in oklab, var(--line, #e5e7eb), transparent 35%);
}
.time-summary-week__day::after {
  content: '';
  position: absolute;
  left: 12%;
  right: 12%;
  top: calc(2px * var(--widget-space, 1));
  bottom: calc(20px * var(--widget-space, 1));
  border-radius: calc(4px * var(--widget-space, 1));
  background: color-mix(in oklab, var(--fg) 3%, transparent);
}
.time-summary-week__day i {
  display: block;
  width: 100%;
  min-height: calc(18px * var(--widget-space, 1));
  border-radius: calc(6px * var(--widget-space, 1)) calc(6px * var(--widget-space, 1)) calc(4px * var(--widget-space, 1)) calc(4px * var(--widget-space, 1));
  background: linear-gradient(180deg, color-mix(in oklab, var(--week-chart-accent) 82%, white 18%), var(--week-chart-accent));
  position: relative;
  overflow: hidden;
  z-index: 1;
  box-shadow: 0 2px 6px color-mix(in oklab, var(--week-chart-accent) 18%, transparent);
  transition: transform .16s ease;
}
.time-summary-week__day:hover i {
  transform: translateY(-2px);
  filter: saturate(1.08);
}
.time-summary-week__bar strong {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  color: #fff;
  font-size: calc(9px * var(--widget-scale, 1));
  font-weight: 800;
  letter-spacing: -.03em;
  line-height: 1;
  text-shadow: 0 1px 4px rgba(15, 23, 42, .38);
  white-space: nowrap;
}
.time-summary-week__day span {
  position: relative;
  z-index: 1;
}
.time-summary-week__day.active i {
  background: linear-gradient(180deg, color-mix(in oklab, #38bdf8 30%, var(--week-chart-accent)), var(--week-chart-accent));
  box-shadow:
    0 3px 8px color-mix(in oklab, var(--week-chart-accent) 24%, transparent),
    0 0 0 1px color-mix(in oklab, var(--week-chart-accent) 28%, transparent);
}
.time-summary-week__day.active span {
  color: var(--fg);
  font-weight: 800;
}
.time-summary-activity-note {
  padding-top: calc(8px * var(--widget-space, 1));
  border-top: 1px solid var(--line);
  color: var(--muted);
  font-size: calc(12px * var(--widget-scale, 1));
  line-height: 1.45;
}
.today-highlight{
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:calc(10px * var(--widget-space, 1)) calc(12px * var(--widget-space, 1));
  border-radius:calc(10px * var(--widget-space, 1));
  background: color-mix(in oklab, var(--brand, #2563eb) 14%, transparent);
  border:1px solid color-mix(in oklab, var(--brand, #2563eb), transparent 70%);
  color: var(--fg);
}
.today-label{
  font-size:calc(12px * var(--widget-scale, 1));
  text-transform:uppercase;
  letter-spacing:0.04em;
  color: color-mix(in oklab, var(--fg), transparent 35%);
}
.today-value{
  font-size:calc(18px * var(--widget-scale, 1));
  font-weight:700;
  letter-spacing:-0.02em;
}
.today-cats{
  display:flex;
  flex-wrap:wrap;
  gap:calc(8px * var(--widget-space, 1)) calc(12px * var(--widget-space, 1));
  font-size:calc(12px * var(--widget-scale, 1));
  color: var(--fg);
}
.today-cat{
  display:inline-flex;
  align-items:center;
  gap:calc(6px * var(--widget-space, 1));
  padding:calc(4px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  border-radius:calc(8px * var(--widget-space, 1));
  background: color-mix(in oklab, var(--card, #fff), transparent 6%);
  border:1px solid color-mix(in oklab, var(--line, #e5e7eb), transparent 30%);
}
.today-cat .dot{
  width:calc(9px * var(--widget-space, 1));
  height:calc(9px * var(--widget-space, 1));
  border-radius:50%;
  box-shadow:0 0 0 1px color-mix(in oklab, var(--fg) 10%, transparent);
}
.today-cat .unassigned-hint{ font-size:calc(11px * var(--widget-scale,1)); color:var(--muted); cursor:default; opacity:.7 }
.today-cat .name{
  font-weight:600;
}
.today-cat .value{
  color: var(--muted);
}
.time-summary-firstline {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--widget-gap, 8px);
  font-size: var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
  color: var(--fg);
  font-weight: 600;
}
.mode-pill {
  display: inline-flex;
  align-items: center;
  padding: calc(2px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  font-size: calc(11px * var(--widget-scale, 1));
  font-weight: 600;
  border-radius: 999px;
  background: color-mix(in srgb, var(--brand) 18%, white);
  color: var(--brand);
  text-transform: uppercase;
  letter-spacing: .05em;
}
.time-summary-metrics {
  display: grid;
  gap: calc(4px * var(--widget-space, 1));
  margin: 0;
  padding: 0;
  list-style: none;
}
.time-summary-metrics li {
  display: flex;
  flex-wrap: wrap;
  gap: calc(4px * var(--widget-space, 1));
  color: var(--muted);
}
.time-summary-metrics strong {
  color: var(--fg);
  font-size: calc(14px * var(--widget-scale, 1));
}
.time-summary-inline {
  font-size: calc(13px * var(--widget-scale, 1));
  color: var(--fg);
}
.time-summary-row {
  display: flex;
  flex-wrap: wrap;
  gap: calc(4px * var(--widget-space, 1));
  line-height: 1.4;
  color: var(--muted);
}
.time-summary-row .label {
  color: var(--fg);
  font-weight: 600;
}
.time-summary-row.calendars {
  word-break: break-word;
}
.time-summary-row .sep {
  color: var(--muted);
}
.time-summary-row .hint {
  color: var(--muted);
}
.time-summary-row .share {
  color: var(--muted);
}
.time-summary-row.top-category {
  align-items: center;
  gap: calc(6px * var(--widget-space, 1));
}
.time-summary-activity {
  margin-top: calc(6px * var(--widget-space, 1));
  padding-top: calc(6px * var(--widget-space, 1));
  border-top: 1px solid var(--line);
  display: grid;
  gap: calc(2px * var(--widget-space, 1));
  font-size: calc(12px * var(--widget-scale, 1));
  color: var(--muted);
}
.time-summary-activity__title {
  font-weight: 600;
  color: var(--fg);
}
.time-summary-activity__line {
  display: flex;
  flex-wrap: wrap;
  gap: calc(6px * var(--widget-space, 1));
}
.time-summary-activity__delta {
  color: var(--muted);
}
.time-summary-history {
  margin-top: calc(8px * var(--widget-space, 1));
  padding-top: calc(8px * var(--widget-space, 1));
  border-top: 1px solid var(--line);
  display: grid;
  gap: calc(8px * var(--widget-space, 1));
}
.time-summary-history--empty {
  gap: calc(10px * var(--widget-space, 1));
}
.time-summary-history__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: calc(8px * var(--widget-space, 1));
}
.time-summary-history__title {
  font-weight: 600;
  color: var(--fg);
}
.time-summary-history__mode {
  font-size: calc(11px * var(--widget-scale, 1));
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--muted);
}
.time-summary-history__empty {
  display: grid;
  gap: calc(4px * var(--widget-space, 1));
  padding: calc(10px * var(--widget-space, 1)) calc(12px * var(--widget-space, 1));
  border-radius: calc(12px * var(--widget-space, 1));
  border: 1px dashed color-mix(in oklab, var(--line, #e5e7eb), transparent 10%);
  background: color-mix(in oklab, var(--card, #fff), transparent 4%);
}
.time-summary-history__empty-title {
  color: var(--fg);
  font-weight: 600;
}
.time-summary-history__empty-copy {
  color: var(--muted);
  line-height: 1.45;
}
.time-summary-history__timeline {
  display: grid;
  gap: calc(10px * var(--widget-space, 1));
}
.history-timeline-entry {
  border: 1px solid color-mix(in oklab, var(--line, #e5e7eb), transparent 20%);
  border-radius: calc(12px * var(--widget-space, 1));
  padding: calc(10px * var(--widget-space, 1));
  background: color-mix(in oklab, var(--card, #fff), transparent 6%);
}
.history-timeline-entry__header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: calc(6px * var(--widget-space, 1)) calc(10px * var(--widget-space, 1));
  margin-bottom: calc(8px * var(--widget-space, 1));
}
.history-timeline-entry__title {
  font-weight: 600;
  color: var(--fg);
}
.history-timeline-entry__range {
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--muted);
}
.history-timeline-entry__delta {
  margin-left: auto;
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--muted);
}
.history-timeline-entry__metrics {
  display: grid;
  gap: calc(6px * var(--widget-space, 1));
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
}
.history-timeline-cell {
  display: flex;
  flex-direction: column;
  gap: calc(2px * var(--widget-space, 1));
}
.history-timeline-cell__label {
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.history-timeline-cell__value {
  font-size: calc(12px * var(--widget-scale, 1));
  color: var(--fg);
  font-weight: 600;
}

.time-summary-history__accordion {
  display: grid;
  gap: calc(10px * var(--widget-space, 1));
}
.history-accordion-entry {
  border: 1px solid color-mix(in oklab, var(--line, #e5e7eb), transparent 20%);
  border-radius: calc(12px * var(--widget-space, 1));
  background: color-mix(in oklab, var(--card, #fff), transparent 6%);
}

.history-accordion-entry__header {
  width: 100%;
  display: grid;
  grid-template-columns: 1fr auto auto auto auto;
  align-items: center;
  gap: calc(8px * var(--widget-space, 1));
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
  padding: calc(10px * var(--widget-space, 1));
  cursor: pointer;
}
.history-accordion-entry__title {
  font-weight: 600;
  color: var(--fg);
}
.history-accordion-entry__range {
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--muted);
}
.history-accordion-entry__summary {
  font-size: calc(12px * var(--widget-scale, 1));
  color: var(--fg);
  font-weight: 600;
}
.history-accordion-entry__delta {
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--muted);
}
.history-accordion-entry__caret {
  font-size: calc(14px * var(--widget-scale, 1));
  color: var(--muted);
  line-height: 1;
}
.history-accordion-entry__body {
  border-top: 1px solid color-mix(in oklab, var(--line, #e5e7eb), transparent 25%);
  padding: calc(8px * var(--widget-space, 1)) calc(10px * var(--widget-space, 1)) calc(10px * var(--widget-space, 1));
  display: grid;
  gap: calc(8px * var(--widget-space, 1));
}
.history-accordion-group {
  display: grid;
  gap: calc(4px * var(--widget-space, 1));
}
.history-accordion-group__title {
  font-size: calc(11px * var(--widget-scale, 1));
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--muted);
}
.history-accordion-group__metrics {
  display: grid;
  gap: calc(6px * var(--widget-space, 1));
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}
.history-accordion-group__metric {
  display: grid;
  gap: calc(2px * var(--widget-space, 1));
}
.history-accordion-group__metric-label {
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--muted);
}
.history-accordion-group__metric-value {
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--fg);
  font-weight: 600;
}
.summary-badge {
  display: inline-flex;
  align-items: center;
  gap: calc(4px * var(--widget-space, 1));
  padding: calc(2px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  border-radius: 999px;
  font-size: calc(11px * var(--widget-scale, 1));
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.status-on {
  background: color-mix(in srgb, var(--brand) 20%, white);
  color: var(--brand);
}
.status-risk {
  background: color-mix(in srgb, #f97316 20%, white);
  color: #f97316;
}
.status-behind {
  background: color-mix(in srgb, #ef4444 20%, white);
  color: #ef4444;
}
.status-done {
  background: color-mix(in srgb, var(--pos) 25%, white);
  color: var(--pos);
}
.status-none {
  background: color-mix(in srgb, var(--muted) 12%, white);
  color: var(--muted);
}
</style>
