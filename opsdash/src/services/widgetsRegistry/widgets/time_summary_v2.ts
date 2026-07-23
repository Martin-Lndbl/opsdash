import { defineAsyncComponent } from 'vue'

const TimeSummaryCard = defineAsyncComponent(() =>
  import('../../../components/widgets/cards/TimeSummaryCard.vue').then((m) => m.default),
)

import { buildTitle } from '../helpers'
import { createDefaultTargetsConfig, convertWeekToMonth } from '../../targets'
import type { TargetsConfig } from '../../targets'
import { computeIndexForShares } from '../../balanceIndex'
import { addDaysUtc, parseDateKey } from '../../dateTime'
import type { RegistryEntry } from '../types'
import { formatLookbackLabel, sortLookbackOffsets } from './chartHelpers'

const baseTitle = 'Time Summary'
const lookbackTitle = 'Period Comparison'
type TimeSummaryDisplayMode = 'single_goal' | 'calendar_goals' | 'category_and_calendar_goals'
type TimeSummaryOverviewView = 'daily' | 'weekly'
const summaryToggleKeys: Array<keyof TargetsConfig['timeSummary']> = [
  'showTotal',
  'showAverage',
  'showMedian',
  'showBusiest',
  'showWorkday',
  'showWeekend',
  'showWeekendShare',
  'showCalendarSummary',
  'showTopCategory',
]

const modeControl = {
  key: 'mode',
  label: 'Average mode',
  type: 'select',
  options: [
    { value: 'active', label: 'Active days' },
    { value: 'all', label: 'All days' },
  ],
} as const

const summaryControls = [
  { key: 'showTotal', label: 'Total hours', type: 'toggle' },
  { key: 'showAverage', label: 'Average per day', type: 'toggle' },
  { key: 'showMedian', label: 'Median per day', type: 'toggle' },
  { key: 'showBusiest', label: 'Busiest day', type: 'toggle' },
  { key: 'showWorkday', label: 'Workdays row', type: 'toggle' },
  { key: 'showWeekend', label: 'Weekend row', type: 'toggle' },
  { key: 'showWeekendShare', label: 'Weekend share', type: 'toggle' },
  { key: 'showCalendarSummary', label: 'Top calendars', type: 'toggle' },
  { key: 'showTopCategory', label: 'Top category', type: 'toggle' },
] as const

function buildDefaultOptions() {
  const defaults = createDefaultTargetsConfig().timeSummary
  return {
    ...defaults,
    mode: 'active',
    showToday: true,
    showActivity: true,
    showHistoryCoreMetrics: true,
    historyView: 'accordion',
    showActivityDetails: true,
    showDelta: true,
  }
}

function buildOverviewDefaultOptions() {
  return {
    defaultView: 'auto',
    showDailyKpis: true,
    showWeekMiniChart: true,
    showEmptyLanes: true,
    maxLanes: 4,
    showRangeInTitle: false,
  }
}

function detectTimeSummaryDisplayMode(ctx: any): TimeSummaryDisplayMode {
  const strategy = String(ctx?.onboardingStrategy ?? '')
  if (strategy === 'total_only') return 'single_goal'
  if (strategy === 'total_plus_categories') return 'calendar_goals'
  if (strategy === 'full_granular') return 'category_and_calendar_goals'
  // Only use config as fallback for legacy users with no strategy set
  if (strategy !== '') return 'single_goal'
  const categories = Array.isArray(ctx?.targetsConfig?.categories) ? ctx.targetsConfig.categories : []
  if (categories.length > 0) return 'category_and_calendar_goals'
  const currentTargets = ctx?.currentTargets && typeof ctx.currentTargets === 'object' ? ctx.currentTargets : {}
  return Object.keys(currentTargets).length > 0 ? 'calendar_goals' : 'single_goal'
}

