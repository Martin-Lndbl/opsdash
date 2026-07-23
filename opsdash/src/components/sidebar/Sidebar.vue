<template>
  <NcAppNavigation>
    <slot name="actions" />

    <div class="sb-inner">
      <!-- ── Range hero card ── -->
      <div class="rc hero">
        <div class="hero-topbar">
          <button
            class="hide-btn"
            type="button"
            @click="$emit('toggle-nav')"
            :aria-label="navToggleLabel"
            :title="navToggleLabel"
          >
            <svg viewBox="0 0 11 12" width="11" height="12" fill="none">
              <path d="M7 1 2 6l5 5M11 1 6 6l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>

          <!-- Week / Month segmented pill -->
          <div class="seg w2 hero-seg">
            <button type="button" :class="{ on: range === 'week' }" @click="$emit('update:range', 'week')">Week</button>
            <button type="button" :class="{ on: range === 'month' }" @click="$emit('update:range', 'month')">Month</button>
          </div>
        </div>

        <div class="ew">{{ rangeEyebrow }}</div>
        <div class="rc-big">{{ rangeHeadline }}</div>

        <!-- Prev / date pill / Next -->
        <div class="navrow arw-layout">
          <button
            class="arw"
            type="button"
            :disabled="isLoading"
            :aria-label="'Previous ' + (range === 'month' ? 'month' : 'week')"
            @click="$emit('update:offset', offset - 1)"
          >
            <svg viewBox="0 0 7 12" width="7" height="12" fill="none">
              <path d="M6 1 1 6l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
          <div class="date-pill">{{ from }} – {{ to }}</div>
          <button
            class="arw"
            type="button"
            :disabled="isLoading"
            :aria-label="'Next ' + (range === 'month' ? 'month' : 'week')"
            @click="$emit('update:offset', offset + 1)"
          >
            <svg viewBox="0 0 7 12" width="7" height="12" fill="none">
              <path d="M1 1l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
        </div>

        <!-- Sync row -->
        <div class="sync-row">
          <div class="sync-left">
            <div class="sync-dot" :class="{ syncing: isLoading }" />
            <span class="sync-txt">{{ syncLabel }}</span>
          </div>
          <button
            class="btn-ref"
            type="button"
            :disabled="isLoading"
            @click="$emit('load')"
          >Refresh</button>
        </div>
      </div>

      <!-- ── Quick settings card ── -->
      <div class="qs">
        <div class="qs-hd">
          <div class="ew">Quick settings</div>
        </div>

        <!-- Theme -->
        <div class="qs-row">
          <span class="qs-label">Theme</span>
          <div class="seg w3">
            <button
              v-for="opt in (['auto', 'light', 'dark'] as const)"
              :key="opt"
              type="button"
              :class="{ on: themePreference === opt }"
              @click="$emit('update:theme-preference', opt)"
            >{{ opt[0].toUpperCase() + opt.slice(1) }}</button>
          </div>
        </div>

        <!-- Scope -->
        <div class="qs-row">
          <span class="qs-label">Scope</span>
          <div class="seg w2">
            <button type="button" :class="{ on: preferredScope === 'calendar' }" @click="preferredScope = 'calendar'">Calendar</button>
            <button type="button" :class="{ on: preferredScope === 'category' }" @click="preferredScope = 'category'">Category</button>
          </div>
        </div>

        <!-- Background -->
        <div class="qs-row">
          <span class="qs-label">Background</span>
          <div class="qs-color">
            <label class="qs-swatch" :title="globalAppBg || defaultAppBg + ' (theme default)'">
              <span class="qs-swatch-dot" :class="{ 'qs-swatch-dot--none': !globalAppBg }" :style="{ background: globalAppBg || defaultAppBg }" />
              <input
                type="color"
                :value="globalAppBg ?? defaultAppBg"
                @input="onColorInput"
                @change="onColorInput"
              />
            </label>
            <button
              class="qs-clear"
              type="button"
              :disabled="!globalAppBg"
              @click="globalAppBg = null"
              title="Follow theme"
            >Reset</button>
          </div>
        </div>
      </div>

      <!-- ── Goals editor (collapsible) ── -->
      <div v-if="targets" class="ge" :class="{ 'ge--collapsed': !goalsExpanded }">
        <button
          class="sc-hd sc-toggle"
          type="button"
          :aria-expanded="goalsExpanded"
          @click="goalsExpanded = !goalsExpanded"
        >
          <div>
            <div class="ew">Weekly goals</div>
            <div class="sc-title">
              <span>Goals</span>
              <span class="sc-progress">{{ formatHours(targets.totalHours ?? 0) }} h/wk</span>
            </div>
          </div>
          <span class="sc-caret" :class="{ 'sc-caret--open': goalsExpanded }" aria-hidden="true">
            <svg viewBox="0 0 12 7" width="12" height="7" fill="none">
              <path d="M1 1l5 5 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
        </button>
        <div v-if="goalsExpanded" class="sc-body">
          <label class="ge-row">
            <span class="ge-label">Total</span>
            <div class="ge-input">
              <input
                type="number"
                min="0"
                max="1000"
                step="0.5"
                :value="targets.totalHours ?? 0"
                @input="(e) => { const v = readHours(e); v !== null && emit('update-total-hours', v) }"
              />
              <span class="ge-unit">h/wk</span>
            </div>
          </label>

          <div class="ge-cats">
            <div
              v-for="cat in targets.categories || []"
              :key="cat.id"
              class="ge-catcard"
              :class="{ 'ge-catcard--open': openCategoryId === cat.id }"
            >
              <div class="ge-catcard__head">
                <input
                  class="ge-catlabel"
                  type="text"
                  :value="labelDraft(cat.id, cat.label)"
                  placeholder="Category"
                  aria-label="Category name"
                  @input="(e) => onCategoryLabelInput(cat.id, (e.target as HTMLInputElement).value)"
                  @change="(e) => onCategoryLabelCommit(cat.id, (e.target as HTMLInputElement).value)"
                  @blur="(e) => onCategoryLabelCommit(cat.id, (e.target as HTMLInputElement).value)"
                  @keydown.enter.prevent="($event.target as HTMLInputElement).blur()"
                />
                <div class="ge-input ge-input--sm">
                  <input
                    type="number"
                    min="0"
                    max="1000"
                    step="0.5"
                    :value="cat.targetHours ?? 0"
                    @input="(e) => { const v = readHours(e); v !== null && emit('update-category-target', { id: cat.id, value: v }) }"
                  />
                  <span class="ge-unit">h</span>
                </div>
                <ColorPickerPopover
                  class="ge-color"
                  :model-value="cat.color || '#2563EB'"
                  @update:model-value="(c) => patchCategory(cat.id, { color: c || null })"
                >
                  <span class="ge-color__spacer" aria-hidden="true" />
                </ColorPickerPopover>
                <button
                  class="ge-catcard__toggle"
                  type="button"
                  :aria-expanded="openCategoryId === cat.id"
                  :aria-label="openCategoryId === cat.id ? 'Collapse calendars' : 'Expand calendars'"
                  :title="calendarsForCategory(cat.id).length + ' calendar' + (calendarsForCategory(cat.id).length === 1 ? '' : 's')"
                  @click="toggleCategory(cat.id)"
                >
                  <span class="ge-catcard__count">{{ calendarsForCategory(cat.id).length }}</span>
                  <svg
                    class="ge-caret"
                    :class="{ 'ge-caret--open': openCategoryId === cat.id }"
                    viewBox="0 0 12 7" width="10" height="6" fill="none"
                  >
                    <path d="M1 1l5 5 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </button>
                <button
                  class="ge-catcard__remove"
                  type="button"
                  :disabled="(targets.categories || []).length <= 1"
                  aria-label="Remove category"
                  title="Remove category"
                  @click="removeCategory(cat.id)"
                >×</button>
              </div>

              <div v-if="openCategoryId === cat.id" class="ge-catcard__body">
                <div
                  v-for="cal in calendarsForCategory(cat.id)"
                  :key="cal.id"
                  class="ge-calrow"
                >
                  <span
                    class="ge-dot"
                    :style="{ background: cal.color || 'var(--brand, #2563eb)' }"
                    aria-hidden="true"
                  />
                  <span class="ge-cal-label" :title="cal.displayname">{{ cal.displayname }}</span>
                  <div class="ge-input ge-input--sm">
                    <input
                      type="number"
                      min="0"
                      max="1000"
                      step="0.25"
                      :value="calendarTarget(cal.id)"
                      @input="(e) => { const v = readHours(e); v !== null && emit('set-calendar-target', { id: cal.id, value: v }) }"
                    />
                    <span class="ge-unit">h</span>
                  </div>
                  <button
                    class="ge-catcard__remove"
                    type="button"
                    aria-label="Unassign calendar"
                    :title="'Unassign ' + cal.displayname"
                    @click="onAssignCalendar(cal.id, '')"
                  >×</button>
                </div>

                <label
                  v-if="assignableCalendars(cat.id).length"
                  class="ge-add-cal"
                >
                  <span>+ Add calendar</span>
                  <select
                    :value="''"
                    @change="onAddCalendarChange(cat.id, $event.target as HTMLSelectElement)"
                  >
                    <option value="">Pick calendar…</option>
                    <option
                      v-for="opt in assignableCalendars(cat.id)"
                      :key="cat.id + '-' + opt.id"
                      :value="opt.id"
                    >{{ opt.displayname }}</option>
                  </select>
                </label>
                <div v-else class="ge-hint">No unassigned calendars left.</div>
              </div>
            </div>
          </div>

          <button class="ge-add" type="button" @click="addCategory">
            + Add category
          </button>
        </div>
      </div>

      <!-- ── Setup card (collapsible) ── -->
      <div class="sc" :class="{ 'sc--collapsed': !setupExpanded }">
        <button
          class="sc-hd sc-toggle"
          type="button"
          :aria-expanded="setupExpanded"
          @click="setupExpanded = !setupExpanded"
        >
          <div>
            <div class="ew">Guided setup</div>
            <div class="sc-title">
              <span>Setup</span>
              <span class="sc-progress">{{ setupSummary }}</span>
            </div>
          </div>
          <span v-if="dashboardMode === 'pro'" class="badge">Pro</span>
          <span v-else-if="dashboardMode === 'standard'" class="badge badge--std">Std</span>
          <span class="sc-caret" :class="{ 'sc-caret--open': setupExpanded }" aria-hidden="true">
            <svg viewBox="0 0 12 7" width="12" height="7" fill="none">
              <path d="M1 1l5 5 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
        </button>

        <div v-if="setupExpanded" class="sc-body">
          <button class="wiz" type="button" @click="$emit('rerun-onboarding')">
            Open setup wizard
          </button>

          <ol class="steps">
            <li
              v-for="step in STEPS"
              :key="step.id"
              class="step"
              :class="guidedHintStatuses?.[step.id] ?? 'dim'"
              @click="$emit('rerun-onboarding', step.id)"
              role="button"
              :title="'Go to ' + step.label"
            >
              <span class="sn">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor">
                  <path :d="step.icon" />
                </svg>
              </span>
              <div class="sb-txt">
                <strong>{{ step.label }}</strong>
                <small>{{ guidedHints?.[step.id] || step.placeholder }}</small>
              </div>
            </li>
          </ol>
        </div>
      </div>

      <!-- ── Bottom dock ── -->
      <div class="dock">
        <button
          class="dk-btn"
          type="button"
          title="Keyboard shortcuts"
          aria-label="Keyboard shortcuts"
          @click="$emit('open-shortcuts', $event.currentTarget)"
        >
          <b>⌘</b><span>Keys</span>
        </button>
        <button
          class="dk-btn"
          type="button"
          :class="{ on: releaseNotesAvailable }"
          :disabled="!releaseNotesAvailable"
          title="What's new"
          aria-label="What's new"
          @click="$emit('open-release-notes')"
        >
          <b>✦</b><span>New</span>
        </button>
        <button
          class="dk-btn"
          type="button"
          title="Profiles and backups"
          aria-label="Profiles and backups"
          @click="$emit('open-profiles')"
        >
          <b>◎</b><span>Profiles</span>
        </button>
      </div>
    </div>
  </NcAppNavigation>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { NcAppNavigation } from '@nextcloud/vue'
