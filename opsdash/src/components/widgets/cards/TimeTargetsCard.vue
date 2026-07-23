<template>
  <div class="card targets-card" :class="{ 'targets-card--endless': neverFinishedMode }" :style="cardStyle">
    <div v-if="neverFinishedMode" class="targets-fireframe" aria-hidden="true"></div>
    <div class="targets-header" v-if="showHeader">
      <div class="targets-header__title">
        <strong>{{ title || 'Targets' }}</strong>
      </div>
      <span class="hint" v-if="total.targetHours > 0">{{ totalDisplay.percentText }}%</span>
    </div>
    <div v-if="neverFinishedMode" class="targets-hustle">
      <div class="targets-hustle__badge">
        <div class="targets-hustle__orb" aria-hidden="true">
          <div
            v-for="(scene, index) in hustleScenes"
            :key="scene.id"
            class="targets-hustle__scene"
            :class="{ 'is-active': index === activeSceneIndex }"
          >
            <component :is="scene.icon" class="targets-hustle__icon" aria-hidden="true" />
          </div>
        </div>
      </div>
    </div>
    <div class="targets-main">
      <div class="line total-line">
        <span class="value"><strong>{{ formatHours(total.actualHours) }}</strong> / {{ formatHours(total.targetHours) }} h</span>
        <span v-if="showDelta && config.ui.showTotalDelta && total.targetHours > 0" :class="['delta', total.deltaHours >= 0 ? 'pos' : 'neg']">
          Δ {{ formatSigned(total.deltaHours) }}h
        </span>
      </div>
      <div class="line" v-if="total.plannedHours > 0">
        Planned {{ formatHours(total.plannedHours) }} h upcoming
      </div>
      <div class="line" v-if="config.ui.showNeedPerDay && total.targetHours > 0">
        Days left {{ total.daysLeft }} • Need {{ formatHours(total.needPerDay) }} h/day
      </div>
      <div class="line" v-if="showPace && total.targetHours > 0">
        Pace: {{ totalDisplay.percentText }}% vs {{ total.calendarPercent.toFixed(0) }}% →
        <span :class="['status-label', totalDisplay.statusClass]">{{ totalDisplay.paceLabel }}</span>
      </div>
      <div class="line forecast" v-if="showForecast">
        Forecast: {{ summary.forecast.text }}
        <span class="hint">
          Linear {{ formatHours(summary.forecast.linear) }}h · Momentum {{ formatHours(summary.forecast.momentum) }}h · Primary: {{ methodLabel(summary.forecast.primaryMethod) }}
        </span>
      </div>
    </div>
    <div class="targets-badges" v-if="config.ui.badges && total.targetHours > 0">
      <span class="badge" :class="totalDisplay.statusClass">{{ totalDisplay.badgeLabel }}</span>
    </div>
    <div class="targets-categories" v-if="config.ui.showCategoryBlocks && categoryItems.length">
      <div class="category" v-for="cat in categoryItems" :key="cat.id">
        <div class="cat-top">
          <div class="cat-label">
            <span class="dot" :style="{ background: cat.color || 'var(--brand)' }"></span>
            <span class="name">{{ cat.label }}</span>
            <span
              v-if="cat.isUnassigned"
              class="unassigned-hint"
              title="Hours from calendars not assigned to a category. Open Settings → Categories to assign them."
            >ⓘ</span>
          </div>
          <div class="cat-meta">
            <span class="percent">{{ cat.percentText }}%</span>
            <span v-if="config.ui.badges && cat.targetHours > 0" class="badge" :class="cat.statusClass">{{ cat.badgeLabel }}</span>
          </div>
        </div>
        <div class="cat-progress" :class="{ 'is-endless': cat.isEndless }">
          <div class="bar">
            <div class="bar-track">
              <div
                class="fill"
                :class="{ 'fill-endless': cat.isEndless }"
                :style="{ width: cat.progress + '%', '--fill-color': cat.color || 'var(--brand)' }"
              ></div>
              <div
                v-if="cat.todayWidth > 0"
                class="today-overlay"
                :style="{
                  width: cat.todayWidth + '%',
                  right: cat.todayRight + '%',
                  background: cat.todayColor,
              }"
              ></div>
            </div>
          </div>
        </div>
        <div class="cat-metrics">
          <strong>{{ formatHours(cat.actualHours) }}h</strong>
          <span class="hint">/ {{ formatHours(cat.targetHours) }}h</span>
          <span v-if="showDelta && cat.targetHours > 0" :class="['delta', cat.deltaHours >= 0 ? 'pos' : 'neg']">
            Δ {{ formatSigned(cat.deltaHours) }}h
          </span>
          <span v-if="cat.todayHours > 0" class="hint today-inline">
            {{ cat.todayText }}
          </span>
          <span v-if="cat.plannedHours > 0" class="hint planned-inline">
            Planned {{ formatHours(cat.plannedHours) }}h
          </span>
          <span v-if="config.ui.showNeedPerDay && cat.targetHours > 0" class="hint">
            Need {{ formatHours(cat.needPerDay) }}h/day · {{ cat.daysLeft }} days left
          </span>
        </div>
        <div class="cat-footer" v-if="cat.calendarCount > 0">
          <span class="hint">{{ cat.calendarCount }} {{ cat.calendarLabel }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { TargetsConfig, TargetsSummary, TargetsProgress } from '../../../services/targets'
import {
  HustleDeadliftIcon,
  HustleRideIcon,
  HustleRunIcon,
  HustleSwimIcon,
} from './hustleIcons'

type CategoryGroup = {
  id: string
  label: string
  summary: TargetsProgress
  color?: string
  rows?: any[]
  todayHours?: number
}

const props = withDefaults(defineProps<{
  summary: TargetsSummary
  config: TargetsConfig
  groups?: CategoryGroup[]
  showDelta?: boolean
  showPace?: boolean
  showForecast?: boolean
  neverFinishedMode?: boolean
  showHeader?: boolean
  title?: string
  cardBg?: string | null
}>(), {
  showDelta: true,
  showPace: true,
  showForecast: true,
  neverFinishedMode: false,
  title: 'Targets',
  cardBg: null,
})

const total = computed<TargetsProgress>(() => props.summary?.total ?? fallbackProgress('total', 'Total'))
const neverFinishedMode = computed(() => props.neverFinishedMode === true)
const showHeader = computed(() => props.showHeader !== false)
const sceneTick = ref(0)
const displaySeed = ref(0.42)
let sceneTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  displaySeed.value = ((Date.now() % 997) + 1) / 998
  sceneTimer = setInterval(() => {
    sceneTick.value = (sceneTick.value + 1) % 24
  }, 780)
})

