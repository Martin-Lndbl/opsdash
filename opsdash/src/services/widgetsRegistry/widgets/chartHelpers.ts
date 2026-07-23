import { createDefaultTargetsConfig, type ActivityForecastMode, type TargetsConfig } from '../../targets'
import { formatDateKey, getWeekdayOrder, parseDateKey } from '../../dateTime'
import { preferredScope } from '../../../../composables/useGlobalPreferences'

type PieData = { ids: string[]; labels: string[]; data: number[]; colors?: string[] }
type StackedData = { labels: string[]; series: Array<{ id: string; name?: string; label?: string; color?: string; data?: number[]; forecast?: number[] }> }
type ChartFilterMode = 'category' | 'calendar'

const LOOKBACK_PALETTE = ['#9aa6b2', '#8895a3', '#778697', '#68788a', '#596b7e', '#4d6074']

export function getLookbackColor(index: number): string {
  return LOOKBACK_PALETTE[index % LOOKBACK_PALETTE.length] || '#6f8193'
}

export function sortLookbackOffsets<T extends { offset?: number }>(input: T[]): T[] {
  return input.slice().sort((a, b) => {
    const aOffset = Number(a?.offset ?? 0)
    const bOffset = Number(b?.offset ?? 0)
    return aOffset - bOffset
  })
}

export function formatLookbackLabel(entry: { offset?: number; from?: string; to?: string }, rangeMode?: string): string {
  const unit = String(rangeMode || '').toLowerCase() === 'month' ? 'Month' : 'Week'
  const offset = Number(entry?.offset ?? 0)
  const base = offset === 0 ? `Current ${unit.toLowerCase()}` : `${unit} -${offset}`
  const from = String(entry?.from ?? '').trim()
  const to = String(entry?.to ?? '').trim()
  if (from && to) return `${base} (${from} to ${to})`
  if (from) return `${base} (${from})`
  return base
}

export function parseIdList(input: any): string[] {
  if (Array.isArray(input)) {
    return input.map((val) => String(val ?? '').trim()).filter(Boolean)
  }
  if (typeof input === 'string') {
    return input.split(',').map((val) => val.trim()).filter(Boolean)
  }
  return []
}

export function normalizeChartFilterMode(input: any): ChartFilterMode {
  if (input === 'calendar' || input === 'category') return input
  // No per-widget override -> follow the global preference set from
  // TimeSummary's calendars/categories tab (or last user choice).
  return preferredScope.value
}

export function buildCategoryLabelMap(ctx: any): Record<string, string> {
  const groups = Array.isArray(ctx?.calendarGroups) ? ctx.calendarGroups : []
  const out: Record<string, string> = {}
  groups.forEach((group: any) => {
    const id = String(group?.id ?? '')
    if (!id) return
    const label = group?.label ?? group?.name ?? id
    out[id] = String(label)
  })
  return out
}

export function buildChartFilterControls(options: any, ctx: any) {
  const mode = normalizeChartFilterMode(options?.filterMode)
  const calOptions = Array.isArray(ctx?.calendars)
    ? ctx.calendars.map((cal: any) => ({ value: cal.id, label: cal.displayname || cal.name || cal.id }))
    : []
  const catOptions = Array.isArray(ctx?.calendarGroups)
    ? ctx.calendarGroups.map((cat: any) => ({ value: cat.id, label: cat.label || cat.id }))
    : []
  const list = mode === 'calendar' ? calOptions : catOptions
  return [
    { key: 'filterMode', label: 'Filter by', type: 'select', options: [
      { value: 'category', label: 'Category' },
      { value: 'calendar', label: 'Calendar' },
    ] },
    { key: 'filterIds', label: mode === 'calendar' ? 'Calendars' : 'Categories', type: 'multiselect', options: list, defaultAll: true },
  ]
}

export function resolveChartFilter(options: Record<string, any> | undefined): { mode: ChartFilterMode; ids: Set<string> } {
  const mode = normalizeChartFilterMode(options?.filterMode)
  return { mode, ids: new Set(parseIdList(options?.filterIds)) }
}

