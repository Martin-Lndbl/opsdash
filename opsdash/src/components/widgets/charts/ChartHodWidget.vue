<template>
  <div class="card chart-widget chart-widget--hod" :style="cardStyle">
    <div v-if="showHeader" class="chart-widget__header">
      <div class="chart-widget__title">{{ titleText }}</div>
      <div v-if="subtitle" class="chart-widget__subtitle">{{ subtitle }}</div>
      <div v-if="lookbackLabel" class="chart-widget__meta">{{ lookbackLabel }}</div>
    </div>
    <HeatmapLookback
      v-if="lookbackEntries.length"
      :entries="lookbackEntries"
      :mode="lookbackMode"
      :show-legend="showLegend"
    />
    <HeatmapCanvas v-else-if="hodData" :hod="hodData" />
    <div v-else class="chart-widget__empty">No data</div>
    <div v-if="showHint && hodData" class="chart-widget__hint">24×7 Heatmap: hours by weekday and hour.</div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import HeatmapCanvas from '../../charts/HeatmapCanvas.vue'
import HeatmapLookback from '../../charts/HeatmapLookback.vue'

const props = defineProps<{
  title?: string
  subtitle?: string
  cardBg?: string | null
  showHeader?: boolean
  showHint?: boolean
  showLegend?: boolean
  lookbackMode?: string
  lookbackWeeks?: number | null
  rangeMode?: 'week' | 'month' | string
  hodData?: { dows: string[]; hours: string[] | number[]; matrix: number[][] } | null
  lookbackEntries?: Array<{ id: string; label: string; color: string; hod: { dows: string[]; hours: string[] | number[]; matrix: number[][] } }>
}>()

const showHeader = computed(() => props.showHeader !== false)
const titleText = computed(() => props.title || 'Hours of day')
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const lookbackEntries = computed(() => props.lookbackEntries || [])
const showLegend = computed(() => props.showLegend !== false)
const lookbackMode = computed(() => props.lookbackMode || 'stacked')
const lookbackLabel = computed(() => {
  const count = typeof props.lookbackWeeks === 'number' ? Math.max(0, Math.round(props.lookbackWeeks)) : 0
  if (!count) return ''
  const unit = (props.rangeMode || '').toString().toLowerCase() === 'month' ? 'month' : 'week'
  return `History · last ${count} ${count === 1 ? unit : `${unit}s`}`
})
</script>

<style scoped>
.chart-widget {
  display: flex;
  flex-direction: column;
  gap: var(--widget-gap, 8px);
}
.chart-widget__header {
  display: flex;
  flex-direction: column;
  gap: calc(2px * var(--widget-space, 1));
}
.chart-widget__title {
  font-weight: 600;
  font-size: var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
}
.chart-widget__subtitle {
  font-size: calc(12px * var(--widget-scale, 1));
  color: var(--muted);
}
.chart-widget__empty {
  padding: calc(12px * var(--widget-space, 1));
  border: 1px dashed var(--line);
  border-radius: calc(10px * var(--widget-space, 1));
  color: var(--muted);
  text-align: center;
}
.chart-widget__hint {
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--muted);
}
.chart-widget__meta{
  font-size: calc(11px * var(--widget-scale, 1));
  color: var(--muted);
}
.chart-widget--hod {
  --heatmap-low: color-mix(in srgb, #d8d2c4 28%, var(--card, #ffffff));
  --heatmap-high: color-mix(in srgb, #4f6477 88%, var(--card, #ffffff));
}
</style>