onBeforeUnmount(() => {
  if (sceneTimer) clearInterval(sceneTimer)
})

const hustleScenes = [
  { id: 'run', label: 'Run', icon: HustleRunIcon },
  { id: 'swim', label: 'Swim', icon: HustleSwimIcon },
  { id: 'ride', label: 'Ride', icon: HustleRideIcon },
  { id: 'lift', label: 'Lifting', icon: HustleDeadliftIcon },
] as const
const activeSceneIndex = computed(() => sceneTick.value % hustleScenes.length)
const categoryGroups = computed<CategoryGroup[]>(() => {
  if (Array.isArray(props.groups)) {
    return props.groups.map(group => ({
      id: group.id,
      label: group.label,
      summary: group.summary ?? fallbackProgress(group.id, group.label),
      color: group.color,
      rows: group.rows,
      todayHours: group.todayHours,
    }))
  }
  const cats = props.summary?.categories ?? []
  return cats.map(cat => ({
    id: cat.id,
    label: cat.label,
    summary: cat,
    color: undefined,
    rows: [],
  }))
})

const cardStyle = computed(() => ({
  background: props.cardBg || undefined,
}))

const totalDisplay = computed(() => buildDisplayProgress(total.value, 'pace'))

const categoryItems = computed(() => categoryGroups.value.map(group => {
  const summary = group.summary ?? fallbackProgress(group.id, group.label)
  const todayHours = Number((group as any).todayHours ?? 0)
  const targetHours = summary.targetHours
  const totalOver = Math.max(0, summary.actualHours - targetHours)
  const overToday = Math.min(totalOver, todayHours)
  const hasTodayHours = todayHours > 0
  const todayPct = targetHours > 0 ? (todayHours / targetHours) * 100 : 0
  const display = buildDisplayProgress(summary, 'badge')
  const progressPct = display.progress
  return {
    id: group.id,
    label: group.label || summary.label,
    actualHours: summary.actualHours,
    plannedHours: Number((summary as any).plannedHours ?? 0),
    targetHours,
    percent: display.percent,
    percentText: display.percentText,
    deltaHours: summary.deltaHours,
    needPerDay: summary.needPerDay,
    daysLeft: summary.daysLeft,
    calendarPercent: summary.calendarPercent,
    gap: summary.gap,
    status: summary.status,
    statusLabel: display.badgeLabel,
    badgeLabel: display.badgeLabel,
    statusClass: display.statusClass,
    color: group.color,
    isUnassigned: (group as any).isUnassigned === true,
    calendarCount: Array.isArray(group.rows) ? group.rows.length : 0,
    progress: progressPct,
    isEndless: display.isEndless,
    todayHours,
    overToday,
    todayWidth: hasTodayHours && targetHours > 0 ? clamp(todayPct, 2, 200) : 0,
    todayRight: Math.max(0, 100 - progressPct),
    todayText: overToday > 0
      ? `Today ${formatHours(todayHours)} (+${formatHours(overToday)})`
      : `Today ${formatHours(todayHours)}`,
    todayColor: group.color ? colorMix(group.color, 0.65) : 'var(--brand)',
    calendarLabel: Array.isArray(group.rows) && group.rows.length === 1 ? 'calendar' : 'calendars',
  }
}).filter(item => item.targetHours > 0 || item.actualHours > 0 || item.calendarCount > 0))