import { getWeekNumber, parseDateKey } from '../../services/dateTime'
import { preferredScope, globalAppBg, activeThemeMode } from '../../../composables/useGlobalPreferences'
import type { TargetCategoryConfig, TargetsConfig } from '../../services/targets'
import ColorPickerPopover from '../ColorPickerPopover.vue'

type SidebarCalendar = { id: string; displayname: string; color?: string }

const defaultAppBg = computed(() => (activeThemeMode.value === 'dark' ? '#111111' : '#efefef'))

const MDI_LAYERS = "M12,16L19.36,10.27L21,9L12,2L3,9L4.63,10.27M12,18.54L4.62,12.81L3,14.07L12,21.07L21,14.07L19.37,12.8L12,18.54Z"
const MDI_CALENDAR_MULTIPLE = "M21,17V8H7V17H21M21,3A2,2 0 0,1 23,5V17A2,2 0 0,1 21,19H7C5.89,19 5,18.1 5,17V5A2,2 0 0,1 7,3H8V1H10V3H18V1H20V3H21M3,21H17V23H3C1.89,23 1,22.1 1,21V9H3V21M19,15H15V11H19V15Z"
const MDI_VIEW_COLUMN = "M16,5V18H21V5M4,18H9V5H4M10,18H15V5H10V18Z"
const MDI_TARGET = "M11,2V4.07C7.38,4.53 4.53,7.38 4.07,11H2V13H4.07C4.53,16.62 7.38,19.47 11,19.93V22H13V19.93C16.62,19.47 19.47,16.62 19.93,13H22V11H19.93C19.47,7.38 16.62,4.53 13,4.07V2M11,6.08V8H13V6.09C15.5,6.5 17.5,8.5 17.92,11H16V13H17.91C17.5,15.5 15.5,17.5 13,17.92V16H11V17.91C8.5,17.5 6.5,15.5 6.08,13H8V11H6.09C6.5,8.5 8.5,6.5 11,6.08M12,11A1,1 0 0,0 11,12A1,1 0 0,0 12,13A1,1 0 0,0 13,12A1,1 0 0,0 12,11Z"
const MDI_TUNE = "M3,17V19H9V17H3M3,5V7H13V5H3M13,21V19H21V17H13V15H11V21H13M7,9V11H3V13H7V15H9V9H7M21,13V11H11V13H21M15,9H17V7H21V5H17V3H15V9Z"
const MDI_VIEW_GRID_OUTLINE = "M3 11H11V3H3M5 5H9V9H5M13 21H21V13H13M15 15H19V19H15M3 21H11V13H3M5 15H9V19H5M13 3V11H21V3M19 9H15V5H19Z"
const MDI_CHECK_DECAGRAM = "M23,12L20.56,9.22L20.9,5.54L17.29,4.72L15.4,1.54L12,3L8.6,1.54L6.71,4.72L3.1,5.53L3.44,9.21L1,12L3.44,14.78L3.1,18.47L6.71,19.29L8.6,22.47L12,21L15.4,22.46L17.29,19.28L20.9,18.46L20.56,14.78L23,12M10,17L6,13L7.41,11.59L10,14.17L16.59,7.58L18,9L10,17Z"

