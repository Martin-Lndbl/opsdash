import { computed, nextTick, reactive, ref, type Ref } from 'vue'
import {
  createDefaultTargetsConfig,
  normalizeTargetsConfig,
  type TargetsConfig,
} from '../src/services/targets'
import {
  createDefaultReportingConfig,
  createDefaultDeckSettings,
  normalizeReportingConfig,
  normalizeDeckSettings,
  type ReportingConfig,
  type DeckFeatureSettings,
} from '../src/services/reporting'
import { normalizeWidgetTabs, type WidgetTabsState } from '../src/services/widgetsRegistry'
import { createDefaultWidgetTabs, setWidgetPresets } from '../src/services/widgetDefaults'
import { preferredScope, globalAppBg, globalAppBgLight, globalAppBgDark } from './useGlobalPreferences'
import { readBootstrapThemePreference } from '../src/services/theme'
import { readCurrentUserId } from '../src/services/currentUser'
import { setUserDateTimeConfig } from '../src/services/dateTime'

export interface OnboardingState {
  completed: boolean
  version: number
  strategy: string
  completed_at: string
  version_required?: number
  needsOnboarding?: boolean
  resetRequested?: boolean
  dashboardMode?: 'quick' | 'standard' | 'pro'
  releaseNotesSeenVersion?: string
}

interface DashboardDeps {
  range: Ref<'week' | 'month'>
  offset: Ref<number>
  userChangedSelection: Ref<boolean>
  route: (name: 'loadData') => string
  getJson: (url: string, params: Record<string, unknown>) => Promise<any>
  postJson: (url: string, body: Record<string, unknown>) => Promise<any>
  notifyError: (message: string) => void
  scheduleDraw: () => void
  isDebug?: () => boolean
  includeLookback?: () => boolean
  widgetTabs?: Ref<WidgetTabsState>
  onCoreLoaded?: (payload: any) => void
}

