import { defineAsyncComponent } from 'vue'
import type { RegistryEntry } from '../types'
import { buildTitle } from '../helpers'
import { buildCategoryPie, buildChartFilterControls, filterPieByIds, resolveChartFilter } from './chartHelpers'

const ChartPieWidget = defineAsyncComponent(() =>
  import('../../../components/widgets/charts/ChartPieWidget.vue').then((m) => m.default),
)

const baseTitle = 'Pie Chart'

export const chartPieEntry: RegistryEntry = {
  component: ChartPieWidget,
  defaultLayout: { width: 'half', height: 'm', order: 75 },
  label: 'Pie Chart',
  category: 'Charts' as const,
  baseTitle,
  configurable: true,
  defaultOptions: {
    showLegend: true,
    showLabels: true,
    compact: false,
      colorStyle: 'fill',
    colorTint: 0,
  },
  dynamicControls: (options, ctx) => {
    return [
      ...buildChartFilterControls(options, ctx),
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
    const colorsById = ctx.colorsById || {}
    const colorsByName = ctx.colorsByName || {}
    const categoryColorMap = ctx.categoryColorMap || {}
    const data =
      mode === 'category'
        ? buildCategoryPie(ctx.calendarGroups || [], ids, categoryColorMap)
        : filterPieByIds(ctx.calendarChartData?.pie || null, ids)
    return {
      title: buildTitle(baseTitle, def.options?.titlePrefix),
      subtitle: mode === 'category' ? 'By category' : 'By calendar',
      cardBg: def.options?.cardBg,
      showHeader: def.options?.showHeader !== false,
      compact: def.options?.compact === true,
      showLegend: def.options?.showLegend !== false,
      showLabels: def.options?.showLabels !== false,
      colorStyle: def.options?.colorStyle === 'outline' ? 'outline' : 'fill',
      colorTint: Number.isFinite(Number(def.options?.colorTint)) ? Math.max(0, Math.min(100, Number(def.options?.colorTint))) : 0,
      chartData: data,
      colorsById,
      colorsByName,
    }
  },
}