const STEPS = [
  { id: 'strategy',    label: 'Strategy',    icon: MDI_LAYERS,            placeholder: 'Choose a planning model' },
  { id: 'calendars',   label: 'Calendars',   icon: MDI_CALENDAR_MULTIPLE, placeholder: 'No calendars selected' },
  { id: 'deck',        label: 'Deck',        icon: MDI_VIEW_COLUMN,       placeholder: 'Deck integration' },
  { id: 'goals',       label: 'Goals',       icon: MDI_TARGET,            placeholder: 'Set weekly targets' },
  { id: 'preferences', label: 'Preferences', icon: MDI_TUNE,              placeholder: 'Theme and hours' },
  { id: 'dashboard',   label: 'Dashboard',   icon: MDI_VIEW_GRID_OUTLINE, placeholder: 'Layout preset' },
  { id: 'review',      label: 'Review',      icon: MDI_CHECK_DECAGRAM,    placeholder: 'Confirm and save' },
] as const

const props = defineProps<{
  isLoading: boolean
  range: 'week' | 'month'
  offset: number
  from: string
  to: string
  navToggleLabel: string
  navToggleIcon: string
  dashboardMode?: 'quick' | 'standard' | 'pro'
  guidedHints?: Partial<Record<'strategy' | 'calendars' | 'deck' | 'goals' | 'preferences' | 'dashboard' | 'review', string>>
  releaseNotesAvailable?: boolean
  releaseNotesOpen?: boolean
  lastSync?: string | null
  guidedHintStatuses?: Partial<Record<'strategy' | 'calendars' | 'deck' | 'goals' | 'preferences' | 'dashboard' | 'review', 'done' | 'warn' | 'dim' | 'skip'>>
  themePreference?: 'auto' | 'light' | 'dark'
  targets?: TargetsConfig | null
  calendars?: SidebarCalendar[]
  groupsById?: Record<string, number>
  currentTargets?: Record<string, number>
}>()