export function filterPieByIds(pie: PieData | null | undefined, allow: Set<string>): PieData | null {
  if (!pie || !Array.isArray(pie.data)) return null
  if (!allow.size) return pie
  const ids = pie.ids || []
  const labels = pie.labels || []
  const data = pie.data || []
  const colors = pie.colors || []
  const idx = ids.map((id, i) => (allow.has(String(id)) ? i : -1)).filter((i) => i >= 0)
  if (!idx.length) return null
  return {
    ids: idx.map((i) => String(ids[i] ?? '')),
    labels: idx.map((i) => String(labels[i] ?? '')),
    data: idx.map((i) => Number(data[i] ?? 0)),
    colors: idx.map((i) => colors[i]),
  }
}

export function filterStackedByIds(stacked: StackedData | null | undefined, allow: Set<string>): StackedData | null {
  if (!stacked || !Array.isArray(stacked.series)) return null
  if (!allow.size) return stacked
  const series = stacked.series.filter((row) => allow.has(String(row?.id ?? '')))
  if (!series.length) return null
  return { labels: stacked.labels || [], series }
}

export function aggregateStackedByCategory(
  stacked: StackedData | null | undefined,
  calendarCategoryMap: Record<string, string>,
  categoryFilter: Set<string>,
  categoryColorMap: Record<string, string>,
  categoryLabelMap: Record<string, string> = {},
): StackedData | null {
  if (!stacked || !Array.isArray(stacked.series)) return null
  const labels = stacked.labels || []
  const dataMap = new Map<string, number[]>()
  const forecastMap = new Map<string, number[]>()
  let anyForecast = false
  stacked.series.forEach((row) => {
    const calId = String(row?.id ?? '')
    const catId = String(calendarCategoryMap?.[calId] ?? '')
    if (!catId) return
    if (categoryFilter.size && !categoryFilter.has(catId)) return
    if (!dataMap.has(catId)) {
      dataMap.set(catId, Array.from({ length: labels.length }, () => 0))
      forecastMap.set(catId, Array.from({ length: labels.length }, () => 0))
    }
    const dataTarget = dataMap.get(catId)
    const forecastTarget = forecastMap.get(catId)
    const data = Array.isArray(row?.data) ? row.data : []
    const forecast = Array.isArray((row as any)?.forecast) ? (row as any).forecast as number[] : null
    if (forecast) anyForecast = true
    labels.forEach((_, idx) => {
      if (dataTarget) dataTarget[idx] += Math.max(0, Number(data[idx] ?? 0))
      if (forecastTarget && forecast) forecastTarget[idx] += Math.max(0, Number(forecast[idx] ?? 0))
    })
  })
  if (!dataMap.size) return null
  const series = Array.from(dataMap.entries()).map(([catId, data]) => {
    const displayName = categoryLabelMap?.[catId] || catId
    const entry: StackedData['series'][number] = {
      id: catId,
      name: displayName,
      label: displayName,
      color: categoryColorMap?.[catId],
      data,
    }
    if (anyForecast) {
      const fc = forecastMap.get(catId)
      if (fc && fc.some((v) => v > 0)) {
        (entry as any).forecast = fc
      }
    }
    return entry
  })
  return { labels, series }
}

export function buildPerDayFromStacked(stacked: StackedData | null | undefined): { labels: string[]; data: number[] } | null {
  if (!stacked || !Array.isArray(stacked.series)) return null
  const labels = stacked.labels || []
  const data = labels.map((_, idx) =>
    stacked.series.reduce((sum, row) => sum + Math.max(0, Number(row?.data?.[idx] ?? 0)), 0),
  )
  return { labels, data }
}

export function buildDowFromPerDay(perDay: { labels: string[]; data: number[] } | null): { labels: string[]; data: number[] } | null {
  if (!perDay) return null
  const order = getWeekdayOrder()
  const buckets = new Map<string, number>()
  order.forEach((day) => buckets.set(day, 0))
  perDay.labels.forEach((label, idx) => {
    const day = dayOfWeek(label)
    if (!day) return
    buckets.set(day, (buckets.get(day) || 0) + Math.max(0, Number(perDay.data[idx] ?? 0)))
  })
  return { labels: order, data: order.map((day) => buckets.get(day) || 0) }
}