export function useDashboard(deps: DashboardDeps) {
  const calendars = ref<any[]>([])
  const colorsByName = ref<Record<string, string>>({})
  const colorsById = ref<Record<string, string>>({})
  const groupsById = ref<Record<string, number>>({})
  const selected = ref<string[]>([])

  const isLoading = ref(false)
  const hasLoadedData = ref(false)
  const isInitialLoading = computed(() => isLoading.value && !hasLoadedData.value)
  const isRefreshing = computed(() => isLoading.value && hasLoadedData.value)
  const isTruncated = ref(false)
  const truncLimits = ref<any>(null)
  const uid = ref('')
  const from = ref('')
  const to = ref('')

  const stats = reactive<any>({})
  const byCal = ref<any[]>([])
  const byDay = ref<any[]>([])
  const longest = ref<any[]>([])
  const charts = ref<any>({})

  const targetsWeek = ref<Record<string, number>>({})
  const targetsMonth = ref<Record<string, number>>({})
  const targetsConfig = ref<TargetsConfig>(normalizeTargetsConfig(createDefaultTargetsConfig()))
  const onboarding = ref<OnboardingState | null>(null)
  const bootstrapThemePreference = readBootstrapThemePreference() ?? 'auto'
  const themePreference = ref<'auto' | 'light' | 'dark'>(bootstrapThemePreference)
  const reportingConfig = ref<ReportingConfig>(createDefaultReportingConfig())
  const deckSettings = ref<DeckFeatureSettings>(createDefaultDeckSettings())

  let loadSeq = 0
  let activeLoad: Promise<void> | null = null

  function load(): Promise<void> {
    // A manual refresh can be clicked faster than the server's per-user load
    // limiter permits. Reuse the active request instead of producing a second
    // expensive request that would receive HTTP 429.
    if (activeLoad) return activeLoad

    activeLoad = runLoad().finally(() => {
      activeLoad = null
    })
    return activeLoad
  }

  async function runLoad() {
    const currentSeq = ++loadSeq
    isLoading.value = true
    const prevColorsById: Record<string, string> = { ...colorsById.value }
    const prevColorsByName: Record<string, string> = { ...colorsByName.value }
    let colorsAdjusted = false
    try {
      const debugEnabled = deps.isDebug?.() === true

      const cloneColorMap = (input: any): Record<string, string> => {
        const result: Record<string, string> = {}
        if (input && typeof input === 'object') {
          Object.entries(input).forEach(([key, value]) => {
            if (typeof value === 'string' && value.trim() !== '') {
              result[String(key)] = value
            }
          })
        }
        return result
      }

      const applyPaletteToCharts = () => {
        let changed = false
        try {
          const pieData: any = charts.value?.pie || {}
          const ids: string[] = Array.isArray(pieData.ids)
            ? pieData.ids.map((id: any) => String(id ?? ''))
            : []
          if (ids.length) {
            const srvCols: string[] = Array.isArray(pieData.colors) ? pieData.colors : []
            const newCols = ids.map(
              (id, idx) => colorsById.value[id] || srvCols[idx] || '#60a5fa',
            )
            charts.value = { ...(charts.value || {}), pie: { ...pieData, colors: newCols } }
            changed = true
          }
        } catch (error) {
          if (deps.isDebug?.()) console.warn('recompute pie colors failed', error)
        }
        try {
          const perDaySeries: any = charts.value?.perDaySeries
          if (perDaySeries && Array.isArray(perDaySeries.series)) {
            const updatedSeries = perDaySeries.series.map((series: any) => {
              const id = String(series?.id ?? '')
              const color = colorsById.value[id] || series.color || '#60a5fa'
              return { ...series, color }
            })
            charts.value = {
              ...(charts.value || {}),
              perDaySeries: { labels: perDaySeries.labels, series: updatedSeries },
            }
            changed = true
          }
        } catch (error) {
          if (deps.isDebug?.()) console.warn('recompute stacked colors failed', error)
        }
        return changed
      }

      const applyCorePayload = (json: any) => {
        const localUid = readCurrentUserId()
        uid.value = localUid || String(json.meta?.uid ?? '')
        from.value = String(json.meta?.from ?? '')
        to.value = String(json.meta?.to ?? '')
        isTruncated.value = Boolean(json.meta?.truncated)
        truncLimits.value = json.meta?.limits ?? null

        const settingsRaw = json.userSettings && typeof json.userSettings === 'object' ? json.userSettings : null
        if (settingsRaw) {
          const timezone = typeof settingsRaw.timezone === 'string' ? settingsRaw.timezone : ''
          const locale = typeof settingsRaw.locale === 'string' ? settingsRaw.locale : ''
          const firstDay = typeof settingsRaw.firstDayOfWeek === 'number' ? settingsRaw.firstDayOfWeek : undefined
          setUserDateTimeConfig({
            timeZone: timezone || undefined,
            locale: locale || undefined,
            firstDayOfWeek: firstDay,
          })
        }

        const rawCalendars = Array.isArray(json.calendars) ? json.calendars : []
        const nextColorsById = cloneColorMap(json.colors?.byId)
        const nextColorsByName = cloneColorMap(json.colors?.byName)
        const normalizedCalendars = rawCalendars.map((raw: any) => {
          if (!raw || typeof raw !== 'object') {
            return raw
          }

          const cal: Record<string, any> = { ...raw }
          const id = String(cal.id ?? '')
          const name = String(cal.displayname ?? cal.name ?? cal.calendar ?? id)
          const originalColor = typeof cal.color === 'string' ? cal.color : ''
          const originalSrc = typeof cal.color_src === 'string' ? cal.color_src : ''
          const serverColor = id ? nextColorsById[id] : ''
          const nameColor = name ? nextColorsByName[name] : ''
          const previousColorById = id ? prevColorsById[id] : ''
          const previousColorByName = name ? prevColorsByName[name] : ''
          const previousColor = previousColorById || previousColorByName

          let resolvedColor = originalColor
          if (!resolvedColor && serverColor) {
            resolvedColor = serverColor
          }
          if (!resolvedColor && nameColor) {
            resolvedColor = nameColor
          }
          if ((!resolvedColor || originalSrc === 'fallback') && previousColor) {
            resolvedColor = previousColor
          }
          if (!resolvedColor && previousColor) {
            resolvedColor = previousColor
          }

          if (resolvedColor) {
            if (resolvedColor !== originalColor) {
              colorsAdjusted = true
            }
            cal.color = resolvedColor
            if (id) nextColorsById[id] = resolvedColor
            if (name) nextColorsByName[name] = resolvedColor
          }

          return cal
        })

        calendars.value = normalizedCalendars
        colorsByName.value = nextColorsByName
        colorsById.value = nextColorsById
        // Some partial payloads may omit onboarding; keep existing state unless server explicitly sends it.
        if (Object.prototype.hasOwnProperty.call(json, 'onboarding')) {
          onboarding.value = json.onboarding && typeof json.onboarding === 'object'
            ? { ...json.onboarding }
            : null
        }
        reportingConfig.value = normalizeReportingConfig(json.reportingConfig, reportingConfig.value)
        deckSettings.value = normalizeDeckSettings(json.deckSettings, deckSettings.value)
        if (json.widgetPresets) {
          setWidgetPresets(json.widgetPresets)
        }
        if (deps.widgetTabs) {
          const mode = json.onboarding?.dashboardMode
          const fallback = deps.widgetTabs.value || createDefaultWidgetTabs(
            mode === 'quick' || mode === 'pro' ? mode : 'standard',
            json.onboarding?.strategy,
          )
          deps.widgetTabs.value = normalizeWidgetTabs(json.widgets, fallback)
        }

        const groupSource = json.groups?.byId ?? json.groups?.byID ?? json.groups?.ids ?? {}
        const mappedGroups: Record<string, number> = {}
        calendars.value.forEach((cal: any) => {
          const id = String(cal?.id ?? '')
          const raw = Number(groupSource?.[id] ?? 0)
          mappedGroups[id] = Number.isFinite(raw) ? Math.max(0, Math.min(9, Math.trunc(raw))) : 0
        })
        groupsById.value = mappedGroups

        const tw = json.targets?.week ?? {}
        const tm = json.targets?.month ?? {}
        targetsWeek.value = tw && typeof tw === 'object' ? tw : {}
        targetsMonth.value = tm && typeof tm === 'object' ? tm : {}
        targetsConfig.value = normalizeTargetsConfig(json.targetsConfig ?? createDefaultTargetsConfig())
        const themeRaw = typeof json.themePreference === 'string' ? json.themePreference : ''
        themePreference.value = themeRaw === 'light' || themeRaw === 'dark' ? (themeRaw as 'light' | 'dark') : 'auto'
        // Global user preferences (persisted server-side; falls back to defaults when absent).
        if (json.preferredScope === 'calendar' || json.preferredScope === 'category') {
          preferredScope.value = json.preferredScope
        }
        // Per-theme slots. We deliberately ignore the legacy
        // single-value globalAppBg here: seeding both light and dark
        // slots from one saved color reintroduces the exact bleed the
        // split was meant to fix. Users who had a picked bg lose it
        // once and re-pick per theme; the CSS defaults (#EFEFEF /
        // #111111) apply until they do.
        const parseBg = (v: unknown) =>
          typeof v === 'string' && /^#[0-9a-fA-F]{6}$/.test(v) ? v
          : v === null ? null : undefined
        const lightRaw = parseBg(json.globalAppBgLight)
        const darkRaw = parseBg(json.globalAppBgDark)
        if (lightRaw !== undefined) globalAppBgLight.value = lightRaw
        if (darkRaw !== undefined) globalAppBgDark.value = darkRaw
        void globalAppBg

        if (deps.isDebug?.()) {
          console.group('[opsdash] calendars/colors')
          console.table((calendars.value || []).map((c: any) => ({
            id: c.id,
            name: c.displayname,
            color: c.color,
            raw: (c as any).color_raw,
            src: (c as any).color_src,
          })))
          console.log('colors.byId', colorsById.value)
          console.log('colors.byName', colorsByName.value)
          if ((json as any).calDebug) console.log('server calDebug', (json as any).calDebug)
          if ((json as any).debug) {
            console.group('[opsdash] server query debug')
            console.log((json as any).debug)
            console.groupEnd()
          }
          console.groupEnd()
        }

        // Normalize to string IDs to keep UI selection reactive and consistent
        selected.value = Array.isArray(json.selected)
          ? (json.selected as any[]).map((id) => String(id))
          : Array.isArray(json.saved)
            ? (json.saved as any[]).map((id) => String(id))
            : []
        deps.userChangedSelection.value = false

        if (colorsAdjusted) {
          applyPaletteToCharts()
        }
      }

      const applyDataPayload = async (json: any) => {
        from.value = String(json.meta?.from ?? from.value)
        to.value = String(json.meta?.to ?? to.value)
        isTruncated.value = Boolean(json.meta?.truncated)
        truncLimits.value = json.meta?.limits ?? null

        Object.assign(stats, json.stats ?? {})
        byCal.value = Array.isArray(json.byCal) ? json.byCal : []
        byDay.value = Array.isArray(json.byDay) ? json.byDay : []
        longest.value = Array.isArray(json.longest) ? json.longest : []
        charts.value = json.charts ?? {}
        hasLoadedData.value = true
        if (colorsAdjusted) {
          applyPaletteToCharts()
        }

        await nextTick()
        deps.scheduleDraw()

      }

      const coreParams: Record<string, unknown> = {
        range: deps.range.value,
        offset: deps.offset.value | 0,
        include: ['core'],
      }
      if (debugEnabled) {
        coreParams.include = ['core', 'debug']
        coreParams.debug = true
      }
      const coreJson: any = await deps.getJson(deps.route('loadData'), coreParams)
      if (currentSeq !== loadSeq) {
        if (deps.isDebug?.()) console.warn('discarding stale core response', { currentSeq, loadSeq })
        return
      }
      applyCorePayload(coreJson)
      deps.onCoreLoaded?.(coreJson)

      const includeLookback = deps.includeLookback?.() === true
      const dataInclude = ['data']
      if (includeLookback) dataInclude.push('lookback')
      if (debugEnabled) dataInclude.push('debug')
      const dataParams: Record<string, unknown> = {
        range: deps.range.value,
        offset: deps.offset.value | 0,
        include: dataInclude,
      }
      if (debugEnabled) {
        dataParams.debug = true
      }
      const dataJson: any = await deps.postJson(deps.route('loadData'), dataParams)
      if (currentSeq !== loadSeq) {
        if (deps.isDebug?.()) console.warn('discarding stale data response', { currentSeq, loadSeq })
        return
      }
      await applyDataPayload(dataJson)
    } catch (error) {
      console.error(error)
      deps.notifyError('Failed to load data')
    } finally {
      isLoading.value = false
    }
  }

  return {
    calendars,
    colorsByName,
    colorsById,
    groupsById,
    selected,
    isLoading,
    isInitialLoading,
    isRefreshing,
    isTruncated,
    truncLimits,
    uid,
    from,
    to,
    stats,
    byCal,
    byDay,
    longest,
    charts,
    targetsWeek,
    targetsMonth,
    targetsConfig,
    onboarding,
    load,
    themePreference,
    reportingConfig,
    deckSettings,
  }
}