const emit = defineEmits([
  'load',
  'update:range',
  'update:offset',
  'toggle-nav',
  'open-profiles',
  'open-release-notes',
  'open-shortcuts',
  'rerun-onboarding',
  'update:theme-preference',
  'update-total-hours',
  'update-category-target',
  'update-targets-config',
  'set-group',
  'set-calendar-target',
])

const rangeEyebrow = computed(() => props.range === 'month' ? 'This month' : 'This week')

const rangeHeadline = computed(() => {
  const date = parseDateKey(props.from)
  if (!date) return props.range === 'month' ? 'Month' : 'Week'
  if (props.range === 'month') {
    const monthNum = date.getUTCMonth() + 1
    return `Month ${monthNum}`
  }
  return `Week ${getWeekNumber(date)}`
})

const syncLabel = computed(() => {
  if (props.isLoading) return 'Syncing…'
  return props.lastSync ?? 'Ready'
})

// Collapsible setup card — starts expanded only when steps still need attention.
const setupDone = computed(() => {
  const statuses = props.guidedHintStatuses ?? {}
  return STEPS.every((s) => {
    const st = statuses[s.id]
    return st === 'done' || st === 'skip'
  })
})
const setupSummary = computed(() => {
  const statuses = props.guidedHintStatuses ?? {}
  const done = STEPS.filter((s) => statuses[s.id] === 'done' || statuses[s.id] === 'skip').length
  return `${done} of ${STEPS.length} ready`
})
const setupExpanded = ref(!setupDone.value)
watch(setupDone, (isDone, wasDone) => {
  // Auto-collapse the first time the checklist becomes complete; leave user
  // control after that.
  if (isDone && !wasDone) setupExpanded.value = false
})

function onColorInput(event: Event) {
  const value = (event.target as HTMLInputElement).value
  if (typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value)) {
    globalAppBg.value = value
  }
}

// Goals editor — collapsed by default. The parent owns persistence
// via useDashboardSelection; this component only emits.
const goalsExpanded = ref(false)
const openCategoryId = ref('')
const labelDrafts = ref<Record<string, string>>({})

const categories = computed<TargetCategoryConfig[]>(() => props.targets?.categories ?? [])

// calendar id → category id, built once per change.
const catByCalendar = computed(() => {
  const groupToCat = new Map<number, string>()
  for (const cat of categories.value) for (const g of cat.groupIds ?? []) groupToCat.set(g, cat.id)
  const out: Record<string, string> = {}
  const groups = props.groupsById ?? {}
  for (const cal of props.calendars ?? []) out[cal.id] = groupToCat.get(groups[cal.id] ?? 0) ?? ''
  return out
})

const readHours = (e: Event) => {
  const n = Number((e.target as HTMLInputElement).value)
  return Number.isFinite(n) ? Math.max(0, Math.min(1000, n)) : null
}
const formatHours = (v: number) => (Math.round((Number(v) || 0) * 10) / 10).toString()

const calendarsForCategory = (id: string) =>
  (props.calendars ?? []).filter((c) => catByCalendar.value[c.id] === id)

const assignableCalendars = (id: string) =>
  (props.calendars ?? []).filter((c) => {
    const cur = catByCalendar.value[c.id]
    return !cur || cur === id
  })

const calendarTarget = (calId: string) =>
  Math.round((Number(props.currentTargets?.[calId]) || 0) * 100) / 100

const toggleCategory = (id: string) => { openCategoryId.value = openCategoryId.value === id ? '' : id }

const patchCategories = (next: TargetCategoryConfig[]) => {
  if (props.targets) emit('update-targets-config', { ...props.targets, categories: next })
}
const patchCategory = (id: string, patch: Partial<TargetCategoryConfig>) =>
  patchCategories(categories.value.map((c) => (c.id === id ? { ...c, ...patch } : c)))

const onAssignCalendar = (calId: string, categoryId: string) => {
  const target = categoryId ? categories.value.find((c) => c.id === categoryId) : null
  const groupId = target?.groupIds?.find((n) => n > 0) ?? 0
  emit('set-group', { id: calId, groupId })
}
const onAddCalendarChange = (categoryId: string, select: HTMLSelectElement) => {
  if (!select.value) return
  onAssignCalendar(select.value, categoryId)
  select.value = ''
}