function formatHours(value: number): string {
  if (!isFinite(value)) return '0'
  const rounded = Math.round(value * 10) / 10
  if (Math.abs(rounded - Math.round(rounded)) < 0.05) return String(Math.round(rounded))
  return rounded.toFixed(1)
}

function buildDisplayProgress(summary: TargetsProgress, mode: 'badge' | 'pace') {
  const rawPercent = clamp(summary.percent, 0, 999)
  const isEndless = neverFinishedMode.value && summary.targetHours > 0 && rawPercent >= 80
  if (!isEndless) {
    return {
      percent: rawPercent,
      percentText: rawPercent.toFixed(0),
      progress: clamp(rawPercent, 0, 999),
      badgeLabel: summary.statusLabel,
      paceLabel: summary.statusLabel,
      statusClass: statusClass(summary.status),
      isEndless: false,
    }
  }

  const intensity = Math.min(1, Math.max(0, (rawPercent - 80) / 20))
  const floor = 80.5 + intensity * 7
  const ceil = 90.5 + intensity * 8
  const displayPercent = round1(Math.min(98.8, floor + displaySeed.value * (ceil - floor)))

  return {
    percent: displayPercent,
    percentText: displayPercent.toFixed(0),
    progress: clamp(displayPercent, 80, 99),
    badgeLabel: 'Never Finished',
    paceLabel: mode === 'pace' ? 'Stay Hard' : 'Never Finished',
    statusClass: 'status-endless',
    isEndless: true,
  }
}

function formatSigned(value: number): string {
  if (!isFinite(value) || Math.abs(value) < 0.005) return '0.0'
  const sign = value >= 0 ? '+' : '−'
  return sign + formatHours(Math.abs(value))
}

function statusClass(status: TargetsProgress['status']): string {
  switch (status) {
    case 'on_track': return 'status-on'
    case 'at_risk': return 'status-risk'
    case 'behind': return 'status-behind'
    case 'done': return 'status-done'
    default: return 'status-none'
  }
}