export function buildCategoryPie(
  groups: Array<{ id: string; label?: string; summary?: { actualHours?: number }; color?: string }>,
  categoryFilter: Set<string>,
  categoryColorMap: Record<string, string>,
): PieData | null {
  if (!Array.isArray(groups) || !groups.length) return null
  const filtered = groups.filter((group) => {
    if (!group || !group.id) return false
    if (!categoryFilter.size) return true
    return categoryFilter.has(String(group.id))
  })
  if (!filtered.length) return null
  return {
    ids: filtered.map((group) => String(group.id)),
    labels: filtered.map((group) => String(group.label ?? group.id)),
    data: filtered.map((group) => Math.max(0, Number(group.summary?.actualHours ?? 0))),
    colors: filtered.map((group) => group.color || categoryColorMap?.[String(group.id)]),
  }
}

export function buildStackedWithForecast(input: {
  perDaySeries: any
  forecastMode?: ActivityForecastMode
  targetsConfig?: TargetsConfig | null
  currentTargets?: Record<string, number> | null
  calendarCategoryMap?: Record<string, string> | null
}): StackedData | null {
  const raw = input.perDaySeries
  if (!raw || !Array.isArray(raw.labels) || !Array.isArray(raw.series)) {
    return null
  }

  const labels: string[] = raw.labels.map((label: any) => String(label ?? ''))
  const series = raw.series.map((entry: any) => {
    const id = String(entry?.id ?? '')
    return {
      ...entry,
      id,
      name: String(entry?.name ?? entry?.label ?? id),
      color: String(entry?.color ?? ''),
      data: labels.map((_, idx) => {
        const rawVal = Number(entry?.data?.[idx] ?? 0)
        return Number.isFinite(rawVal) ? Math.max(0, rawVal) : 0
      }),
    }
  })

  const mode = normalizeMode(input.forecastMode)
  const todayKey = formatDateKey(new Date())
  const isFuture = labels.map((label) => DATE_KEY_RX.test(label) && label > todayKey)
  const futureIndices: number[] = []
  isFuture.forEach((flag, idx) => {
    if (flag) futureIndices.push(idx)
  })

  if (!futureIndices.length || mode === 'off') {
    return { labels, series }
  }

  const cfg = input.targetsConfig ?? createDefaultTargetsConfig()
  const targetsMap = sanitizeTargetsMap(input.currentTargets)
  const categoryAssignments = input.calendarCategoryMap ?? {}
  const futureDays = futureIndices.length

  const actualByCalendar = new Map<string, number>()
  series.forEach((row) => {
    let sum = 0
    row.data.forEach((value: number, idx: number) => {
      if (!isFuture[idx]) {
        sum += Math.max(0, value)
      }
    })
    actualByCalendar.set(row.id, sum)
  })
  const actualTotal = Array.from(actualByCalendar.values()).reduce((sum, value) => sum + value, 0)

  const forecastByCalendar = new Map<string, number[]>()
  series.forEach((row) => {
    forecastByCalendar.set(row.id, makeZeroArray(labels.length))
  })

  if (mode === 'total') {
    const targetTotal = Math.max(0, Number(cfg.totalHours ?? 0))
    const remaining = Math.max(0, targetTotal - actualTotal)
    if (remaining > 0.0001) {
      const perDay = remaining / futureDays
      const ids = series.map((row) => row.id)
      const weights = computeWeights(ids, actualByCalendar, targetsMap)
      ids.forEach((id) => {
        const weight = weights[id] ?? 0
        if (weight <= 0) return
        const arr = forecastByCalendar.get(id)
        if (!arr) return
        futureIndices.forEach((idx) => {
          arr[idx] = roundHours(perDay * weight)
        })
      })
    }
  } else if (mode === 'calendar') {
    series.forEach((row) => {
      const target = targetsMap[row.id] ?? 0
      if (target <= 0) return
      const actual = Math.max(0, actualByCalendar.get(row.id) ?? 0)
      const remaining = Math.max(0, target - actual)
      if (remaining <= 0.0001) return
      const perDay = remaining / futureDays
      const arr = forecastByCalendar.get(row.id)
      if (!arr) return
      futureIndices.forEach((idx) => {
        arr[idx] = roundHours(perDay)
      })
    })
  } else if (mode === 'category') {
    const processed = new Set<string>()
    const categories = Array.isArray(cfg.categories) ? cfg.categories : []
    const categoryTargets = new Map<string, number>()
    categories.forEach((cat: any) => {
      categoryTargets.set(String(cat.id), Math.max(0, Number(cat.targetHours ?? 0)))
    })
    const calendarsByCategory = new Map<string, string[]>()
    series.forEach((row) => {
      const catIdRaw = categoryAssignments?.[row.id]
      const catId = catIdRaw ? String(catIdRaw) : UNCATEGORIZED_ID
      if (!calendarsByCategory.has(catId)) {
        calendarsByCategory.set(catId, [])
      }
      calendarsByCategory.get(catId)!.push(row.id)
    })
    calendarsByCategory.forEach((calIds, catId) => {
      if (!calIds.length) return
      const target = categoryTargets.get(catId) ?? 0
      if (target <= 0) return
      const actual = calIds.reduce((sum, id) => sum + Math.max(0, actualByCalendar.get(id) ?? 0), 0)
      const remaining = Math.max(0, target - actual)
      if (remaining <= 0.0001) return
      const perDay = remaining / futureDays
      const targetSubset: Record<string, number> = {}
      calIds.forEach((id) => {
        targetSubset[id] = targetsMap[id] ?? 0
      })
      const weights = computeWeights(calIds, actualByCalendar, targetSubset)
      calIds.forEach((id) => {
        const weight = weights[id] ?? 0
        if (weight <= 0) return
        const arr = forecastByCalendar.get(id)
        if (!arr) return
        futureIndices.forEach((idx) => {
          arr[idx] = roundHours(perDay * weight)
        })
        processed.add(id)
      })
    })
    series.forEach((row) => {
      if (processed.has(row.id)) return
      const target = targetsMap[row.id] ?? 0
      if (target <= 0) return
      const actual = Math.max(0, actualByCalendar.get(row.id) ?? 0)
      const remaining = Math.max(0, target - actual)
      if (remaining <= 0.0001) return
      const perDay = remaining / futureDays
      const arr = forecastByCalendar.get(row.id)
      if (!arr) return
      futureIndices.forEach((idx) => {
        arr[idx] = roundHours(perDay)
      })
    })
  }

  series.forEach((row) => {
    const arr = forecastByCalendar.get(row.id)
    if (!arr) return
    if (arr.some((value) => value > 0.0001)) {
      row.forecast = arr
    }
  })

  return { labels, series }
}