const addCategory = () => {
  if (!props.targets) return
  const used = new Set<number>()
  for (const c of categories.value) for (const g of c.groupIds ?? []) used.add(g)
  let groupId = 0
  for (let i = 1; i <= 9; i += 1) if (!used.has(i)) { groupId = i; break }
  const id = `cat_${Date.now().toString(36)}`
  patchCategories([...categories.value, {
    id,
    label: `Category ${categories.value.length + 1}`,
    targetHours: 0,
    includeWeekend: false,
    paceMode: 'days_only',
    color: null,
    groupIds: groupId ? [groupId] : [],
  }])
  openCategoryId.value = id
}
const removeCategory = (id: string) => {
  patchCategories(categories.value.filter((c) => c.id !== id))
  if (openCategoryId.value === id) openCategoryId.value = ''
}

// Label draft: normalizeTargetsConfig trims + falls back to capitalize(id)
// on every emit, so live-committing keystrokes fights the input. Buffer
// per row while typing; commit on blur / Enter / change.
const labelDraft = (id: string, actual: string) =>
  labelDrafts.value[id] !== undefined ? labelDrafts.value[id] : actual
const onCategoryLabelInput = (id: string, v: string) => {
  labelDrafts.value = { ...labelDrafts.value, [id]: v }
}
const onCategoryLabelCommit = (id: string, v: string) => {
  const trimmed = v.trim()
  const next = { ...labelDrafts.value }
  delete next[id]
  labelDrafts.value = next
  const cur = categories.value.find((c) => c.id === id)
  if (!trimmed || !cur || cur.label === trimmed) return
  patchCategory(id, { label: trimmed })
}
</script>

