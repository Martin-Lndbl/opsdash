<template>
  <div class="card chart-widget" :style="cardStyle">
    <div v-if="showHeader" class="chart-widget__header">
      <div class="chart-widget__title">{{ titleText }}</div>
      <div v-if="subtitle" class="chart-widget__subtitle">{{ subtitle }}</div>
    </div>
    <PieChart
      v-if="chartData"
      :data="chartData"
      :colors-by-id="colorsById"
      :colors-by-name="colorsByName"
      :show-labels="showLabels"
      :color-style="colorStyle"
      :color-tint="colorTint"
      :highlight-id="hoveredId"
    />
    <div v-else class="chart-widget__empty">No data</div>
    <ul v-if="showLegend && legendItems.length" class="chart-widget__legend">
      <li
        v-for="item in legendItems"
        :key="item.id"
        :class="{ hovered: hoveredId === item.id }"
        @mouseenter="hoveredId = item.id"
        @mouseleave="hoveredId = null"
        @focus="hoveredId = item.id"
        @blur="hoveredId = null"
        tabindex="0"
      >
        <span class="dot" :style="{ background: item.color }"></span>
        <span>{{ item.label }}</span>
        <span class="val">{{ item.value }}</span>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import PieChart from '../../charts/PieChart.vue'

type PieData = { ids: string[]; labels: string[]; data: number[]; colors?: string[] }

const props = defineProps<{
  title?: string
  subtitle?: string
  cardBg?: string | null
  showHeader?: boolean
  compact?: boolean
  showLegend?: boolean
  showLabels?: boolean
  colorStyle?: 'fill' | 'outline'
  colorTint?: number
  chartData?: PieData | null
  colorsById?: Record<string, string>
  colorsByName?: Record<string, string>
}>()

const showHeader = computed(() => props.showHeader !== false)
const titleText = computed(() => props.title || 'Pie chart')
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const colorsById = computed(() => props.colorsById || {})
const colorsByName = computed(() => props.colorsByName || {})
const hoveredId = ref<string | null>(null)

const legendItems = computed(() => {
  const data = props.chartData
  if (!data || !Array.isArray(data.data)) return []
  const total = data.data.reduce((sum, val) => sum + Math.max(0, Number(val) || 0), 0) || 1
  return data.data
    .map((val, idx) => {
      const raw = Math.max(0, Number(val) || 0)
      const label = String(data.labels?.[idx] ?? '')
      const id = String(data.ids?.[idx] ?? label ?? idx)
      const color = data.colors?.[idx] || colorsById.value[id] || colorsByName.value[label] || '#60a5fa'
      const pct = (raw / total) * 100
      const pctRounded = Math.round(pct)
      return { id, label, color, raw, pctRounded, value: `${raw.toFixed(1)}h · ${pctRounded}%` }
    })
    .filter((entry) => entry.pctRounded > 0)
    .sort((a, b) => b.raw - a.raw)
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
  transition: color 0.15s ease, background 0.15s ease;
  cursor: pointer;
}
.chart-widget__legend li.hovered {
  color: var(--brand);
  background: color-mix(in oklab, var(--brand), transparent 90%);
}
.chart-widget__legend .dot {
  width: calc(10px * var(--widget-space, 1));
  height: calc(10px * var(--widget-space, 1));
  border-radius: 50%;
}
.chart-widget__legend .val {
  margin-left: auto;
  color: var(--muted);
}
</style>
