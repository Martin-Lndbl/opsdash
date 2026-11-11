import { clampInt, clampNumber } from './math'

export type TargetsMode = 'days_only' | 'time_aware'

export interface TargetCategoryConfig {
  id: string
  label: string
  targetHours: number
  includeWeekend: boolean
  paceMode?: TargetsMode
  color?: string | null
  groupIds?: number[]
}

export type ActivityForecastMode = 'off' | 'total' | 'calendar' | 'category'

export interface ActivityCardConfig {
  showWeekendShare: boolean
  showEveningShare: boolean
  showEarliestLatest: boolean
  showOverlaps: boolean
  showLongestSession: boolean
  showLastDayOff: boolean
  showDayOffTrend: boolean
  showHint: boolean
  forecastMode: ActivityForecastMode
}

export interface BalanceConfig {
  categories: string[]
  useCategoryMapping: boolean
  index: { method: 'simple_range' | 'shannon_evenness'; basis: 'off' | 'category' | 'calendar' | 'both' }
  thresholds: {
    noticeAbove: number
    noticeBelow: number
    warnAbove: number
    warnBelow: number
    warnIndex: number
  }
  relations: { displayMode: 'ratio' | 'factor' }
  trend: { lookbackWeeks: number }
  dayparts: { enabled: boolean }
  ui: {
    showNotes: boolean
  }
}