<style scoped>
:global(.app-opsdash #app-navigation),
:global(.app-opsdash .app-navigation) {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: visible !important;
}
:global(.app-opsdash .app-content__navigation) {
  overflow: visible !important;
}
:global(.app-opsdash .app-navigation__content) {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 100%;
  overflow-y: auto !important;
  overflow-x: hidden !important;
  max-height: 100% !important;
}

.sb-inner {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 0 4px 4px;
  min-height: 100%;
}

.hero-topbar {
  display: flex;
  align-items: center;
  gap: 8px;
}

.hero-seg {
  flex: 1;
}

/* ── Hide button ── */
.hide-btn {
  position: static;
  width: 34px;
  height: 34px;
  border-radius: 10px;
  border: 1px solid color-mix(in oklab, var(--brand), transparent 82%);
  color: var(--brand);
  background: color-mix(in oklab, var(--brand), transparent 86%);
  box-shadow: 0 2px 8px rgba(15, 23, 42, .08);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  appearance: none;
}
.hide-btn:hover {
  background: color-mix(in oklab, var(--brand), transparent 76%);
}

/* ── Range hero card ── */
.rc {
  border: 1px solid color-mix(in oklab, var(--brand), transparent 82%);
  border-radius: 22px;
  padding: 17px 16px 15px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  box-shadow:
    0 10px 24px rgba(15, 23, 42, .08),
    inset 0 1px 0 rgba(255, 255, 255, .45);
}
.hero {
  background: var(--card, #fff);
}

/* Dark-mode-only: put a tasteful gradient sheen back on every sidebar
   card. Light mode stays flat because the same treatment reads as a
   muddy grey wash there. Skipped entirely when the user has picked a
   custom app bg so the chosen color paints the sidebar cleanly. */
:global(#opsdash.opsdash-theme-dark:not(.has-app-bg)) .hero {
  background:
    radial-gradient(circle at 120% -10%, color-mix(in oklab, var(--brand), transparent 76%), transparent 52%),
    linear-gradient(180deg, color-mix(in oklab, var(--card), var(--brand) 6%), var(--card));
}
:global(#opsdash.opsdash-theme-dark:not(.has-app-bg)) .qs,
:global(#opsdash.opsdash-theme-dark:not(.has-app-bg)) .ge,
:global(#opsdash.opsdash-theme-dark:not(.has-app-bg)) .sc {
  background:
    linear-gradient(180deg, color-mix(in oklab, var(--card), var(--brand) 4%), var(--card));
}
:global(#opsdash.opsdash-theme-dark:not(.has-app-bg)) .dock {
  background:
    radial-gradient(circle at 50% -30%, color-mix(in oklab, var(--brand), transparent 82%), transparent 62%),
    linear-gradient(180deg, color-mix(in oklab, var(--card), var(--brand) 3%), color-mix(in oklab, var(--card), var(--fg) 4%));
}

.ew {
  font-size: 10px;
  font-weight: 900;
  letter-spacing: .16em;
  text-transform: uppercase;
  color: var(--muted, #64748b);
  margin-bottom: 3px;
}

.rc-big {
  font-size: 28px;
  font-weight: 900;
  letter-spacing: -.06em;
  line-height: 1;
  color: var(--fg, #0f172a);
}

.rc-sub {
  font-size: 12px;
  color: var(--muted, #64748b);
  margin-top: -5px;
}

/* Segmented pill */
.seg {
  display: grid;
  gap: 3px;
  padding: 3px;
  border-radius: 999px;
  background: color-mix(in oklab, var(--fg, #0f172a) 6%, transparent);
}
.seg.w2 { grid-template-columns: 1fr 1fr; }
.seg.w3 { grid-template-columns: 1fr 1fr 1fr; }

.seg button {
  appearance: none;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 800;
  color: var(--muted, #64748b);
  border: 0;
  background: none;
  cursor: pointer;
}
.seg button.on {
  color: #fff;
  background: var(--brand, #2563eb);
  box-shadow: 0 4px 10px color-mix(in oklab, var(--brand), transparent 72%);
}

/* Nav row */
.navrow {
  display: grid;
  gap: 6px;
  align-items: center;
}
.navrow.arw-layout { grid-template-columns: 34px 1fr 34px; }

.arw {
  width: 34px;
  height: 34px;
  border-radius: 11px;
  border: 1px solid var(--line, #e2e8f0);
  background: var(--card, #fff);
  color: var(--fg, #0f172a);
  box-shadow: 0 1px 4px rgba(15, 23, 42, .08);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  appearance: none;
}
.arw:disabled { opacity: .45; cursor: default; }
.arw:hover:not(:disabled) { background: color-mix(in oklab, var(--card), var(--brand) 5%); }

.date-pill {
  height: 34px;
  border-radius: 11px;
  border: 1px solid color-mix(in oklab, var(--brand), transparent 82%);
  background: color-mix(in oklab, var(--brand), transparent 93%);
  color: var(--brand, #2563eb);
  font-size: 11px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  padding: 0 6px;
}

/* Sync row */
.sync-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.sync-left {
  display: flex;
  align-items: center;
  gap: 5px;
}
.sync-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--green, #16a34a);
  flex-shrink: 0;
}
.sync-dot.syncing {
  background: var(--amber, #d97706);
  animation: pulse 1s ease-in-out infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: .4; }
}
.sync-txt {
  font-size: 11px;
  color: var(--muted, #64748b);
}

.btn-ref {
  height: 30px;
  border-radius: 999px;
  padding: 0 13px;
  background: var(--brand, #2563eb);
  color: #fff;
  font-size: 12px;
  font-weight: 800;
  border: 0;
  cursor: pointer;
  box-shadow: 0 4px 12px color-mix(in oklab, var(--brand), transparent 74%);
  appearance: none;
}
.btn-ref:disabled { opacity: .5; cursor: default; }

/* ── Quick settings card ── */
.qs {
  border: 1px solid var(--line, #e2e8f0);
  border-radius: 18px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: var(--card, #fff);
  box-shadow: 0 6px 16px rgba(15, 23, 42, .05);
}
.qs-hd { display: flex; align-items: center; justify-content: space-between; }
.qs-row {
  display: grid;
  grid-template-columns: 88px 1fr;
  align-items: center;
  gap: 8px;
}
.qs-label {
  font-size: 11px;
  font-weight: 800;
  color: var(--muted, #64748b);
  letter-spacing: .04em;
  text-transform: uppercase;
}
.qs-color {
  display: flex;
  align-items: center;
  gap: 8px;
}
.qs-swatch {
  position: relative;
  width: 34px;
  height: 30px;
  border-radius: 10px;
  border: 1px solid var(--line, #e2e8f0);
  overflow: hidden;
  cursor: pointer;
  display: block;
}
.qs-swatch input[type=color] {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: 0;
  cursor: pointer;
  border: 0;
  padding: 0;
}
.qs-swatch-dot {
  position: absolute;
  inset: 0;
  display: block;
  border-radius: 9px;
  background: var(--card, #fff);
}
.qs-swatch-dot--none {
  background:
    linear-gradient(45deg, transparent 45%, var(--line, #e5e7eb) 45%, var(--line, #e5e7eb) 55%, transparent 55%),
    var(--card, #fff);
}
.qs-clear {
  height: 30px;
  padding: 0 12px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 800;
  color: var(--muted, #64748b);
  background: transparent;
  border: 1px solid var(--line, #e2e8f0);
  cursor: pointer;
  appearance: none;
}
.qs-clear:disabled { opacity: .4; cursor: default; }
.qs-clear:hover:not(:disabled) { background: color-mix(in oklab, var(--fg, #0f172a) 5%, transparent); color: var(--fg, #0f172a); }

/* ── Goals editor ── */
.ge {
  border: 1px solid var(--line, #e2e8f0);
  border-radius: 18px;
  padding: 4px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  background: var(--card, #fff);
  box-shadow: 0 6px 16px rgba(15, 23, 42, .05);
}
.ge-row {
  display: grid;
  grid-template-columns: 60px 1fr;
  align-items: center;
  gap: 8px;
  padding: 4px 0;
}
.ge-label {
  font-size: 11px;
  font-weight: 800;
  color: var(--muted, #64748b);
  letter-spacing: .04em;
  text-transform: uppercase;
}
.ge-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  border: 1px solid color-mix(in oklab, var(--fg, #0f172a) 15%, transparent);
  flex-shrink: 0;
}
.ge-cat-label,
.ge-cal-label {
  font-size: 12px;
  font-weight: 700;
  color: var(--fg, #0f172a);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1 1 auto;
}
.ge-cats {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.ge-catcard {
  border: 1px solid color-mix(in oklab, var(--fg, #0f172a) 8%, transparent);
  border-radius: 12px;
  background: color-mix(in oklab, var(--card, #fff) 92%, var(--fg, #0f172a) 4%);
  padding: 4px;
}
.ge-catcard--open {
  border-color: color-mix(in oklab, var(--brand, #2563eb) 30%, transparent);
  box-shadow: 0 4px 10px color-mix(in oklab, var(--brand, #2563eb) 8%, transparent);
}
.ge-catcard__head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto auto auto;
  align-items: center;
  gap: 6px;
  padding: 2px 4px;
}
.ge-color :deep(.cpp-trigger) {
  padding: 3px;
  gap: 0;
  border-radius: 999px;
  border-color: color-mix(in oklab, var(--fg, #0f172a) 14%, transparent);
}
.ge-color :deep(.cpp-dot) {
  width: 16px;
  height: 16px;
  border-radius: 50%;
}
.ge-color__spacer { display: none; }
.ge-catlabel {
  min-width: 0;
  width: 100%;
  height: 26px;
  border: 1px solid color-mix(in oklab, var(--fg, #0f172a) 8%, transparent);
  border-radius: 8px;
  background: color-mix(in oklab, var(--card, #fff) 92%, var(--fg, #0f172a) 3%);
  padding: 0 8px;
  font-size: 12px;
  font-weight: 700;
  color: var(--fg, #0f172a);
  cursor: text;
}
.ge-catlabel:hover {
  border-color: color-mix(in oklab, var(--fg, #0f172a) 18%, transparent);
}
.ge-catlabel:focus {
  outline: none;
  border-color: color-mix(in oklab, var(--brand, #2563eb) 45%, transparent);
  background: var(--card, #fff);
  box-shadow: 0 0 0 2px color-mix(in oklab, var(--brand, #2563eb) 18%, transparent);
}
.ge-catlabel::placeholder {
  color: var(--muted, #64748b);
  font-weight: 600;
}
.ge-catcard__toggle {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 22px;
  padding: 0 8px;
  border-radius: 999px;
  border: 1px solid var(--line, #e2e8f0);
  background: transparent;
  color: var(--muted, #64748b);
  font-size: 11px;
  font-weight: 800;
  cursor: pointer;
  appearance: none;
}
.ge-catcard__toggle:hover {
  color: var(--fg, #0f172a);
  background: color-mix(in oklab, var(--fg, #0f172a) 4%, transparent);
}
.ge-catcard__count { font-variant-numeric: tabular-nums; }
.ge-caret { transition: transform .18s ease; }
.ge-caret--open { transform: rotate(180deg); }
.ge-catcard__remove {
  width: 22px;
  height: 22px;
  border-radius: 8px;
  border: 1px solid transparent;
  background: transparent;
  color: var(--muted, #64748b);
  font-size: 15px;
  line-height: 1;
  cursor: pointer;
  appearance: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.ge-catcard__remove:hover:not(:disabled) {
  color: var(--neg, #ef4444);
  background: color-mix(in oklab, var(--neg, #ef4444) 10%, transparent);
}
.ge-catcard__remove:disabled { opacity: .35; cursor: default; }
.ge-catcard__body {
  padding: 6px 4px 4px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.ge-calrow {
  display: grid;
  grid-template-columns: 10px minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 6px;
  padding: 2px 4px;
}
.ge-add-cal {
  display: flex;
  align-items: center;
  gap: 6px;
  height: 26px;
  padding: 0 8px;
  border-radius: 8px;
  border: 1px dashed color-mix(in oklab, var(--fg, #0f172a) 18%, transparent);
  color: var(--muted, #64748b);
  font-size: 11px;
  font-weight: 800;
  cursor: pointer;
  position: relative;
}
.ge-add-cal:hover {
  color: var(--brand, #2563eb);
  border-color: color-mix(in oklab, var(--brand, #2563eb) 40%, transparent);
}
.ge-add-cal select {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}
.ge-hint {
  font-size: 11px;
  color: var(--muted, #64748b);
  padding: 4px 6px;
}
.ge-add {
  width: 100%;
  height: 28px;
  border-radius: 10px;
  border: 1px dashed color-mix(in oklab, var(--brand, #2563eb) 40%, transparent);
  background: transparent;
  color: var(--brand, #2563eb);
  font-size: 12px;
  font-weight: 800;
  cursor: pointer;
  appearance: none;
}
.ge-add:hover {
  background: color-mix(in oklab, var(--brand, #2563eb) 10%, transparent);
}
.ge-input {
  display: flex;
  align-items: center;
  gap: 4px;
  border: 1px solid var(--line, #e2e8f0);
  border-radius: 10px;
  padding: 2px 8px;
  background: var(--card, #fff);
  min-width: 0;
}
.ge-input input[type="number"] {
  width: 56px;
  border: 0;
  background: transparent;
  color: var(--fg, #0f172a);
  font-size: 12px;
  font-weight: 700;
  text-align: right;
  padding: 4px 0;
  appearance: textfield;
  -moz-appearance: textfield;
}
.ge-input--sm { padding: 1px 6px; }
.ge-input--sm input[type="number"] { width: 40px; font-size: 11px; }
.ge-input input[type="number"]::-webkit-outer-spin-button,
.ge-input input[type="number"]::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.ge-input input[type="number"]:focus {
  outline: none;
}
.ge-unit {
  font-size: 10px;
  font-weight: 700;
  color: var(--muted, #64748b);
  letter-spacing: .04em;
  text-transform: uppercase;
}

/* ── Setup card ── */
.sc {
  border: 1px solid var(--line, #e2e8f0);
  border-radius: 18px;
  padding: 4px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  background: var(--card, #fff);
  box-shadow: 0 6px 16px rgba(15, 23, 42, .05);
}

.sc-toggle {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  border: 0;
  background: transparent;
  padding: 10px 12px;
  border-radius: 14px;
  cursor: pointer;
  text-align: left;
  color: inherit;
  font: inherit;
}
.sc-toggle:hover { background: color-mix(in oklab, var(--fg, #0f172a) 4%, transparent); }

.sc-title {
  font-size: 15px;
  font-weight: 900;
  letter-spacing: -.02em;
  line-height: 1.1;
  margin-top: 3px;
  color: var(--fg, #0f172a);
  display: flex;
  align-items: baseline;
  gap: 8px;
}
.sc-progress {
  font-size: 11px;
  font-weight: 700;
  color: var(--muted, #64748b);
  letter-spacing: 0;
  text-transform: none;
}

.sc-caret {
  width: 22px;
  height: 22px;
  display: grid;
  place-items: center;
  color: var(--muted, #64748b);
  transition: transform .18s ease;
  flex-shrink: 0;
}
.sc-caret--open { transform: rotate(180deg); }

.sc-body {
  display: flex;
  flex-direction: column;
  gap: 9px;
  padding: 0 10px 10px;
}

.badge {
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 900;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: var(--brand, #2563eb);
  background: color-mix(in oklab, var(--brand), transparent 93%);
  border: 1px solid color-mix(in oklab, var(--brand), transparent 82%);
  flex-shrink: 0;
}
.badge--std {
  color: var(--muted, #64748b);
  background: transparent;
  border-color: var(--line, #e2e8f0);
}

.wiz {
  width: 100%;
  height: 34px;
  border-radius: 11px;
  background: linear-gradient(135deg, var(--brand, #2563eb), color-mix(in oklab, var(--brand, #2563eb), #1d4ed8 40%));
  color: #fff;
  font-size: 12px;
  font-weight: 900;
  border: 0;
  cursor: pointer;
  box-shadow: 0 5px 14px color-mix(in oklab, var(--brand), transparent 74%);
  appearance: none;
  display: flex;
  align-items: center;
  justify-content: center;
}
.wiz:hover { filter: brightness(1.08); }

/* ── Steps ── */
.steps {
  display: flex;
  flex-direction: column;
  gap: 5px;
  list-style: none;
  padding: 0;
  margin: 0;
}

.step {
  display: grid;
  grid-template-columns: 26px 1fr;
  gap: 8px;
  align-items: center;
  padding: 7px 9px;
  border-radius: 11px;
  background: color-mix(in oklab, var(--fg, #0f172a) 3%, transparent);
  border: 1px solid transparent;
  min-width: 0;
  cursor: pointer;
}
.step:hover { background: color-mix(in oklab, var(--fg, #0f172a) 5%, transparent); }

.step.done {
  background: rgba(22, 163, 74, .05);
  border-color: rgba(22, 163, 74, .13);
}
.step.warn {
  background: rgba(217, 119, 6, .06);
  border-color: rgba(217, 119, 6, .17);
}
.step.skip {
  background: transparent;
  border-color: rgba(100, 116, 139, .14);
}
.step.dim {
  background: transparent;
}

/* Step icon badge */
.sn {
  width: 26px;
  height: 26px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 900;
  background: var(--brand, #2563eb);
  color: #fff;
  flex-shrink: 0;
}
.step.done .sn { background: var(--green, #16a34a); }
.step.warn .sn { background: var(--amber, #d97706); }
.step.dim  .sn { background: color-mix(in oklab, var(--fg, #0f172a) 9%, transparent); color: var(--muted, #64748b); }
.step.skip .sn { background: rgba(100, 116, 139, .18); color: var(--muted, #64748b); }

/* Step text */
.sb-txt { min-width: 0; overflow: hidden; }
.sb-txt strong {
  display: block;
  font-size: 12px;
  font-weight: 800;
  line-height: 1.1;
  color: var(--fg, #0f172a);
}
.sb-txt small {
  display: block;
  font-size: 11px;
  color: var(--muted, #64748b);
  margin-top: 1px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ── Dock ── */
.dock {
  border: 1px solid var(--line, #e2e8f0);
  border-radius: 20px;
  padding: 7px;
  background:
    linear-gradient(180deg, color-mix(in oklab, var(--card, #fff), var(--brand) 2%), color-mix(in oklab, var(--card, #fff), var(--fg, #0f172a) 2%));
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 4px;
  margin-top: auto;
  box-shadow:
    0 10px 24px color-mix(in oklab, var(--fg, #0f172a) 6%, transparent),
    inset 0 1px 0 color-mix(in oklab, var(--card, #fff) 40%, transparent);
}
:global(#opsdash.has-app-bg) .dock {
  background: var(--card, #fff);
}

.dk-btn {
  height: 50px;
  border-radius: 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  font-size: 11px;
  font-weight: 800;
  color: var(--muted, #64748b);
  border: 0;
  background: none;
  cursor: pointer;
  appearance: none;
}
.dk-btn b { font-size: 17px; line-height: 1; }
.dk-btn.on { color: var(--brand, #2563eb); background: color-mix(in oklab, var(--brand), transparent 93%); }
.dk-btn:disabled { opacity: .4; cursor: default; }
.dk-btn:hover:not(:disabled) { background: color-mix(in oklab, var(--fg, #0f172a) 5%, transparent); }
</style>
