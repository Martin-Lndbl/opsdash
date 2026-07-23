<template>
  <div class="card calendar-stats" :style="cardStyle">
    <div v-if="showHeader" class="calendar-stats__header">
      <div>
        <div class="calendar-stats__title">{{ titleText }}</div>
        <div class="calendar-stats__subtitle">Showing {{ rangeText }}</div>
      </div>
      <div class="calendar-stats__scope">Selected calendars</div>
    </div>
    <div v-if="!rows.length" class="calendar-stats__state">No metrics configured</div>
    <ul v-else class="calendar-stats__rows">
      <li v-for="row in rows" :key="row.key" class="calendar-stats__row">
        <div>
          <div class="calendar-stats__label">{{ row.label }}</div>
          <div class="calendar-stats__hint">{{ row.hint }}</div>
        </div>
        <div class="calendar-stats__value">{{ row.value }}</div>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { buildCalendarStatsRows, type CalendarStatsMetric } from '../../../services/calendarStats'

const props = defineProps<{
  byCal?: any[]
  rangeLabel?: string
  metrics?: CalendarStatsMetric[]
  title?: string
  cardBg?: string | null
  showHeader?: boolean
}>()

const rows = computed(() => buildCalendarStatsRows(props.byCal || [], props.metrics, props.rangeLabel || ''))
const titleText = computed(() => props.title || 'Calendar stats')
const rangeText = computed(() => (props.rangeLabel || 'current range').toLowerCase())
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const showHeader = computed(() => props.showHeader !== false)
</script>

<style scoped>
.calendar-stats { display:flex; flex-direction:column; gap:calc(12px * var(--widget-space, 1)); }
.calendar-stats__header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
.calendar-stats__title { font-weight:700; font-size:var(--widget-title-size, calc(14px * var(--widget-scale, 1))); }
.calendar-stats__subtitle, .calendar-stats__scope, .calendar-stats__hint, .calendar-stats__state { color:var(--muted); font-size:calc(13px * var(--widget-scale, 1)); }
.calendar-stats__scope { text-align:right; }
.calendar-stats__rows { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:calc(8px * var(--widget-space, 1)); }
.calendar-stats__row { display:grid; grid-template-columns:minmax(0, 1fr) auto; gap:12px; align-items:center; border:1px solid var(--line); border-radius:calc(10px * var(--widget-space, 1)); padding:calc(10px * var(--widget-space, 1)) calc(12px * var(--widget-space, 1)); background:var(--card); }
.calendar-stats__label { font-weight:600; font-size:calc(14px * var(--widget-scale, 1)); }
.calendar-stats__hint { margin-top:2px; }
.calendar-stats__value { font-weight:800; font-size:calc(22px * var(--widget-scale, 1)); line-height:1; white-space:nowrap; }
</style>
