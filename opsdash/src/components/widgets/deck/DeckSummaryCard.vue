<template>
  <div class="card deck-summary-card" :style="cardStyle">
    <div class="deck-summary-card__header" v-if="showHeader">
      <div>
        <div class="deck-summary-card__title">{{ titleText }}</div>
        <div class="deck-summary-card__subtitle">Showing {{ rangeLabel.toLowerCase() }} selection</div>
      </div>
      <div class="deck-summary-card__meta">
        <span v-if="ticker.autoScroll">Auto-scroll · {{ ticker.intervalSeconds }}s</span>
        <span v-else>Auto-scroll off</span>
      </div>
    </div>

    <div v-if="loading" class="deck-summary-card__loading">
      <NcLoadingIcon :size="18" />
      <span>Loading Deck cards…</span>
    </div>
    <div v-else-if="error" class="deck-summary-card__error">
      {{ error }}
    </div>
    <div v-else-if="!hasAnyData" class="deck-summary-card__empty">
      No Deck data
    </div>
    <ul v-else class="deck-summary-card__rows" @mouseenter="pause" @mouseleave="resume">
      <li
        v-for="bucket in buckets"
        :key="bucket.key"
        class="deck-summary-card__row"
        :class="{
          'deck-summary-card__row--active': bucket.key === activeFilter,
          'deck-summary-card__row--clickable': canFilter,
        }"
        role="button"
        tabindex="0"
        @click="handleFilter(bucket.key)"
        @keydown.enter.prevent="handleFilter(bucket.key)"
        @keydown.space.prevent="handleFilter(bucket.key)"
      >
        <div class="deck-summary-card__row-head">
          <span class="bucket-label">{{ bucket.label }}</span>
          <span class="bucket-count">{{ bucket.count }}</span>
        </div>
        <div class="deck-summary-card__row-body">
          <span
            v-if="showBoardBadges && bucket.board"
            class="bucket-board"
            :style="badgeStyle(bucket.board.color)"
          >
            {{ bucket.board.title }}
          </span>
          <div class="bucket-titles">
            <span v-if="bucket.titles.length === 0" class="bucket-title bucket-title--muted">No cards</span>
            <span
              v-else
              v-for="(title, idx) in visibleTitles(bucket)"
              :key="`${bucket.key}-${idx}-${title}`"
              class="bucket-title"
            >
              {{ title }}
            </span>
          </div>
        </div>
      </li>
    </ul>
  </div>
  </template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { NcLoadingIcon } from '@nextcloud/vue'
import type { DeckFilterMode } from '../../../services/reporting'

const props = defineProps<{
  buckets: Array<{
    key: DeckFilterMode
    label: string
    titles: string[]
    count: number
    board?: { title: string; color?: string }
  }>
  rangeLabel: string
  loading: boolean
  error?: string
  ticker: { autoScroll: boolean; intervalSeconds: number }
  showBoardBadges?: boolean
  activeFilter?: DeckFilterMode
  onFilter?: (key: DeckFilterMode) => void
  title?: string
  cardBg?: string | null
  showHeader?: boolean
}>()

const tick = ref(0)
const paused = ref(false)
let timer: ReturnType<typeof setInterval> | null = null

const hasAnyData = computed(() => props.buckets.some((bucket) => bucket.count > 0))
const titleText = computed(() => props.title || 'Deck summary')
const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const showHeader = computed(() => props.showHeader !== false)
const activeFilter = computed(() => props.activeFilter)
const canFilter = computed(() => typeof props.onFilter === 'function')

function visibleTitles(bucket: (typeof props.buckets)[number]) {
  if (!bucket.titles.length) return []
  const start = props.ticker.autoScroll ? tick.value % bucket.titles.length : 0
  const max = Math.min(4, bucket.titles.length)
  const out: string[] = []
  for (let i = 0; i < max; i += 1) {
    const idx = (start + i) % bucket.titles.length
    out.push(bucket.titles[idx])
  }
  return out
}