function fallbackProgress(id: string, label: string): TargetsProgress {
  return {
    id,
    label,
    actualHours: 0,
    plannedHours: 0,
    targetHours: 0,
    percent: 0,
    deltaHours: 0,
    remainingHours: 0,
    needPerDay: 0,
    daysLeft: 0,
    calendarPercent: 0,
    gap: 0,
    status: 'none',
    statusLabel: '—',
    includeWeekend: true,
    paceMode: 'days_only',
  }
}

function clamp(value: number, min: number, max: number): number {
  if (!isFinite(value)) return min
  if (value < min) return min
  if (value > max) return max
  return value
}

function round1(value: number): number {
  return Math.round(value * 10) / 10
}

function methodLabel(method: 'linear' | 'momentum'): string {
  return method === 'momentum' ? 'Momentum' : 'Linear'
}

function colorMix(hex: string, factor = 0.5): string {
  const m = /^#?([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i.exec(hex || '')
  if (!m) return hex
  const r = parseInt(m[1], 16)
  const g = parseInt(m[2], 16)
  const b = parseInt(m[3], 16)
  const mix = Math.max(0, Math.min(1, factor))
  return `rgb(${Math.round(r + (255 - r) * mix)},${Math.round(g + (255 - g) * mix)},${Math.round(
    b + (255 - b) * mix,
  )})`
}
</script>

