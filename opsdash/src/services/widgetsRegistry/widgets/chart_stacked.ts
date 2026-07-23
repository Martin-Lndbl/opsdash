import { defineAsyncComponent } from 'vue'
import type { RegistryEntry } from '../types'
import { buildTitle } from '../helpers'
import {
  aggregateStackedByCategory,
  buildStackedWithForecast,
  buildChartFilterControls,
  filterStackedByIds,
  resolveChartFilter,
  buildCategoryLabelMap,
} from './chartHelpers'

const ChartStackedWidget = defineAsyncComponent(() =>
  import('../../../components/widgets/charts/ChartStackedWidget.vue').then((m) => m.default),
)

const baseTitle = 'Stacked Chart'

export const chartStackedEntry: RegistryEntry = {
  component: ChartStackedWidget,
  defaultLayout: { width: 'full', height: 'l', order: 80 },
  label: 'Stacked Chart',
  category: 'Charts' as const,
  baseTitle,
  configurable: true,
  defaultOptions: {
    showLegend: true,
    showLabels: false,
    compact: false,
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
      { key: 'showLegend', label: 'Show legend', type: 'toggle' },
      { key: 'showLabels', label: 'Show labels', type: 'toggle' },
      { key: 'compact', label: 'Compact', type: 'toggle' },
    ]
  },
  buildProps: (def, ctx) => {
    const { mode, ids } = resolveChartFilter(def.options)
    const categoryColorMap = ctx.categoryColorMap || {}
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
    return {
      title: buildTitle(baseTitle, def.options?.titlePrefix),
      subtitle: mode === 'category' ? 'By category' : 'By calendar',
      cardBg: def.options?.cardBg,
      showHeader: def.options?.showHeader !== false,
      compact: def.options?.compact === true,
      showLegend: def.options?.showLegend !== false,
      showLabels: def.options?.showLabels === true,
      colorStyle: def.options?.colorStyle === 'outline' ? 'outline' : 'fill',
      colorTint: Number.isFinite(Number(def.options?.colorTint)) ? Math.max(0, Math.min(100, Number(def.options?.colorTint))) : 0,
      stacked,
      colorsById: ctx.colorsById || {},
    }
  },
}
