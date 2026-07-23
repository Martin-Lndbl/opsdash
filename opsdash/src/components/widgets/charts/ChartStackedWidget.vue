<template>
  <div class="card chart-widget" :style="cardStyle">
    <div v-if="showHeader" class="chart-widget__header">
      <div class="chart-widget__title">{{ titleText }}</div>
      <div v-if="subtitle" class="chart-widget__subtitle">{{ subtitle }}</div>
    </div>
    <StackedBars
      v-if="stacked"
      :stacked="stacked"
      :colors-by-id="colorsById"
      :show-labels="showLabels"
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
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import StackedBars from '../../charts/StackedBars.vue'

const props = defineProps<{
  title?: string
  subtitle?: string
  cardBg?: string | null
  showHeader?: boolean
  compact?: boolean
  showLegend?: boolean
  showLabels?: boolean
  stacked?: { labels?: string[]; series?: Array<{ id: string; name?: string; label?: string; color?: string; data?: number[] }> } | null
  colorsById?: Record<string, string>
}>()

const showHeader = computed(() => props.showHeader !== false)
const titleText = computed(() => props.title || 'Stacked bars')
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const colorsById = computed(() => props.colorsById || {})
const hoveredId = ref<string | null>(null)

const legendItems = computed(() => {
  const series = props.stacked?.series || []
  const totals = series.map((entry: any) =>
    (Array.isArray(entry?.data) ? entry.data : []).reduce((sum: number, value: any) => {
      const num = Number(value)
      return sum + (Number.isFinite(num) ? Math.max(0, num) : 0)
    }, 0),
  )
  const grandTotal = totals.reduce((sum, value) => sum + value, 0) || 1
  return series
    .map((entry: any, idx: number) => {
      const id = String(entry?.id ?? '')
      const label = String(entry?.name ?? entry?.label ?? id)
      const color = entry?.color || colorsById.value[id] || '#60a5fa'
      const total = totals[idx] || 0
      const pctRounded = Math.round((total / grandTotal) * 100)
      return { id, label, color, total, pctRounded }
    })
    .filter((entry) => entry.pctRounded > 0)
    .sort((a, b) => b.total - a.total)
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
</style>