export interface TargetsConfig {
  totalHours: number
  categories: TargetCategoryConfig[]
  pace: {
    includeWeekendTotal: boolean
    mode: TargetsMode
    thresholds: { onTrack: number; atRisk: number }
  }
  forecast: {
    methodPrimary: 'linear' | 'momentum'
    momentumLastNDays: number
    padding: number
  }
  ui: {
    showTotalDelta: boolean
    showNeedPerDay: boolean
    showCategoryBlocks: boolean
    badges: boolean
    includeWeekendToggle: boolean
    showCalendarCharts: boolean
    showCategoryCharts: boolean
  }
  allDayHours: number
  timeSummary: {
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
  activityCard: ActivityCardConfig
  balance: BalanceConfig
  includeZeroDaysInStats: boolean
  /** Calendar id → priority (-10..10). Where timed events overlap, only the higher-priority calendar is credited. */
  calendarPriorities?: Record<string, number>
}

export const CALENDAR_PRIORITY_MIN = -10
export const CALENDAR_PRIORITY_MAX = 10

export function clampTarget(value: number): number {
  const num = Number(value)
  if (!Number.isFinite(num)) return 0
  const clamped = Math.min(10000, Math.max(0, num))
  return Number(clamped.toFixed(2))
}

export function convertWeekToMonth(value: number): number {
  return clampTarget(Number(value) * 4)
}

export function convertMonthToWeek(value: number): number {
  return clampTarget(Number(value) / 4)
}

export function createDefaultActivityCardConfig(): ActivityCardConfig {
  return {
    showWeekendShare: true,
    showEveningShare: true,
    showEarliestLatest: true,
    showOverlaps: true,
    showLongestSession: true,
    showLastDayOff: true,
    showDayOffTrend: true,
    showHint: true,
    forecastMode: 'total',
  }
}

export function createDefaultBalanceConfig(): BalanceConfig {
  return {
    categories: ['work', 'hobby', 'sport'],
    useCategoryMapping: true,
    index: { method: 'simple_range', basis: 'category' },
    thresholds: {
      noticeAbove: 0.15,
      noticeBelow: 0.15,
      warnAbove: 0.30,
      warnBelow: 0.30,
      warnIndex: 0.60,
    },
    relations: { displayMode: 'ratio' },
    trend: { lookbackWeeks: 3 },
    dayparts: { enabled: false },
    ui: {
      showNotes: false,
    },
  }
}

export function createDefaultTargetsConfig(): TargetsConfig {
  return {
    totalHours: 48,
    categories: [
      { id: 'work', label: 'Work', targetHours: 32, includeWeekend: false, paceMode: 'days_only', color: '#2563EB', groupIds: [1] },
      { id: 'hobby', label: 'Hobby', targetHours: 6, includeWeekend: true, paceMode: 'days_only', color: '#F97316', groupIds: [2] },
      { id: 'sport', label: 'Sport', targetHours: 4, includeWeekend: true, paceMode: 'days_only', color: '#10B981', groupIds: [3] },
    ],
    pace: {
      includeWeekendTotal: true,
      mode: 'days_only',
      thresholds: { onTrack: -2, atRisk: -10 },
    },
    forecast: {
      methodPrimary: 'linear',
      momentumLastNDays: 2,
      padding: 1.5,
    },
    ui: {
      showTotalDelta: true,
      showNeedPerDay: true,
      showCategoryBlocks: true,
      badges: true,
      includeWeekendToggle: true,
      showCalendarCharts: true,
      showCategoryCharts: true,
    },
    allDayHours: 8,
    timeSummary: {
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
    },
    activityCard: createDefaultActivityCardConfig(),
    balance: createDefaultBalanceConfig(),
    includeZeroDaysInStats: false,
    calendarPriorities: {},
  }
}

export function cloneTargetsConfig(config: TargetsConfig): TargetsConfig {
  return JSON.parse(JSON.stringify(normalizeTargetsConfig(config)))
}

export function normalizeTargetsConfig(cfg: TargetsConfig | string | null | undefined): TargetsConfig {
  if (typeof cfg === 'string') {
    try {
      const parsed = JSON.parse(cfg)
      return normalizeTargetsConfig(parsed as TargetsConfig)
    } catch {
      return normalizeTargetsConfig(undefined)
    }
  }

  const base: TargetsConfig = createDefaultTargetsConfig()
  const clone: any = JSON.parse(JSON.stringify(cfg ?? {}))

  if (typeof clone !== 'object' || clone === null || Array.isArray(clone)) {
    return base
  }

  if (!Array.isArray(clone.categories)) {
    clone.categories = []
  }
  const sanitizedCategories = clone.categories.map((cat: any) => {
    const id = (cat?.id && typeof cat.id === 'string' ? cat.id : '') || cryptoLike(String(cat?.label ?? 'cat'))
    const label = (typeof cat?.label === 'string' && cat.label.trim() !== '') ? cat.label.trim() : capitalize(id)
    const targetHours = clampNumber(cat?.targetHours, 0, 10000)
    const includeWeekend = !!cat?.includeWeekend
    const paceMode: TargetsMode = cat?.paceMode === 'time_aware' ? 'time_aware' : 'days_only'
    const color = sanitizeHexColor(cat?.color)
    const groupIds = Array.isArray(cat?.groupIds)
      ? cat.groupIds
          .map((n: any) => Number(n))
          .filter((n: number) => Number.isFinite(n) && n >= 0 && n <= 9)
      : []
    return { id, label, targetHours, includeWeekend, paceMode, color, groupIds }
  })

  const resolvedCategories = sanitizedCategories.length ? sanitizedCategories : base.categories

  const result: TargetsConfig = {
    totalHours: clampNumber(clone.totalHours, 0, 10000),
    categories: resolvedCategories,
    pace: {
      includeWeekendTotal: !!(clone.pace?.includeWeekendTotal ?? base.pace.includeWeekendTotal),
      mode: clone.pace?.mode === 'time_aware' ? 'time_aware' : base.pace.mode,
      thresholds: {
        onTrack: clampNumber(clone.pace?.thresholds?.onTrack ?? base.pace.thresholds.onTrack, -100, 100),
        atRisk: clampNumber(clone.pace?.thresholds?.atRisk ?? base.pace.thresholds.atRisk, -100, 100),
      },
    },
    forecast: {
      methodPrimary: clone.forecast?.methodPrimary === 'momentum' ? 'momentum' : 'linear',
      momentumLastNDays: (() => {
        const n = Math.round(clone.forecast?.momentumLastNDays ?? base.forecast.momentumLastNDays)
        return Math.min(14, Math.max(1, Number.isFinite(n) ? n : base.forecast.momentumLastNDays))
      })(),
      padding: clampNumber(clone.forecast?.padding ?? base.forecast.padding, 0, 100),
    },
    ui: {
      showTotalDelta: !!(clone.ui?.showTotalDelta ?? base.ui.showTotalDelta),
      showNeedPerDay: !!(clone.ui?.showNeedPerDay ?? base.ui.showNeedPerDay),
      showCategoryBlocks: !!(clone.ui?.showCategoryBlocks ?? base.ui.showCategoryBlocks),
      badges: !!(clone.ui?.badges ?? base.ui.badges),
      includeWeekendToggle: !!(clone.ui?.includeWeekendToggle ?? base.ui.includeWeekendToggle),
      showCalendarCharts: clone.ui?.showCalendarCharts === undefined ? base.ui.showCalendarCharts : !!clone.ui.showCalendarCharts,
      showCategoryCharts: clone.ui?.showCategoryCharts === undefined ? base.ui.showCategoryCharts : !!clone.ui.showCategoryCharts,
    },
    allDayHours: clampNumber(clone.allDayHours ?? base.allDayHours, 0, 24),
    timeSummary: {
      showTotal: clone.timeSummary?.showTotal === undefined ? base.timeSummary.showTotal : !!clone.timeSummary.showTotal,
      showAverage: clone.timeSummary?.showAverage === undefined ? base.timeSummary.showAverage : !!clone.timeSummary.showAverage,
      showMedian: clone.timeSummary?.showMedian === undefined ? base.timeSummary.showMedian : !!clone.timeSummary.showMedian,
      showBusiest: clone.timeSummary?.showBusiest === undefined ? base.timeSummary.showBusiest : !!clone.timeSummary.showBusiest,
      showWorkday: clone.timeSummary?.showWorkday === undefined ? base.timeSummary.showWorkday : !!clone.timeSummary.showWorkday,
      showWeekend: clone.timeSummary?.showWeekend === undefined ? base.timeSummary.showWeekend : !!clone.timeSummary.showWeekend,
      showWeekendShare: clone.timeSummary?.showWeekendShare === undefined ? base.timeSummary.showWeekendShare : !!clone.timeSummary.showWeekendShare,
      showCalendarSummary: clone.timeSummary?.showCalendarSummary === undefined ? base.timeSummary.showCalendarSummary : !!clone.timeSummary.showCalendarSummary,
      showTopCategory: clone.timeSummary?.showTopCategory === undefined ? base.timeSummary.showTopCategory : !!clone.timeSummary.showTopCategory,
      showBalance: clone.timeSummary?.showBalance === undefined ? base.timeSummary.showBalance : !!clone.timeSummary.showBalance,
    },
    activityCard: normalizeActivityCardConfig(clone.activityCard, base.activityCard),
    balance: normalizeBalanceConfig(clone.balance, resolvedCategories, base.balance),
    includeZeroDaysInStats: !!(clone.includeZeroDaysInStats ?? base.includeZeroDaysInStats),
    calendarPriorities: normalizeCalendarPriorities(clone.calendarPriorities),
  }

  return result
}

function normalizeCalendarPriorities(input: any): Record<string, number> {
  const out: Record<string, number> = {}
  if (!input || typeof input !== 'object' || Array.isArray(input)) return out
  for (const [id, raw] of Object.entries(input)) {
    const key = String(id).trim()
    const n = Math.trunc(Number(raw))
    if (!key || !Number.isFinite(n) || n === 0) continue
    out[key] = Math.min(CALENDAR_PRIORITY_MAX, Math.max(CALENDAR_PRIORITY_MIN, n))
  }
  return out
}

function normalizeActivityCardConfig(input: any, base: ActivityCardConfig): ActivityCardConfig {
  const result: ActivityCardConfig = { ...base }
  if (!input || typeof input !== 'object') {
    return result
  }

  type ActivityCardBooleanKey = Exclude<keyof ActivityCardConfig, 'forecastMode'>
  const booleanKeys: ActivityCardBooleanKey[] = [
    'showWeekendShare',
    'showEveningShare',
    'showEarliestLatest',
    'showOverlaps',
    'showLongestSession',
    'showLastDayOff',
    'showDayOffTrend',
    'showHint',
  ]
  booleanKeys.forEach((key) => {
    if (Object.prototype.hasOwnProperty.call(input, key)) {
      result[key] = !!input[key]
    }
  })

  const modeRaw = input?.forecastMode
  if (typeof modeRaw === 'string') {
    const lowered = modeRaw.toLowerCase()
    if (lowered === 'off' || lowered === 'total' || lowered === 'calendar' || lowered === 'category') {
      result.forecastMode = lowered as ActivityForecastMode
    }
  }

  return result
}

function normalizeBalanceConfig(input: any, categories: TargetCategoryConfig[], base: BalanceConfig): BalanceConfig {
  const available = categories.map((cat) => cat.id)
  const allowed = new Set([...available, '__uncategorized__'])
  const orderSource = Array.isArray(input?.categories) ? input.categories : base.categories
  const order: string[] = []
  for (const rawId of orderSource) {
    const id = typeof rawId === 'string' ? rawId.trim() : String(rawId ?? '').trim()
    if (!id) continue
    if (allowed.size && !allowed.has(id)) continue
    if (!order.includes(id)) {
      order.push(id)
    }
  }
  if (!order.length) {
    if (available.length) {
      order.push(...available.slice(0, base.categories.length))
    } else {
      order.push(...base.categories)
    }
  }

  const thresholds = input?.thresholds ?? {}
  const ui = input?.ui ?? {}
  const indexInput = input?.index ?? {}

  return {
    categories: order,
    useCategoryMapping: !!(input?.useCategoryMapping ?? base.useCategoryMapping),
    index: {
      method: indexInput?.method === 'shannon_evenness' ? 'shannon_evenness' : base.index.method,
      basis:
        indexInput?.basis === 'calendar' || indexInput?.basis === 'both' || indexInput?.basis === 'off'
          ? indexInput.basis
          : base.index.basis,
    },
    thresholds: {
      noticeAbove: clampNumber(thresholds.noticeAbove ?? base.thresholds.noticeAbove, 0, 1),
      noticeBelow: clampNumber(thresholds.noticeBelow ?? base.thresholds.noticeBelow, 0, 1),
      warnAbove: clampNumber(thresholds.warnAbove ?? base.thresholds.warnAbove, 0, 1),
      warnBelow: clampNumber(thresholds.warnBelow ?? base.thresholds.warnBelow, 0, 1),
      warnIndex: clampNumber(thresholds.warnIndex ?? base.thresholds.warnIndex, 0, 1),
    },
    relations: {
      displayMode: input?.relations?.displayMode === 'factor' ? 'factor' : base.relations.displayMode,
    },
    trend: {
      lookbackWeeks: clampInt(input?.trend?.lookbackWeeks ?? base.trend.lookbackWeeks, 1, 6),
    },
    dayparts: {
      enabled: !!(input?.dayparts?.enabled ?? base.dayparts.enabled),
    },
    ui: {
      showNotes: !!(ui.showNotes ?? base.ui.showNotes),
    },
  }
}

function sanitizeHexColor(value: any): string | undefined {
  if (typeof value !== 'string') return undefined
  const trimmed = value.trim()
  if (!/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(trimmed)) {
    return undefined
  }
  if (trimmed.length === 4) {
    const [, r, g, b] = trimmed
    return `#${r}${r}${g}${g}${b}${b}`.toUpperCase()
  }
  return trimmed.toUpperCase()
}

function capitalize(value: string): string {
  if (!value) return ''
  return value.charAt(0).toUpperCase() + value.slice(1)
}

function cryptoLike(seed: string): string {
  let hash = 0
  for (let i = 0; i < seed.length; i++) {
    hash = (hash << 5) - hash + seed.charCodeAt(i)
    hash |= 0
  }
  return `cat-${Math.abs(hash)}`
}
