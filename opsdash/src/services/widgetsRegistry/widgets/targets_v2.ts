import { defineAsyncComponent } from 'vue'

const TimeTargetsCard = defineAsyncComponent(() =>
  import('../../../components/widgets/cards/TimeTargetsCard.vue').then((m) => m.default),
)

import { attachUi, buildCalendarGroups, buildCategoryGroups, buildTitle, copyConfigForRange, safeBuildTargetsSummary } from '../helpers'
import { clampTarget, convertWeekToMonth, createDefaultTargetsConfig } from '../../targets'
import type { RegistryEntry } from '../types'

const baseTitle = 'Targets'

type TargetsDisplayMode = 'single_goal' | 'calendar_goals' | 'category_and_calendar_goals'

export const targetsV2Entry: RegistryEntry = {
  component: TimeTargetsCard,
  defaultLayout: { width: 'half', height: 'm', order: 18 },
  heightMode: 'auto',
  label: 'Targets',
  category: 'Time' as const,
  baseTitle,
  configurable: true,
  defaultOptions: {
    showLegend: true,
    showDelta: true,
    showForecast: true,
    showPace: true,
    neverFinishedMode: false,
    showToday: true,
    showTotalDelta: true,
    showNeedPerDay: true,
    showCategoryBlocks: true,
    badges: true,
    includeWeekendToggle: true,
    includeZeroDaysInStats: false,
    useLocalConfig: false,
    localConfig: null,
    colorStyle: 'fill',
  },
  resolveOptions: (options, ctx) => {
    const useLocal = options?.useLocalConfig === true
    const baseConfig =
      useLocal && options?.localConfig
        ? JSON.parse(JSON.stringify(options.localConfig))
        : ctx?.targetsConfig
          ? JSON.parse(JSON.stringify(ctx.targetsConfig))
          : createDefaultTargetsConfig()
    const cfg = attachUi(copyConfigForRange(baseConfig, ctx?.rangeMode))
    return {
      showTotalDelta: cfg.ui.showTotalDelta,
      showNeedPerDay: cfg.ui.showNeedPerDay,
      showCategoryBlocks: cfg.ui.showCategoryBlocks,
      badges: cfg.ui.badges,
      includeWeekendToggle: cfg.ui.includeWeekendToggle,
      includeZeroDaysInStats: cfg.includeZeroDaysInStats,
    }
  },
  controls: [
    { key: 'showLegend', label: 'Show legend', type: 'toggle' },
    { key: 'showDelta', label: 'Show delta', type: 'toggle' },
    { key: 'showForecast', label: 'Show forecast', type: 'toggle' },
    { key: 'showPace', label: 'Show pace line', type: 'toggle' },
    { key: 'neverFinishedMode', label: 'Never Finished · Stay Hard', type: 'toggle' },
    { key: 'showToday', label: 'Show today overlay', type: 'toggle' },
    { key: 'useLocalConfig', label: 'Use custom targets for this widget', type: 'toggle' },
    { key: 'showTotalDelta', label: 'Show total delta', type: 'toggle' },
    { key: 'showNeedPerDay', label: 'Show need per day', type: 'toggle' },
    { key: 'showCategoryBlocks', label: 'Show categories', type: 'toggle' },
    { key: 'badges', label: 'Status badges', type: 'toggle' },
    { key: 'includeWeekendToggle', label: 'Weekend toggle', type: 'toggle' },
    { key: 'includeZeroDaysInStats', label: 'Include zero days in pace', type: 'toggle' },
    { key: 'colorStyle', label: 'Color style', type: 'select', options: [
      { value: 'fill', label: 'Fill' },
      { value: 'outline', label: 'Border only' },
    ] },
    // footer removed
  ],
  buildProps: (def, ctx) => {
    const useLocal = !!def.options?.useLocalConfig
    const localGroupsById = normalizeLocalGroups(useLocal ? def.options?.localGroupsById : null)
    const localTargetsWeek = normalizeLocalTargets(useLocal ? def.options?.localTargetsWeek : null)
    const baseConfig =
      useLocal && def.options?.localConfig
        ? JSON.parse(JSON.stringify(def.options.localConfig))
        : ctx.targetsConfig
          ? JSON.parse(JSON.stringify(ctx.targetsConfig))
          : createDefaultTargetsConfig()
    const cfg = attachUi(copyConfigForRange(baseConfig, ctx.rangeMode))
    const applyBool = (key: string, setter: (val: boolean) => void) => {
      if (def.options?.[key] === undefined) return
      setter(!!def.options[key])
    }
    applyBool('showTotalDelta', (val) => { cfg.ui.showTotalDelta = val })
    applyBool('showNeedPerDay', (val) => { cfg.ui.showNeedPerDay = val })
    applyBool('showCategoryBlocks', (val) => { cfg.ui.showCategoryBlocks = val })
    applyBool('badges', (val) => { cfg.ui.badges = val })
    applyBool('includeWeekendToggle', (val) => { cfg.ui.includeWeekendToggle = val })
    applyBool('includeZeroDaysInStats', (val) => { cfg.includeZeroDaysInStats = val })

    const summary = useLocal && ctx.stats
      ? safeBuildTargetsSummary(cfg, {
          ...ctx,
          groupsById: localGroupsById ?? ctx.groupsById,
        })
      : (ctx.targetsSummary ?? ctx.summary)
    const currentTargets = useLocal
      ? targetsForRange(localTargetsWeek, ctx.rangeMode)
      : (ctx.currentTargets || {})
    const mode = detectTargetsDisplayMode({
      strategy: useLocal ? null : ctx?.onboardingStrategy,
      config: cfg,
      currentTargets,
    })

    if (!Object.prototype.hasOwnProperty.call(def.options ?? {}, 'showCategoryBlocks')) {
      cfg.ui.showCategoryBlocks = mode !== 'single_goal'
    }

    const groups = useLocal
      ? buildGroupsForMode({
          mode,
          config: cfg,
          summary,
          byCal: ctx.byCal || [],
          calendars: ctx.calendars || [],
          colorsById: ctx.colorsById || {},
          groupsById: localGroupsById ?? ctx.groupsById ?? {},
          currentTargets,
          todayHoursByCalendar: ctx.calendarTodayHours || {},
        })
      : (def.props?.groups ?? buildGroupsForMode({
          mode,
          config: cfg,
          summary,
          byCal: ctx.byCal || [],
          calendars: ctx.calendars || [],
          colorsById: ctx.colorsById || {},
          groupsById: ctx.groupsById ?? {},
          currentTargets,
          todayHoursByCalendar: ctx.calendarTodayHours || {},
        }))

    return {
      summary,
      config: cfg,
      groups,
      showHeader: def.options?.showHeader !== false,
      showLegend: def.options?.showLegend !== false,
      showDelta: def.options?.showDelta !== false,
      showForecast: def.options?.showForecast !== false,
      showPace: def.options?.showPace !== false,
      neverFinishedMode: def.options?.neverFinishedMode === true,
      showToday: def.options?.showToday !== false,
      title: buildTitle(baseTitle, def.options?.titlePrefix),
      cardBg: def.options?.cardBg,
      colorStyle: def.options?.colorStyle === 'outline' ? 'outline' : 'fill',
    }
  },
}