function stopTimer() {
  if (timer) {
    clearInterval(timer)
    timer = null
  }
}

function startTimer() {
  stopTimer()
  if (!props.ticker.autoScroll) return
  const intervalMs = Math.max(3000, Math.min(10000, (props.ticker.intervalSeconds || 5) * 1000))
  timer = setInterval(() => {
    if (!paused.value) {
      tick.value += 1
    }
  }, intervalMs)
}

function pause() {
  paused.value = true
}
function resume() {
  paused.value = false
}

function handleFilter(key: DeckFilterMode) {
  if (props.onFilter) {
    props.onFilter(key)
  }
}

function badgeStyle(color?: string) {
  const base = color || 'var(--brand)'
  return {
    borderColor: base,
    color: base,
    background: `color-mix(in srgb, ${base} 18%, transparent)`,
  }
}

watch(
  () => [props.ticker.autoScroll, props.ticker.intervalSeconds],
  () => {
    startTimer()
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  stopTimer()
})
</script>

<style scoped>
.deck-summary-card {
  display: flex;
  flex-direction: column;
  gap: calc(12px * var(--widget-space, 1));
  font-size: var(--widget-font, 14px);
}
.deck-summary-card__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.deck-summary-card__title {
  font-weight: 700;
  font-size: var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
}
.deck-summary-card__subtitle {
  color: var(--muted);
  font-size: calc(14px * var(--widget-scale, 1));
}
.deck-summary-card__meta {
  color: var(--muted);
  font-size: calc(14px * var(--widget-scale, 1));
}
.deck-summary-card__loading,
.deck-summary-card__error,
.deck-summary-card__empty {
  display: flex;
  align-items: center;
  gap: calc(8px * var(--widget-space, 1));
  color: var(--muted);
}
.deck-summary-card__error {
  background: var(--color-error-hover);
  color: var(--color-error-text);
  border-radius: calc(8px * var(--widget-space, 1));
  padding: calc(8px * var(--widget-space, 1)) calc(12px * var(--widget-space, 1));
}
.deck-summary-card__rows {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: calc(8px * var(--widget-space, 1));
}
.deck-summary-card__row {
  border: 1px solid var(--line);
  border-radius: calc(10px * var(--widget-space, 1));
  padding: calc(10px * var(--widget-space, 1)) calc(12px * var(--widget-space, 1));
  display: flex;
  flex-direction: column;
  gap: calc(6px * var(--widget-space, 1));
  background: var(--card);
}
.deck-summary-card__row--clickable {
  cursor: pointer;
}
.deck-summary-card__row--active {
  border-color: var(--brand);
  box-shadow: inset 0 0 0 1px var(--brand);
}
.deck-summary-card__row-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 600;
}
.bucket-label {
  font-size: calc(14px * var(--widget-scale, 1));
}
.bucket-count {
  font-size: calc(13px * var(--widget-scale, 1));
  border-radius: 999px;
  padding: calc(2px * var(--widget-space, 1)) calc(9px * var(--widget-space, 1));
  background: var(--soft);
}
.deck-summary-card__row-body {
  display: flex;
  flex-wrap: wrap;
  gap: calc(8px * var(--widget-space, 1));
  align-items: center;
}
.bucket-board {
  border: 2px solid var(--line);
  padding: calc(3px * var(--widget-space, 1)) calc(10px * var(--widget-space, 1));
  border-radius: 999px;
  font-size: calc(12px * var(--widget-scale, 1));
  text-transform: uppercase;
}
.bucket-titles {
  display: flex;
  flex-wrap: wrap;
  gap: calc(6px * var(--widget-space, 1));
}
.bucket-title {
  padding: calc(2px * var(--widget-space, 1)) calc(8px * var(--widget-space, 1));
  background: var(--soft);
  border-radius: 999px;
  font-size: calc(13px * var(--widget-scale, 1));
}
.bucket-title--muted {
  color: var(--muted);
}
</style>
