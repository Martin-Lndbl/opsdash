<template>
  <div class="card chart-widget" :style="cardStyle">
    <div v-if="showHeader" class="chart-widget__header">
      <div class="chart-widget__title">{{ titleText }}</div>
      <div v-if="subtitle" class="chart-widget__subtitle">{{ subtitle }}</div>
    </div>
    <SimpleBars
      v-if="chartData"
      :data="chartData"
      :show-labels="showLabels"
      :x-label="xLabel"
      :y-label="yLabel"
    />
    <div v-else class="chart-widget__empty">No data</div>
    <ul v-if="legendItems.length" class="chart-widget__legend">
      <li v-for="item in legendItems" :key="item.id">
        <span class="dot" :style="{ background: item.color }"></span>
        <span>{{ item.label }}</span>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import SimpleBars from '../../charts/SimpleBars.vue'

const props = defineProps<{
  title?: string
  subtitle?: string
  cardBg?: string | null
  showHeader?: boolean
  showLabels?: boolean
  xLabel?: string
  yLabel?: string
  chartData?: { labels?: string[]; data?: number[]; colors?: string[] } | null
  legendItems?: Array<{ id: string; label: string; color: string }>
}>()

const showHeader = computed(() => props.showHeader !== false)
const titleText = computed(() => props.title || 'Per-day totals')
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const legendItems = computed(() => props.legendItems || [])
const xLabel = computed(() => props.xLabel || '')
const yLabel = computed(() => props.yLabel || '')
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
.chart-widget__legend {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: calc(6px * var(--widget-space, 1));
  font-size: calc(12px * var(--widget-scale, 1));
}
.chart-widget__legend li {
  display: flex;
  align-items: center;
  gap: calc(6px * var(--widget-space, 1));
  border-radius: 999px;
  padding: calc(2px * var(--widget-space, 1)) calc(6px * var(--widget-space, 1));
}
.chart-widget__legend .dot {
  width: calc(10px * var(--widget-space, 1));
  height: calc(10px * var(--widget-space, 1));
  border-radius: 50%;
}
</style>