function detectTargetsDisplayMode(input: {
  strategy?: string | null
  config?: any
  currentTargets?: Record<string, number>
}): TargetsDisplayMode {
  const strategy = String(input?.strategy ?? '')
  if (strategy === 'total_only') return 'single_goal'
  if (strategy === 'total_plus_categories') return 'calendar_goals'
  if (strategy === 'full_granular') return 'category_and_calendar_goals'
  // Only use config as fallback for legacy users with no strategy set
  if (strategy !== '') return 'single_goal'
  const categories = Array.isArray(input?.config?.categories) ? input.config.categories : []
  if (categories.length > 0) return 'category_and_calendar_goals'
  const currentTargets = input?.currentTargets && typeof input.currentTargets === 'object' ? input.currentTargets : {}
  return Object.keys(currentTargets).length > 0 ? 'calendar_goals' : 'single_goal'
}

function buildGroupsForMode(input: {
  mode: TargetsDisplayMode
  config: any
  summary: any
  byCal: any[]
  calendars: any[]
  colorsById: Record<string, string>
  groupsById: Record<string, number>
  currentTargets: Record<string, number>
  todayHoursByCalendar: Record<string, number>
}) {
  if (input.mode === 'single_goal') return []
  if (input.mode === 'calendar_goals') {
    return buildCalendarGroups({
      config: input.config,
      summary: input.summary,
      byCal: input.byCal,
      calendars: input.calendars,
      colorsById: input.colorsById,
      currentTargets: input.currentTargets,
      todayHoursByCalendar: input.todayHoursByCalendar,
    })
  }
  return buildCategoryGroups({
    config: input.config,
    summary: input.summary,
    byCal: input.byCal,
    calendars: input.calendars,
    colorsById: input.colorsById,
    groupsById: input.groupsById,
    currentTargets: input.currentTargets,
  })
}

function normalizeLocalGroups(input: any): Record<string, number> | null {
  if (!input || typeof input !== 'object' || Array.isArray(input)) return null
  const result: Record<string, number> = {}
  Object.entries(input).forEach(([calendarId, value]) => {
    const id = String(calendarId || '').trim()
    if (!id) return
    result[id] = Math.max(0, Math.min(9, Math.trunc(Number(value) || 0)))
  })
  return result
}

function normalizeLocalTargets(input: any): Record<string, number> {
  if (!input || typeof input !== 'object' || Array.isArray(input)) return {}
  const result: Record<string, number> = {}
  Object.entries(input).forEach(([calendarId, value]) => {
    const id = String(calendarId || '').trim()
    const numeric = Number(value)
    if (!id || !Number.isFinite(numeric)) return
    result[id] = clampTarget(numeric)
  })
  return result
}

function targetsForRange(targetsWeek: Record<string, number>, rangeMode: string | undefined) {
  if (String(rangeMode || 'week').toLowerCase() !== 'month') {
    return { ...targetsWeek }
  }
  return Object.fromEntries(
    Object.entries(targetsWeek).map(([calendarId, value]) => [calendarId, convertWeekToMonth(value)]),
  )
}