<style scoped>
.targets-card{ display:flex; flex-direction:column; gap:var(--widget-gap, 8px); font-size:var(--widget-font, 14px) }
.targets-card{ position:relative; overflow:hidden; isolation:isolate }
.targets-card > *{ position:relative; z-index:1 }
.targets-fireframe{ position:absolute; inset:2px; border-radius:inherit; pointer-events:none; z-index:0; overflow:hidden; opacity:.96 }
.targets-fireframe::before{ content:''; position:absolute; inset:0; border-radius:inherit; box-shadow:inset 0 0 0 1px color-mix(in srgb, #fb923c 58%, rgba(255,255,255,.14)), inset 0 0 18px color-mix(in srgb, #f97316 18%, transparent), inset 0 0 42px color-mix(in srgb, #dc2626 12%, transparent) }
.targets-fireframe::after{ content:''; position:absolute; inset:0; border-radius:inherit; background:
  radial-gradient(34% 14% at 12% 0%, color-mix(in srgb, #fdba74 58%, transparent), transparent 70%),
  radial-gradient(30% 12% at 34% 1%, color-mix(in srgb, #fb923c 52%, transparent), transparent 72%),
  radial-gradient(26% 11% at 58% 0%, color-mix(in srgb, #f97316 48%, transparent), transparent 70%),
  radial-gradient(24% 12% at 82% 1%, color-mix(in srgb, #fb7185 30%, transparent), transparent 74%),
  radial-gradient(12% 24% at 0% 18%, color-mix(in srgb, #f97316 38%, transparent), transparent 76%),
  radial-gradient(12% 24% at 100% 20%, color-mix(in srgb, #fb923c 34%, transparent), transparent 78%);
  filter:blur(9px) saturate(1.16);
  opacity:.82
}
.targets-header{ display:flex; justify-content:space-between; align-items:center }
.targets-header__title{ display:flex; align-items:center; gap:calc(6px * var(--widget-space, 1)) }
.targets-header strong{ font-size:var(--widget-title-size, calc(14px * var(--widget-scale, 1))) }
.targets-main{ display:flex; flex-direction:column; gap:calc(4px * var(--widget-space, 1)); font-size:calc(13px * var(--widget-scale, 1)) }
.targets-main .line{ display:flex; flex-wrap:wrap; gap:calc(6px * var(--widget-space, 1)) }
.targets-main .line.forecast{ flex-direction:column; gap:calc(2px * var(--widget-space, 1)) }
.targets-main .line .value{ display:flex; gap:calc(6px * var(--widget-space, 1)); align-items:center }
.targets-hustle{ position:absolute; top:calc(8px * var(--widget-space, 1)); right:calc(8px * var(--widget-space, 1)); z-index:2 }
.targets-hustle__badge{ display:grid; place-items:center; padding:calc(8px * var(--widget-space, 1)); border-radius:calc(20px * var(--widget-space, 1)); background:color-mix(in srgb, var(--bg) 90%, transparent); border:1px solid color-mix(in srgb, #f97316 32%, var(--line)); box-shadow:0 10px 26px color-mix(in srgb, #020617 28%, transparent), inset 0 0 0 1px color-mix(in srgb, #fb923c 10%, transparent) }
.targets-hustle__orb{ position:relative; width:200px; aspect-ratio:1; border-radius:50%; background:radial-gradient(circle at 35% 30%, color-mix(in srgb, #fb923c 24%, transparent), transparent 45%), linear-gradient(145deg, color-mix(in srgb, #f97316 18%, transparent), color-mix(in srgb, var(--brand) 12%, transparent)); border:1px solid color-mix(in srgb, #f97316 34%, var(--line)); box-shadow:inset 0 0 0 1px color-mix(in srgb, #fb923c 12%, transparent), 0 0 24px color-mix(in srgb, #f97316 10%, transparent); overflow:hidden }
.targets-hustle__orb::after{ content:''; position:absolute; inset:12%; border-radius:50%; background:radial-gradient(circle, color-mix(in srgb, #fb923c 18%, transparent) 0%, transparent 72%); opacity:.45; filter:blur(4px); pointer-events:none; transition:opacity .34s ease, transform .34s ease }
.targets-hustle__scene{ position:absolute; inset:0; display:grid; place-items:center; opacity:0; transform:scale(.92); transition:opacity .34s ease, transform .34s ease, filter .34s ease; filter:saturate(.82) brightness(.88) }
.targets-hustle__scene::after{ content:''; position:absolute; inset:16%; border-radius:50%; background:radial-gradient(circle, color-mix(in srgb, #fb923c 24%, transparent) 0%, transparent 74%); opacity:0; transform:scale(.86); filter:blur(4px); transition:opacity .34s ease, transform .34s ease; pointer-events:none }
.targets-hustle__scene.is-active{ opacity:1; transform:scale(1); filter:saturate(1.08) brightness(1.06) }
.targets-hustle__scene.is-active::after{ opacity:.82; transform:scale(1.04) }
.targets-hustle__icon{ width:100%; max-width:200px; height:auto; color:color-mix(in srgb, #fff 22%, #f97316 78%); transition:filter .34s ease, opacity .34s ease, transform .34s ease; opacity:.78; filter:drop-shadow(0 0 0 transparent) }
.targets-hustle__icon :deep(svg),
.targets-hustle__icon:deep(svg){ width:100%; height:auto; stroke-width:1.5 }
.targets-hustle__scene.is-active .targets-hustle__icon{ opacity:1; transform:scale(1.02); filter:drop-shadow(0 0 8px color-mix(in srgb, #fb923c 45%, transparent)) drop-shadow(0 0 14px color-mix(in srgb, #f97316 20%, transparent)) }
.targets-badges{ display:flex; flex-wrap:wrap; gap:calc(6px * var(--widget-space, 1)) }
.targets-categories{ display:flex; flex-direction:column; gap:calc(10px * var(--widget-space, 1)); padding-top:calc(6px * var(--widget-space, 1)); border-top:1px solid var(--line) }
.category{ display:flex; flex-direction:column; gap:calc(6px * var(--widget-space, 1)); font-size:calc(12px * var(--widget-scale, 1)); padding:calc(4px * var(--widget-space, 1)) 0 }
.category .cat-top{ display:flex; align-items:center; justify-content:space-between; gap:calc(8px * var(--widget-space, 1)) }
.category .cat-label{ display:flex; align-items:center; gap:calc(6px * var(--widget-space, 1)); font-weight:600; color:var(--fg) }
.category .cat-label .dot{ width:calc(10px * var(--widget-space, 1)); height:calc(10px * var(--widget-space, 1)); border-radius:50%; background:var(--brand); box-shadow:0 0 0 1px color-mix(in srgb, var(--fg) 10%, transparent) }
.category .cat-label .name{ color:var(--fg) }
.unassigned-hint{ font-size:calc(11px * var(--widget-scale,1)); color:var(--muted); cursor:default; line-height:1; opacity:.7 }
.category .cat-meta{ display:flex; align-items:center; gap:calc(6px * var(--widget-space, 1)); font-weight:600; color:var(--muted) }
.category .cat-meta .percent{ font-variant-numeric:tabular-nums; color:var(--fg) }
.cat-progress .bar{ position:relative; width:100%; overflow:visible }
.cat-progress .bar .bar-track{ position:relative; height:calc(16px * var(--widget-space, 1)); border-radius:999px; background:color-mix(in srgb, var(--muted) 20%, transparent); overflow:hidden }
.cat-progress .bar .fill{ height:100%; border-radius:999px; transition:width .2s ease; max-width:100%; background:linear-gradient(180deg, color-mix(in oklab, var(--fill-color, var(--brand)) 82%, white 18%), var(--fill-color, var(--brand))) }
.cat-progress .bar .fill.fill-endless{ transition:none; background-image:linear-gradient(90deg, color-mix(in srgb, var(--fill-color, #f97316) 70%, #f97316), color-mix(in srgb, #fb923c 38%, transparent), color-mix(in srgb, var(--fill-color, #f97316) 78%, #f97316)) }
.cat-progress .bar .today-overlay{ position:absolute; top:0; height:100%; border-radius:999px; opacity:0.45; border:0; pointer-events:none }
.category .cat-metrics{ display:flex; flex-wrap:wrap; gap:calc(6px * var(--widget-space, 1)); align-items:center; color:var(--fg) }
.cat-metrics .today-inline{ font-weight:600 }
.cat-footer{ display:flex; justify-content:space-between; align-items:center }
.hint{ color:var(--muted); font-size:calc(12px * var(--widget-scale, 1)) }
.badge{ display:inline-flex; align-items:center; gap:calc(4px * var(--widget-space, 1)); padding:calc(2px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1)); border-radius:999px; font-size:calc(11px * var(--widget-scale, 1)); font-weight:600; text-transform:uppercase; letter-spacing:.05em }
.status-label{ font-weight:600 }
.status-on{ background:color-mix(in srgb, var(--brand) 20%, white); color:var(--brand) }
.status-risk{ background:color-mix(in srgb, #f97316 20%, white); color:#f97316 }
.status-behind{ background:color-mix(in srgb, #ef4444 20%, white); color:#ef4444 }
.status-done{ background:color-mix(in srgb, var(--pos) 25%, white); color:var(--pos) }
.status-endless{ background:color-mix(in srgb, #f97316 18%, white); color:#ea580c }
.status-none{ background:color-mix(in srgb, var(--muted) 12%, white); color:var(--muted) }
.delta{ font-weight:600 }
.delta.pos{ color:var(--pos) }
.delta.neg{ color:var(--neg) }
.cat-metrics .hint{ font-size:calc(11px * var(--widget-scale, 1)) }
.cat-footer .hint{ font-size:calc(11px * var(--widget-scale, 1)) }
.cat-progress{ padding:calc(2px * var(--widget-space, 1)) 0 }
.cat-meta .badge{ font-size:calc(10px * var(--widget-scale, 1)) }
@media (max-width: 720px) {
  .targets-hustle{ top:calc(6px * var(--widget-space, 1)); right:calc(6px * var(--widget-space, 1)) }
  .targets-hustle__badge{ padding:calc(6px * var(--widget-space, 1)) }
  .targets-hustle__orb{ width:200px }
  .targets-hustle__icon{ max-width:200px }
}
</style>
