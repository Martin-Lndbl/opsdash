<template>
  <div class="card table-widget" :style="cardStyle">
    <div v-if="showHeader" class="table-widget__header">
      <div class="table-widget__title">{{ titleText }}</div>
      <div v-if="subtitle" class="table-widget__subtitle">{{ subtitle }}</div>
    </div>
    <div v-if="mode === 'single_goal'" class="table-widget__context">
      <span class="table-widget__badge">Single Goal</span>
      <span v-if="totalTarget > 0" class="table-widget__context-text">Shared total target: {{ n2(totalTarget) }} h</span>
      <span v-else class="table-widget__context-text">Shared total goal without per-calendar targets</span>
    </div>
    <div v-else-if="mode === 'category_and_calendar_goals'" class="table-widget__context">
      <span class="table-widget__badge">Category + Calendar Goals</span>
      <span class="table-widget__context-text">Grouped by category assignment with per-calendar targets.</span>
    </div>
    <ByCalendarTable
      v-if="rows && rows.length"
      :rows="rows"
      :n2="n2"
      :targets="targets"
      :groups="groups"
      :today-hours="todayHours"
    />
    <div v-else class="table-widget__empty">No data</div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import ByCalendarTable from '../../tables/ByCalendarTable.vue'

const props = defineProps<{
  title?: string
  subtitle?: string
  cardBg?: string | null
  showHeader?: boolean
  rows?: any[]
  targets?: Record<string, number>
  groups?: any[]
  todayHours?: Record<string, number>
  mode?: 'single_goal' | 'calendar_goals' | 'category_and_calendar_goals'
  totalTarget?: number
}>()

const showHeader = computed(() => props.showHeader !== false)
const titleText = computed(() => props.title || 'By calendar')
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const n2 = (v: any) => Number(v ?? 0).toFixed(2)
</script>

<style scoped>
.table-widget {
  display: flex;
  flex-direction: column;
  gap: var(--widget-gap, 8px);
}
.table-widget__header {
  display: flex;
  flex-direction: column;
  gap: calc(2px * var(--widget-space, 1));
}
.table-widget__title {
  font-weight: 600;
  font-size: var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
}
.table-widget__subtitle {
  font-size: calc(12px * var(--widget-scale, 1));
  color: var(--muted);
}
.table-widget__context {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.table-widget__badge {
  display: inline-flex;
  align-items: center;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
  background: color-mix(in srgb, var(--brand) 14%, var(--card) 86%);
  color: var(--brand);
}
.table-widget__context-text {
  font-size: 12px;
  color: var(--muted);
}
.table-widget__empty {
  padding: calc(12px * var(--widget-space, 1));
  border: 1px dashed var(--line);
  border-radius: calc(10px * var(--widget-space, 1));
  color: var(--muted);
  text-align: center;
}
</style>
