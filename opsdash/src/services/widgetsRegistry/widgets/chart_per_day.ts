import { defineAsyncComponent } from 'vue'
import type { RegistryEntry } from '../types'
import { buildTitle } from '../helpers'
import {
  aggregateStackedByCategory,
  buildChartFilterControls,
  buildStackedWithForecast,
  buildPerDayFromStacked,
  filterStackedByIds,
  formatLookbackLabel,
  getLookbackColor,
  resolveChartFilter,
  buildCategoryLabelMap,
  sortLookbackOffsets,
} from './chartHelpers'

const ChartPerDayWidget = defineAsyncComponent(() =>
  import('../../../components/widgets/charts/ChartPerDayWidget.vue').then((m) => m.default),
)

const baseTitle = 'Daily Chart'

export const chartPerDayEntry: RegistryEntry = {
  component: ChartPerDayWidget,
  defaultLayout: { width: 'half', height: 'm', order: 82 },
  label: 'Daily Chart',
  category: 'Charts' as const,
  baseTitle,
  configurable: true,
  defaultOptions: {
    showLabels: false,
    compact: false,
    reverseOrder: false,
    forecastMode: 'total',
      colorStyle: 'fill',
    colorTint: 0,
  },
  dynamicControls: (options, ctx) => {
    return [
      ...buildChartFilterControls(options, ctx),
      { key: 'forecastMode', label: 'Projection mode', type: 'select', options: [
        { value: 'off', label: 'No projection' },
        { value: 'total', label: 'Distribute remaining total target' },
        { value: 'calendar', label: 'Respect calendar targets' },
        { value: 'category', label: 'Respect category targets' },
      ] },
      { key: 'showLabels', label: 'Show labels', type: 'toggle' },
      { key: 'compact', label: 'Compact', type: 'toggle' },
      { key: 'reverseOrder', label: 'Reverse order (newest first)', type: 'toggle' },
    ]
  },
  buildProps: (def, ctx) => {
    const { mode, ids } = resolveChartFilter(def.options)
    const categoryColorMap = ctx.categoryColorMap || {}
    const lookbackWeeks = Number.isFinite(ctx.lookbackWeeks) ? Math.max(1, Math.min(6, Number(ctx.lookbackWeeks))) : 1
    const lookbackInput =
      lookbackWeeks > 1 && Array.isArray(ctx.charts?.perDaySeriesByOffset)
        ? ctx.charts.perDaySeriesByOffset
        : null
    const reverseOrder = def.options?.reverseOrder === true
    let chartData: { labels: string[]; data: number[]; colors?: string[] } | null = null
    let legendItems: Array<{ id: string; label: string; color: string }> = []

    if (lookbackInput && lookbackInput.length) {
      const sorted = sortLookbackOffsets<any>(lookbackInput as any[])
      const ordered = reverseOrder ? sorted : sorted.slice().reverse()
      const labels: string[] = []
      const data: number[] = []
      const colors: string[] = []
      legendItems = []
      ordered.forEach((entry, idx) => {
        const perDaySeries = { labels: entry.labels || [], series: entry.series || [] }
        const baseStacked = buildStackedWithForecast({
          perDaySeries,
          forecastMode: def.options?.forecastMode,
          targetsConfig: ctx.targetsConfig,
          currentTargets: ctx.currentTargets,
          calendarCategoryMap: ctx.calendarCategoryMap,
        })
        const stacked =
          mode === 'category'
            ? aggregateStackedByCategory(baseStacked, ctx.calendarCategoryMap || {}, ids, categoryColorMap, buildCategoryLabelMap(ctx))
            : filterStackedByIds(baseStacked, ids)
        const perDay = buildPerDayFromStacked(stacked)
        if (!perDay) return
        const color = getLookbackColor(idx)
        labels.push(...perDay.labels)
        data.push(...perDay.data)
        colors.push(...perDay.data.map(() => color))
        const total = perDay.data.reduce((sum, value) => sum + Math.max(0, Number(value) || 0), 0)
        if (total > 0) {
          legendItems.push({
            id: `offset-${entry.offset ?? idx}`,
            label: formatLookbackLabel(entry, ctx.rangeMode),
            color,
          })
        }
      })
      chartData = labels.length ? { labels, data, colors } : null
    } else {
      const baseStacked = buildStackedWithForecast({
        perDaySeries: ctx.charts?.perDaySeries,
        forecastMode: def.options?.forecastMode,
        targetsConfig: ctx.targetsConfig,
        currentTargets: ctx.currentTargets,
        calendarCategoryMap: ctx.calendarCategoryMap,
      })
      const stacked =
        mode === 'category'
          ? aggregateStackedByCategory(baseStacked, ctx.calendarCategoryMap || {}, ids, categoryColorMap, buildCategoryLabelMap(ctx))
          : filterStackedByIds(baseStacked, ids)
      chartData = buildPerDayFromStacked(stacked)
    }
    return {
      title: buildTitle(baseTitle, def.options?.titlePrefix),
      subtitle: mode === 'category' ? 'Totals by category' : 'Totals by calendar',
      cardBg: def.options?.cardBg,
      showHeader: def.options?.showHeader !== false,
      showLabels: def.options?.showLabels === true,
      colorStyle: def.options?.colorStyle === 'outline' ? 'outline' : 'fill',
      colorTint: Number.isFinite(Number(def.options?.colorTint)) ? Math.max(0, Math.min(100, Number(def.options?.colorTint))) : 0,
      compact: def.options?.compact === true,
      xLabel: 'Date',
      yLabel: 'Hours (h)',
      chartData,
      legendItems,
    }
  },
}