function buildTimeSummaryProps(
  def: any,
  ctx: any,
  opts: {
    title: string
    includeHistory: boolean
    showOverview: boolean
    showLookback: boolean
    showDelta: boolean
    showRangeInTitle?: boolean
  },
) {
  const baseConfig: TargetsConfig = ctx.targetsConfig ? JSON.parse(JSON.stringify(ctx.targetsConfig)) : createDefaultTargetsConfig()
  const cfg = {
    ...baseConfig,
    timeSummary: { ...baseConfig.timeSummary },
  }
  const rangeMode = String(ctx.rangeMode || 'week').toLowerCase() === 'month' ? 'month' : 'week'
  const mode = (def.options?.mode as 'active' | 'all' | undefined) ?? ctx.activeDayMode ?? 'active'
  const showToday = opts.showOverview && def.options?.showToday !== false
  const showActivity = opts.showOverview && def.options?.showActivity !== false
  const showHistoryCoreMetrics = def.options?.showHistoryCoreMetrics !== false
  const displayMode = detectTimeSummaryDisplayMode(ctx)
  const todayGroups = resolveTodayGroups(ctx, displayMode)
  const allowedViews = resolveAllowedViews(displayMode)
  const defaultView = resolveDefaultView(def.options?.defaultView, displayMode, allowedViews)
  const calendarTodayItems = resolveCalendarTodayItems(ctx)
  const categoryTodayItems = resolveCategoryTodayItems(ctx)
  const weekDays = resolveWeekDays(ctx)
  const rawHistoryView = String(def.options?.historyView ?? '').toLowerCase()
  const historyView =
    rawHistoryView === 'accordion' || rawHistoryView === 'pills'
      ? 'accordion'
      : 'timeline'
  const showActivityDetails = def.options?.showActivityDetails !== false
  const showDelta = opts.showLookback && opts.showDelta && def.options?.showDelta !== false

  summaryToggleKeys.forEach((key) => {
    if (def.options?.[key] === undefined) return
    cfg.timeSummary[key] = !!def.options[key]
  })

  if (def.options?.showCalendarSummary === undefined) {
    cfg.timeSummary.showCalendarSummary = displayMode !== 'single_goal'
  }
  if (def.options?.showTopCategory === undefined) {
    cfg.timeSummary.showTopCategory = displayMode === 'category_and_calendar_goals'
  }
  if (def.options?.showBalance === undefined) {
    cfg.timeSummary.showBalance = displayMode === 'category_and_calendar_goals'
  }

  const history =
    opts.includeHistory && Number(ctx.lookbackWeeks) > 1
      ? buildHistoryEntries({
          mode,
          rangeMode,
          lookbackWeeks: Number(ctx.lookbackWeeks),
          perDaySeriesByOffset: ctx.charts?.perDaySeriesByOffset,
          hodByOffset: ctx.charts?.hodByOffset,
          summaryByOffset: ctx.charts?.summaryByOffset,
          targetsConfig: normalizeTargetsConfigForRange(cfg, rangeMode),
          calendarCategoryMap: ctx.calendarCategoryMap || {},
          calendarGroups: Array.isArray(ctx.calendarGroups) ? ctx.calendarGroups : [],
          categoryColorMap: ctx.categoryColorMap || {},
        })
      : []

  return {
    summary: ctx.summary,
    activitySummary: ctx.activitySummary,
    mode,
    config: cfg.timeSummary,
    lookbackWeeks: Number(ctx.lookbackWeeks) || 1,
    todayGroups: def.props?.todayGroups ?? todayGroups,
    calendarTodayItems,
    categoryTodayItems,
    weekDays,
    allowedViews,
    defaultView,
    showDailyKpis: def.options?.showDailyKpis !== false,
    showWeekMiniChart: def.options?.showWeekMiniChart !== false,
    showEmptyLanes: def.options?.showEmptyLanes !== false,
    maxLanes: Number(def.options?.maxLanes ?? 4) || 4,
    title: buildTitle(opts.title, def.options?.titlePrefix),
    cardBg: def.options?.cardBg,
    displayMode,
    rangeMode: ctx.rangeMode,
    rangeStart: ctx.from,
    rangeEnd: ctx.to,
    offset: ctx.offset,
    showHeader: def.options?.showHeader !== false,
    showRangeInTitle: opts.showRangeInTitle !== false,
    showToday,
    showActivity,
    showHistoryCoreMetrics,
    historyView,
    showActivityDetails,
    showOverview: opts.showOverview,
    showLookback: opts.showLookback,
    showDelta,
    history,
  }
}

function resolveAllowedViews(displayMode: TimeSummaryDisplayMode): TimeSummaryOverviewView[] {
  // "Weekly" replaces the old calendars/categories tabs; the actual scope
  // (calendar vs category) is now driven by preferredScope from the sidebar.
  if (displayMode === 'single_goal') return ['daily']
  return ['daily', 'weekly']
}

