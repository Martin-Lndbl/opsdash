<template>
  <div class="card deck-stats-widget" :style="cardStyle">
    <div v-if="showHeader" class="deck-stats-widget__header">
      <div>
        <div class="deck-stats-widget__title">{{ titleText }}</div>
        <div class="deck-stats-widget__subtitle">Showing {{ rangeText }}</div>
      </div>
      <div v-if="selectionText" class="deck-stats-widget__scope">{{ selectionText }}</div>
    </div>

    <div v-if="loading" class="deck-stats-widget__state">
      Loading Deck stats…
    </div>
    <div v-else-if="error" class="deck-stats-widget__state deck-stats-widget__state--error">
      {{ error }}
    </div>
    <div v-else-if="!rows.length" class="deck-stats-widget__state">
      No metrics configured
    </div>
    <ul v-else class="deck-stats-widget__rows">
      <li v-for="row in rows" :key="row.key" class="deck-stats-widget__row">
        <div class="deck-stats-widget__meta">
          <div class="deck-stats-widget__label">{{ row.label }}</div>
          <div class="deck-stats-widget__hint">{{ row.hint }}</div>
        </div>
        <div class="deck-stats-widget__value">{{ row.value }}</div>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { DeckCardSummary } from '../../../services/deck'
import type { DeckMineMode } from '../../../services/reporting'
import {
  buildDeckStatsRows,
  type DeckStatsMetric,
  type DeckStatsScope,
} from '../../../services/deckWidgetMetrics'

const props = withDefaults(defineProps<{
  cards: DeckCardSummary[]
  rangeLabel: string
  from?: string
  to?: string
  uid?: string
  loading?: boolean
  error?: string | null
  boardIds?: Array<number | string>
  stackIds?: Array<number | string>
  tagIds?: string[]
  metrics?: DeckStatsMetric[]
  scope?: DeckStatsScope
  mineMode?: DeckMineMode
  includeArchived?: boolean
  includeCompleted?: boolean
  title?: string
  cardBg?: string | null
  showHeader?: boolean
  selectionText?: string
}>(), {
  includeArchived: true,
  includeCompleted: true,
  loading: false,
  showHeader: true,
})

const rows = computed(() =>
  buildDeckStatsRows(props.cards || [], {
    boardIds: props.boardIds,
    stackIds: props.stackIds,
    includeArchived: props.includeArchived !== false,
    includeCompleted: props.includeCompleted !== false,
    scope: props.scope || 'all',
    uid: props.uid,
    mineMode: props.mineMode || 'assignee',
    tagIds: props.tagIds,
    metrics: props.metrics,
    from: props.from,
    to: props.to,
    rangeLabel: props.rangeLabel,
  }),
)

const cardStyle = computed(() => {
  const bg = props.cardBg || undefined
  if (!bg) return {}
  return { background: bg, '--card': bg } as Record<string, string>
})
const titleText = computed(() => props.title || 'Deck stats')
const rangeText = computed(() => (props.rangeLabel || 'current range').toLowerCase())
const selectionText = computed(() => String(props.selectionText || '').trim())
const showHeader = computed(() => props.showHeader !== false)
</script>

<style scoped>
.deck-stats-widget {
  display: flex;
  flex-direction: column;
  gap: calc(12px * var(--widget-space, 1));
}

.deck-stats-widget__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.deck-stats-widget__title {
  font-weight: 700;
  font-size: var(--widget-title-size, calc(14px * var(--widget-scale, 1)));
}

.deck-stats-widget__subtitle,
.deck-stats-widget__scope,
.deck-stats-widget__hint,
.deck-stats-widget__state {
  color: var(--muted);
  font-size: calc(13px * var(--widget-scale, 1));
}

.deck-stats-widget__scope {
  text-align: right;
}

.deck-stats-widget__state--error {
  background: var(--color-error-hover);
  color: var(--color-error-text);
  border-radius: calc(8px * var(--widget-space, 1));
  padding: calc(8px * var(--widget-space, 1)) calc(12px * var(--widget-space, 1));
}

.deck-stats-widget__rows {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: calc(8px * var(--widget-space, 1));
}

.deck-stats-widget__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
  border: 1px solid var(--line);
  border-radius: calc(10px * var(--widget-space, 1));
  padding: calc(10px * var(--widget-space, 1)) calc(12px * var(--widget-space, 1));
  background: var(--card);
}

.deck-stats-widget__meta {
  min-width: 0;
}

.deck-stats-widget__label {
  font-weight: 600;
  font-size: calc(14px * var(--widget-scale, 1));
}

.deck-stats-widget__hint {
  margin-top: 2px;
}

.deck-stats-widget__value {
  font-weight: 800;
  font-size: calc(22px * var(--widget-scale, 1));
  line-height: 1;
}
</style>