function dayOfWeek(label: string): string | null {
  const d = parseDateKey(String(label))
  if (!d) return null
  const names = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']
  const name = names[d.getUTCDay()]
  if (!name) return null
  if (name === 'Sun') return 'Sun'
  return name
}

const DATE_KEY_RX = /^\d{4}-\d{2}-\d{2}$/
const UNCATEGORIZED_ID = '__uncategorized__'

function normalizeMode(mode: ActivityForecastMode | undefined): ActivityForecastMode {
  if (mode === 'off' || mode === 'calendar' || mode === 'category') {
    return mode
  }
  return 'total'
}

function makeZeroArray(length: number): number[] {
  return Array.from({ length }, () => 0)
}

function roundHours(value: number): number {
  const rounded = Math.round(value * 100) / 100
  return Number.isFinite(rounded) ? rounded : 0
}

function sanitizeTargetsMap(map: Record<string, number> | null | undefined): Record<string, number> {
  const result: Record<string, number> = {}
  if (!map) return result
  Object.entries(map).forEach(([key, val]) => {
    const num = Number(val)
    if (Number.isFinite(num) && num > 0) {
      result[String(key)] = num
    }
  })
  return result
}

function computeWeights(
  ids: string[],
  actualByCalendar: Map<string, number>,
  targetMap: Record<string, number>,
): Record<string, number> {
  const weights: Record<string, number> = {}
  const actualSum = ids.reduce((sum, id) => sum + Math.max(0, actualByCalendar.get(id) ?? 0), 0)
  if (actualSum > 0.0001) {
    ids.forEach((id) => {
      const value = Math.max(0, actualByCalendar.get(id) ?? 0)
      weights[id] = value / actualSum
    })
    return weights
  }
  const targetSum = ids.reduce((sum, id) => sum + Math.max(0, targetMap?.[id] ?? 0), 0)
  if (targetSum > 0.0001) {
    ids.forEach((id) => {
      const value = Math.max(0, targetMap?.[id] ?? 0)
      weights[id] = value / targetSum
    })
    return weights
  }
  const equalShare = ids.length ? 1 / ids.length : 0
  ids.forEach((id) => {
    weights[id] = equalShare
  })
  return weights
}