function resolveDefaultView(input: any, displayMode: TimeSummaryDisplayMode, allowed: TimeSummaryOverviewView[]): TimeSummaryOverviewView {
  const value = String(input ?? '').toLowerCase()
  // Collapse legacy 'calendars' / 'categories' to 'weekly'.
  const norm: TimeSummaryOverviewView | 'auto' | '' =
    value === 'daily' ? 'daily'
    : value === 'weekly' || value === 'calendars' || value === 'categories' ? 'weekly'
    : value === 'auto' ? 'auto'
    : ''
  if (norm === 'daily' || norm === 'weekly') {
    if (allowed.includes(norm)) return norm
  }
  if (allowed.includes('weekly') && displayMode !== 'single_goal') return 'weekly'
  return 'daily'
}

function resolveCalendarTodayItems(ctx: any) {
  const calendarTodayHours = ctx?.calendarTodayHours && typeof ctx.calendarTodayHours === 'object'
    ? ctx.calendarTodayHours
    : {}
  const calendars = Array.isArray(ctx?.calendars) ? ctx.calendars : []
  return calendars
    .map((calendar: any) => {
      const id = String(calendar?.id ?? '').trim()
      if (!id) return null
      return {
        id,
        label: String(calendar?.displayname ?? calendar?.name ?? id),
        todayHours: Number(calendarTodayHours[id] ?? 0) || 0,
        color: typeof calendar?.color === 'string' ? calendar.color : undefined,
      }
    })
    .filter(Boolean)
    .sort((left: any, right: any) => Number(right?.todayHours ?? 0) - Number(left?.todayHours ?? 0))
}

function resolveCategoryTodayItems(ctx: any) {
  const groups = Array.isArray(ctx?.groups)
    ? ctx.groups
    : (Array.isArray(ctx?.calendarGroups) ? ctx.calendarGroups : [])
  const categoryTodayHours = ctx?.categoryTodayHours && typeof ctx.categoryTodayHours === 'object'
    ? ctx.categoryTodayHours
    : {}
  const categoryColorMap = ctx?.categoryColorMap && typeof ctx.categoryColorMap === 'object'
    ? ctx.categoryColorMap
    : {}
  return groups
    .map((group: any) => {
      const id = String(group?.id ?? '').trim()
      if (!id) return null
      const todayHours = Number(group?.todayHours ?? categoryTodayHours[id] ?? 0) || 0
      return {
        id,
        label: String(group?.label ?? id),
        todayHours,
        color: group?.color || categoryColorMap[id],
        isUnassigned: Boolean(group?.isUnassigned) || id === '__uncategorized__',
      }
    })
    .filter(Boolean)
    .sort((left: any, right: any) => Number(right?.todayHours ?? 0) - Number(left?.todayHours ?? 0))
}

function resolveWeekDays(ctx: any) {
  const rangeMode = String(ctx?.rangeMode || 'week').toLowerCase() === 'month' ? 'month' : 'week'
  if (rangeMode !== 'week') return []

  const from = parseDateKey(String(ctx?.from ?? ''))
  const to = parseDateKey(String(ctx?.to ?? ''))
  if (!from || !to) return []

  const todayKey = formatLocalDateKey(new Date())
  const rows = Array.isArray(ctx?.byDay) ? ctx.byDay : []
  const byDate = new Map<string, { hours: number; events: number }>()
  rows.forEach((row: any) => {
    const date = String(row?.date ?? '')
    if (!date) return
    byDate.set(date, {
      hours: Number(row?.total_hours ?? row?.hours ?? 0) || 0,
      events: Number(row?.events_count ?? row?.events ?? 0) || 0,
    })
  })

  const perDay = ctx?.charts?.perDay
  const labels = Array.isArray(perDay?.labels) ? perDay.labels : []
  const data = Array.isArray(perDay?.data) ? perDay.data : []
  labels.forEach((raw: any, idx: number) => {
    const date = String(raw ?? '')
    if (!date || byDate.has(date)) return
    byDate.set(date, {
      hours: Number(data[idx] ?? 0) || 0,
      events: 0,
    })
  })

  const days = []
  for (let idx = 0; idx < 7; idx++) {
    const current = addDaysUtc(from, idx)
    if (current.getTime() > to.getTime()) break
    const date = formatUtcDateKey(current)
    const row = byDate.get(date)
    days.push({
      date,
      label: shortDayLabel(date),
      hours: row?.hours ?? 0,
      events: row?.events ?? 0,
      isToday: date === todayKey,
    })
  }
  return days
}

