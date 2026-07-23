import { defineAsyncComponent } from 'vue'
import type { RegistryEntry } from '../types'
import { buildTitle } from '../helpers'
import {
  aggregateStackedByCategory,
  buildChartFilterControls,
  buildStackedWithForecast,
  buildDowFromPerDay,
  buildPerDayFromStacked,
  filterStackedByIds,
  formatLookbackLabel,
  getLookbackColor,
  resolveChartFilter,
  buildCategoryLabelMap,
  sortLookbackOffsets,
} from './chartHelpers'

const ChartDowWidget = defineAsyncComponent(() =>
  import('../../../components/widgets/charts/ChartDowWidget.vue').then((m) => m.default),
)

const baseTitle = 'Day-of-Week Chart'

export const chartDowEntry: RegistryEntry = {
  component: ChartDowWidget,
  defaultLayout: { width: 'half', height: 'm', order: 84 },
  label: 'Day-of-Week Chart',
  category: 'Charts' as const,
  baseTitle,
  configurable: true,
  defaultOptions: {
    showLabels: true,
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
      { key: 'colorStyle', label: 'Color style', type: 'select', options: [
        { value: 'fill', label: 'Fill' },
        { value: 'outline', label: 'Border only' },
      ] },
      { key: 'colorTint', label: 'Border-mode color tint', type: 'select', options: [
        { value: 0, label: 'Off' },
        { value: 30, label: 'Subtle' },
        { value: 60, label: 'Medium' },
        { value: 100, label: 'Strong' },
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
    let chartData: { labels?: string[]; data?: number[] } | null = null
    let groupedData: { labels: string[]; series: Array<{ id: string; name?: string; label?: string; color?: string; data?: number[] }> } | null = null
    let legendItems: Array<{ id: string; label: string; color: string }> = []

    if (lookbackInput && lookbackInput.length) {
      const sorted = sortLookbackOffsets<any>(lookbackInput as any[])
      const ordered = reverseOrder ? sorted : sorted.slice().reverse()
      const labels: string[] = []
      const series: Array<{ id: string; name?: string; label?: string; color?: string; data?: number[] }> = []
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
        const dow = buildDowFromPerDay(perDay)
        if (!dow) return
        if (!labels.length) labels.push(...(dow.labels || []))
        const color = getLookbackColor(idx)
        const label = formatLookbackLabel(entry, ctx.rangeMode)
        const total = (dow.data || []).reduce((sum, value) => sum + Math.max(0, Number(value) || 0), 0)
        series.push({
          id: `offset-${entry.offset ?? idx}`,
          name: label,
          label,
          color,
          data: dow.data || [],
        })
        if (total > 0) {
          legendItems.push({ id: `offset-${entry.offset ?? idx}`, label, color })
        }
      })
      groupedData = labels.length && series.length ? { labels, series } : null
    } else {
      const perDaySeriesInput = ctx.charts?.perDaySeries
      const baseStacked = buildStackedWithForecast({
        perDaySeries: perDaySeriesInput,
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
      chartData = buildDowFromPerDay(perDay)
    }
    return {
      title: buildTitle(baseTitle, def.options?.titlePrefix),
      subtitle: mode === 'category' ? 'By category' : 'By calendar',
      cardBg: def.options?.cardBg,
      showHeader: def.options?.showHeader !== false,
      showLabels: def.options?.showLabels !== false,
      colorStyle: def.options?.colorStyle === 'outline' ? 'outline' : 'fill',
      colorTint: Number.isFinite(Number(def.options?.colorTint)) ? Math.max(0, Math.min(100, Number(def.options?.colorTint))) : 0,
      compact: def.options?.compact === true,
      xLabel: 'Weekday',
      yLabel: 'Hours (h)',
      chartData,
      groupedData,
      legendItems,
    }
  },
}