function formatLocalDateKey(date: Date) {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function formatUtcDateKey(date: Date) {
  const y = date.getUTCFullYear()
  const m = String(date.getUTCMonth() + 1).padStart(2, '0')
  const d = String(date.getUTCDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function shortDayLabel(date: string) {
  const parsed = parseDateKey(date)
  if (!parsed) return date.slice(5) || date
  return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][parsed.getUTCDay()] || date.slice(5)
}

function resolveTodayGroups(ctx: any, displayMode: TimeSummaryDisplayMode) {
  if (displayMode === 'category_and_calendar_goals') {
    return Array.isArray(ctx.groups) ? ctx.groups : []
  }
  if (displayMode !== 'calendar_goals') {
    return []
  }

  const calendarTodayHours = ctx?.calendarTodayHours && typeof ctx.calendarTodayHours === 'object'
    ? ctx.calendarTodayHours
    : {}
  const calendars = Array.isArray(ctx?.calendars) ? ctx.calendars : []
  const colorById = new Map<string, string | undefined>()
  const labelById = new Map<string, string>()

  calendars.forEach((calendar: any) => {
    const id = String(calendar?.id ?? '').trim()
    if (!id) return
    labelById.set(id, String(calendar?.displayname ?? calendar?.name ?? id))
    colorById.set(id, typeof calendar?.color === 'string' ? calendar.color : undefined)
  })

  return Object.entries(calendarTodayHours)
    .map(([id, value]) => {
      const todayHours = Number(value ?? 0)
      if (!Number.isFinite(todayHours) || todayHours <= 0) return null
      return {
        id,
        label: labelById.get(id) || id,
        todayHours,
        color: colorById.get(id),
      }
    })
    .filter(Boolean)
    .sort((left: any, right: any) => Number(right?.todayHours ?? 0) - Number(left?.todayHours ?? 0))
}

export const timeSummaryOverviewEntry: RegistryEntry = {
  component: TimeSummaryCard,
  defaultLayout: { width: 'half', height: 'l', order: 9 },
  heightMode: 'auto',
  label: "Today's time summary",
  category: 'Time',
  baseTitle: "Today's time summary",
  configurable: true,
  defaultOptions: buildOverviewDefaultOptions(),
  controls: [
    {
      key: 'defaultView',
      label: 'Default view',
      type: 'select',
      options: [
        { value: 'auto', label: 'Auto' },
        { value: 'daily', label: 'Daily' },
        { value: 'calendars', label: 'Calendars' },
        { value: 'categories', label: 'Categories' },
      ],
    },
    { key: 'showDailyKpis', label: 'Daily KPIs', type: 'toggle' },
    { key: 'showWeekMiniChart', label: 'Week mini chart', type: 'toggle' },
    { key: 'showEmptyLanes', label: 'Show empty lanes', type: 'toggle' },
    { key: 'maxLanes', label: 'Max lanes', type: 'number', min: 1, max: 12, step: 1 },
  ],
  buildProps: (def, ctx) =>
    buildTimeSummaryProps(def, ctx, {
      title: "Today's time summary",
      includeHistory: false,
      showOverview: true,
      showLookback: false,
      showDelta: false,
      showRangeInTitle: false,
    }),
}

export const timeSummaryLookbackEntry: RegistryEntry = {
  component: TimeSummaryCard,
  defaultLayout: { width: 'half', height: 'l', order: 19 },
  heightMode: 'auto',
  label: lookbackTitle,
  category: 'Time',
  baseTitle: lookbackTitle,
  configurable: true,
  defaultOptions: buildDefaultOptions(),
  controls: [
    modeControl,
    { key: 'showHistoryCoreMetrics', label: 'History core metrics', type: 'toggle' },
    {
      key: 'historyView',
      label: 'History layout',
      type: 'select',
      options: [
        { value: 'timeline', label: 'Timeline' },
        { value: 'accordion', label: 'Accordion' },
      ],
    },
    { key: 'showActivityDetails', label: 'Activity details', type: 'toggle' },
    { key: 'showDelta', label: 'Show delta line', type: 'toggle' },
    ...summaryControls,
  ],
  buildProps: (def, ctx) =>
    buildTimeSummaryProps(def, ctx, {
      title: lookbackTitle,
      includeHistory: true,
      showOverview: false,
      showLookback: true,
      showDelta: true,
      showRangeInTitle: true,
    }),
}

export const timeSummaryV2Entry: RegistryEntry = {
  component: TimeSummaryCard,
  defaultLayout: { width: 'half', height: 's', order: 9 },
  label: 'Time Summary',
  category: 'Time',
  baseTitle,
  configurable: true,
  defaultOptions: buildDefaultOptions(),
  controls: [
    modeControl,
    { key: 'showToday', label: 'Show today block', type: 'toggle' },
    { key: 'showActivity', label: 'Show activity section', type: 'toggle' },
    { key: 'showHistoryCoreMetrics', label: 'History core metrics', type: 'toggle' },
    {
      key: 'historyView',
      label: 'History layout',
      type: 'select',
      options: [
        { value: 'timeline', label: 'Timeline' },
        { value: 'accordion', label: 'Accordion' },
      ],
    },
    { key: 'showActivityDetails', label: 'Activity details', type: 'toggle' },
    { key: 'showDelta', label: 'Show delta line', type: 'toggle' },
    ...summaryControls,
  ],
  buildProps: (def, ctx) =>
    buildTimeSummaryProps(def, ctx, {
      title: baseTitle,
      includeHistory: true,
      showOverview: true,
      showLookback: true,
      showDelta: true,
    }),
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
  busiest: { date: string; hours: number } | null
  workdayAvg: number
  workdayMedian: number
  weekendAvg: number
  weekendMedian: number
  weekendShare: number | null
  activeCalendars: number
  calendarSummary: string
  topCategory: { label: string; actualHours: number; targetHours: number; percent: number; color: string | undefined } | null
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

function buildHistoryEntries(opts: {
  mode: 'active' | 'all'
  rangeMode: 'week' | 'month'
  lookbackWeeks: number
  perDaySeriesByOffset: any
  hodByOffset: any
  summaryByOffset: any
  targetsConfig: TargetsConfig
  calendarCategoryMap: Record<string, string>
  calendarGroups: Array<{ id: string; label?: string }>
  categoryColorMap: Record<string, string>
}): HistoryEntry[] {
  const perDayInput = Array.isArray(opts.perDaySeriesByOffset) ? opts.perDaySeriesByOffset : []
  if (!perDayInput.length) return []
  const hodInput = Array.isArray(opts.hodByOffset) ? opts.hodByOffset : []
  const summaryInput = Array.isArray(opts.summaryByOffset) ? opts.summaryByOffset : []

  const perDayMap = new Map<number, any>()
  perDayInput.forEach((entry) => {
    const offset = Number(entry?.offset ?? 0)
    if (Number.isFinite(offset)) perDayMap.set(offset, entry)
  })
  const hodMap = new Map<number, any>()
  hodInput.forEach((entry) => {
    const offset = Number(entry?.offset ?? 0)
    if (Number.isFinite(offset)) hodMap.set(offset, entry)
  })
  const summaryMap = new Map<number, any>()
  summaryInput.forEach((entry) => {
    const offset = Number(entry?.offset ?? 0)
    if (Number.isFinite(offset)) summaryMap.set(offset, entry)
  })

  const maxOffset = Math.max(0, Math.min(6, Math.floor(opts.lookbackWeeks) - 1))
  if (maxOffset <= 0) return []

  const offsets = sortLookbackOffsets(perDayInput)
    .map((entry) => Number(entry?.offset ?? 0))
    .filter((offset) => Number.isFinite(offset) && offset > 0 && offset <= maxOffset)

  const categoryLabelById = new Map<string, string>()
  opts.calendarGroups.forEach((group) => {
    const id = String(group?.id ?? '').trim()
    if (id) categoryLabelById.set(id, String(group?.label ?? id))
  })
  opts.targetsConfig.categories.forEach((cat) => {
    const id = String(cat?.id ?? '').trim()
    if (id && !categoryLabelById.has(id)) {
      categoryLabelById.set(id, String(cat?.label ?? id))
    }
  })

  return offsets
    .map((offset) => {
      const perDayEntry = perDayMap.get(offset)
      if (!perDayEntry) return null
      const perDay = buildPerDayStats(perDayEntry)
      const summaryEntry = summaryMap.get(offset)
      const hodEntry = hodMap.get(offset)
      const totalHours = round2(perDay.totalHours)
      const events = Number(summaryEntry?.events ?? summaryEntry?.eventsCount ?? 0) || 0
      const overlapEvents = Number(summaryEntry?.overlap_events ?? summaryEntry?.overlapEvents ?? 0) || 0
      const longestSession = Number(summaryEntry?.longest_session ?? summaryEntry?.longestSession ?? 0) || 0
      const earliestStart = stringOrNull(summaryEntry?.earliest_start ?? summaryEntry?.earliestStart)
      const latestEnd = stringOrNull(summaryEntry?.latest_end ?? summaryEntry?.latestEnd)
      const hodStats = hodEntry ? buildHodStats(hodEntry, totalHours) : null

      const activeDays = perDay.activeDays
      const avgDay = round2(avg(perDay.filteredTotals(opts.mode)))
      const medianDay = round2(median(perDay.filteredTotals(opts.mode)))
      const avgEvent = events > 0 ? round2(totalHours / events) : 0
      const workdayAvg = round2(avg(perDay.filteredWorkdayTotals(opts.mode)))
      const workdayMedian = round2(median(perDay.filteredWorkdayTotals(opts.mode)))
      const weekendAvg = round2(avg(perDay.filteredWeekendTotals(opts.mode)))
      const weekendMedian = round2(median(perDay.filteredWeekendTotals(opts.mode)))
      const weekendShare = hodStats?.weekendShare ?? perDay.weekendShare

      const calendarSummary = buildCalendarSummary(perDay.calendarTotals, totalHours)
      const topCategory = buildTopCategory({
        calendarTotals: perDay.calendarTotals,
        categoryLabelById,
        categoryColorMap: opts.categoryColorMap,
        calendarCategoryMap: opts.calendarCategoryMap,
        targetsConfig: opts.targetsConfig,
      })
      const balanceIndex = buildBalanceIndex({
        totalHours,
        calendarTotals: perDay.calendarTotals,
        calendarCategoryMap: opts.calendarCategoryMap,
        targetsConfig: opts.targetsConfig,
      })

      return {
        offset,
        label: formatLookbackLabel({ offset, from: perDayEntry?.from, to: perDayEntry?.to }, opts.rangeMode),
        rangeStart: String(perDayEntry?.from ?? ''),
        rangeEnd: String(perDayEntry?.to ?? ''),
        totalHours,
        avgDay,
        avgEvent,
        medianDay,
        busiest: perDay.busiest,
        workdayAvg,
        workdayMedian,
        weekendAvg,
        weekendMedian,
        weekendShare,
        activeCalendars: perDay.activeCalendars,
        calendarSummary,
        topCategory,
        balanceIndex,
        activity: {
          events,
          activeDays,
          typicalStart: hodStats?.typicalStart ?? null,
          typicalEnd: hodStats?.typicalEnd ?? null,
          weekendShare: hodStats?.weekendShare ?? null,
          eveningShare: hodStats?.eveningShare ?? null,
          earliestStart,
          latestEnd,
          overlapEvents,
          longestSession: round2(longestSession),
          lastDayOff: perDay.lastDayOff,
          lastHalfDayOff: perDay.lastHalfDayOff,
        },
      }
    })
    .filter(Boolean) as HistoryEntry[]
}

function normalizeTargetsConfigForRange(config: TargetsConfig, rangeMode: 'week' | 'month'): TargetsConfig {
  const clone: TargetsConfig = JSON.parse(JSON.stringify(config))
  if (rangeMode === 'month') {
    clone.totalHours = convertWeekToMonth(clone.totalHours)
    clone.categories = clone.categories.map((cat) => ({
      ...cat,
      targetHours: convertWeekToMonth(cat.targetHours),
    }))
  }
  return clone
}

function buildPerDayStats(entry: any) {
  const labels = Array.isArray(entry?.labels) ? entry.labels.map((label: any) => String(label ?? '')) : []
  const series = Array.isArray(entry?.series) ? entry.series : []
  const totals = labels.map((_, idx) => series.reduce((sum: number, row: any) => {
    const raw = Number(row?.data?.[idx] ?? 0)
    return sum + (Number.isFinite(raw) ? Math.max(0, raw) : 0)
  }, 0))
  const calendarTotals = series.map((row: any) => {
    const id = String(row?.id ?? '')
    const name = String(row?.name ?? row?.label ?? id)
    const total = Array.isArray(row?.data)
      ? row.data.reduce((sum: number, v: any) => {
          const num = Number(v ?? 0)
          return sum + (Number.isFinite(num) ? Math.max(0, num) : 0)
        }, 0)
      : 0
    return { id, name, total: round2(total), color: row?.color ? String(row.color) : undefined }
  })
  const totalHours = totals.reduce((sum, v) => sum + v, 0)
  const activeCalendars = calendarTotals.filter((row) => row.total > 0).length
  const busiest = (() => {
    if (!labels.length) return null
    let max = -1
    let maxIdx = -1
    totals.forEach((val, idx) => {
      if (val > max) {
        max = val
        maxIdx = idx
      }
    })
    if (maxIdx < 0 || max <= 0) return null
    return { date: labels[maxIdx], hours: round2(max) }
  })()
  const workdayTotals: number[] = []
  const weekendTotals: number[] = []
  totals.forEach((val, idx) => {
    const day = dayOfWeek(labels[idx])
    if (day == null) return
    if (day === 0 || day === 6) {
      weekendTotals.push(val)
    } else {
      workdayTotals.push(val)
    }
  })

  const weekendShare = totalHours > 0
    ? round1((weekendTotals.reduce((sum, v) => sum + v, 0) / totalHours) * 100)
    : null

  const { lastDayOff, lastHalfDayOff } = findLastDayOff(labels, totals)
  const activeDays = totals.filter((v) => v > 0.01).length

  return {
    labels,
    totals,
    totalHours,
    activeCalendars,
    busiest,
    workdayTotals,
    weekendTotals,
    weekendShare,
    lastDayOff,
    lastHalfDayOff,
    activeDays,
    filteredTotals: (mode: 'active' | 'all') => mode === 'active' ? totals.filter((v) => v > 0) : totals,
    filteredWorkdayTotals: (mode: 'active' | 'all') => mode === 'active' ? workdayTotals.filter((v) => v > 0) : workdayTotals,
    filteredWeekendTotals: (mode: 'active' | 'all') => mode === 'active' ? weekendTotals.filter((v) => v > 0) : weekendTotals,
    calendarTotals,
  }
}

function buildHodStats(entry: any, totalHours: number) {
  const dows = Array.isArray(entry?.dows) ? entry.dows.map((d: any) => String(d ?? '')) : []
  const matrix = Array.isArray(entry?.matrix) ? entry.matrix : []
  const rowMap = new Map<string, number[]>()
  dows.forEach((dow: string, idx: number) => {
    const row = Array.isArray(matrix[idx]) ? matrix[idx] : []
    rowMap.set(dow, row.map((val) => Number(val ?? 0)))
  })
  const hourTotals = Array.from({ length: 24 }, (_, idx) =>
    Array.from(rowMap.values()).reduce((sum, row) => sum + Number(row[idx] ?? 0), 0),
  )
  const threshold = 0.25
  let typicalStart: string | null = null
  let typicalEnd: string | null = null
  for (let i = 0; i < 24; i += 1) {
    if (hourTotals[i] >= threshold) {
      typicalStart = `${String(i).padStart(2, '0')}:00`
      break
    }
  }
  for (let i = 23; i >= 0; i -= 1) {
    if (hourTotals[i] >= threshold) {
      typicalEnd = `${String(i + 1).padStart(2, '0')}:00`
      break
    }
  }
  const weekendTotal = (rowMap.get('Sat') || []).reduce((sum, v) => sum + Number(v ?? 0), 0)
    + (rowMap.get('Sun') || []).reduce((sum, v) => sum + Number(v ?? 0), 0)
  let eveningTotal = 0
  rowMap.forEach((row) => {
    for (let i = 18; i < 24; i += 1) {
      eveningTotal += Number(row[i] ?? 0)
    }
  })
  const weekendShare = totalHours > 0 ? round1((weekendTotal / totalHours) * 100) : null
  const eveningShare = totalHours > 0 ? round1((eveningTotal / totalHours) * 100) : null
  return {
    typicalStart,
    typicalEnd,
    weekendShare,
    eveningShare,
  }
}

function buildCalendarSummary(
  calendarTotals: Array<{ name: string; total: number }>,
  totalHours: number,
): string {
  if (totalHours <= 0) return ''
  const ranked = calendarTotals.slice().sort((a, b) => b.total - a.total)
  return ranked
    .filter((row) => row.total > 0)
    .slice(0, 3)
    .map((row) => `${row.name} ${round1((row.total / totalHours) * 100)}%`)
    .join(', ')
}

function buildTopCategory(input: {
  calendarTotals: Array<{ id: string; total: number }>
  calendarCategoryMap: Record<string, string>
  categoryLabelById: Map<string, string>
  categoryColorMap: Record<string, string>
  targetsConfig: TargetsConfig
}) {
  const totals = new Map<string, number>()
  input.calendarTotals.forEach((row) => {
    const catId = String(input.calendarCategoryMap?.[row.id] ?? '')
    if (!catId) return
    totals.set(catId, (totals.get(catId) || 0) + row.total)
  })
  if (!totals.size) return null
  let topId = ''
  let topTotal = -1
  totals.forEach((value, id) => {
    if (value > topTotal) {
      topTotal = value
      topId = id
    }
  })
  if (!topId) return null
  const targetEntry = input.targetsConfig.categories.find((cat) => String(cat.id) === topId)
  const targetHours = Number(targetEntry?.targetHours ?? 0) || 0
  const percent = targetHours > 0 ? round1((topTotal / targetHours) * 100) : 0
  return {
    label: input.categoryLabelById.get(topId) || topId,
    actualHours: round2(topTotal),
    targetHours: round2(targetHours),
    percent,
    color: input.categoryColorMap?.[topId],
  }
}

function buildBalanceIndex(input: {
  totalHours: number
  calendarTotals: Array<{ id: string; total: number }>
  calendarCategoryMap: Record<string, string>
  targetsConfig: TargetsConfig
}): number | null {
  if (input.totalHours <= 0) return null
  const basis = input.targetsConfig.balance?.index?.basis || 'category'
  if (basis === 'off') return null
  const shares: Record<string, number> = {}
  if (basis === 'calendar') {
    input.calendarTotals.forEach((row) => {
      if (row.total <= 0) return
      shares[row.id] = row.total / input.totalHours
    })
  } else {
    const totals = new Map<string, number>()
    input.calendarTotals.forEach((row) => {
      const catId = String(input.calendarCategoryMap?.[row.id] ?? '')
      if (!catId) return
      totals.set(catId, (totals.get(catId) || 0) + row.total)
    })
    totals.forEach((value, id) => {
      if (value <= 0) return
      shares[id] = value / input.totalHours
    })
  }
  if (!Object.keys(shares).length) return null
  return computeIndexForShares({
    shares,
    targets: input.targetsConfig.categories,
    basis,
  })
}

function avg(values: number[]): number {
  if (!values.length) return 0
  return values.reduce((sum, v) => sum + v, 0) / values.length
}

function median(values: number[]): number {
  if (!values.length) return 0
  const sorted = [...values].sort((a, b) => a - b)
  const mid = Math.floor(sorted.length / 2)
  return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2
}

function dayOfWeek(label: string): number | null {
  if (!label) return null
  const date = parseDateKey(label)
  if (!date) return null
  return date.getUTCDay()
}

function findLastDayOff(labels: string[], totals: number[]) {
  let lastDayOff: string | null = null
  let lastHalfDayOff: string | null = null
  const halfThreshold = 4
  for (let i = labels.length - 1; i >= 0; i -= 1) {
    const dayTotal = totals[i] ?? 0
    if (!lastDayOff && dayTotal <= 0.01) {
      lastDayOff = labels[i]
    }
    if (!lastHalfDayOff && dayTotal > 0.01 && dayTotal <= halfThreshold) {
      lastHalfDayOff = labels[i]
    }
    if (lastDayOff && lastHalfDayOff) break
  }
  return { lastDayOff, lastHalfDayOff }
}

function round1(value: number) {
  return Math.round(value * 10) / 10
}

function round2(value: number) {
  return Math.round(value * 100) / 100
}

function stringOrNull(value: any): string | null {
  if (value === undefined || value === null) return null
  const str = String(value).trim()
  return str ? str : null
}
