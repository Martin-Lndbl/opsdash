<template>
  <div class="options-wrapper" @keydown.stop>
    <button ref="triggerRef" type="button" class="ic ic--config" title="Open widget settings" @click.stop="togglePop">
      <svg width="14" height="12" viewBox="0 0 14 12" fill="none" aria-hidden="true">
        <path d="M1 3h12M1 6h12M1 9h12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
        <circle cx="4" cy="3" r="1.5" stroke="currentColor" stroke-width="1.3"/>
        <circle cx="10" cy="6" r="1.5" stroke="currentColor" stroke-width="1.3"/>
        <circle cx="6" cy="9" r="1.5" stroke="currentColor" stroke-width="1.3"/>
      </svg>
      <span class="ic-lbl">Settings</span>
    </button>
    <div v-if="open" class="options-pop" :style="popStyle">
      <div class="opt-section">
        <div class="opt-section__title">Layout & title</div>
        <div v-for="control in coreControls" :key="control.key" class="opt-row">
          <label :for="`opt-${control.key}`">{{ control.label }}</label>
          <template v-if="control.type === 'number'">
            <input
              :id="`opt-${control.key}`"
              type="number"
              :min="control.min"
              :max="control.max"
              :step="control.step || 1"
              :value="valueFor(control.key) ?? ''"
              @input="onNumber(control.key, $event)"
            />
          </template>
          <template v-else-if="control.type === 'select'">
            <select :id="`opt-${control.key}`" :value="valueFor(control.key)" @change="onSelect(control.key, $event)">
              <option v-for="opt in control.options || []" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </template>
          <template v-else-if="control.type === 'toggle'">
            <input
              :id="`opt-${control.key}`"
              type="checkbox"
              :checked="!!valueFor(control.key)"
              @change="onToggle(control.key, $event)"
            />
          </template>
          <template v-else-if="control.type === 'text'">
            <input
              :id="`opt-${control.key}`"
              type="text"
              :value="valueFor(control.key) ?? ''"
              @input="onText(control.key, $event)"
            />
          </template>
          <template v-else-if="control.type === 'color'">
            <ColorPickerPopover
              :model-value="valueFor(control.key) || '#ffffff'"
              :allow-clear="control.key === 'cardBg'"
              @update:model-value="(v) => emit('change', control.key, v === '' ? null : v)"
            />
          </template>
        </div>
      </div>

      <div class="opt-section" v-if="specificControls.length">
        <div class="opt-section__title">Widget options</div>
        <div v-for="control in specificControls" :key="control.key" class="opt-row">
          <label :for="`opt-${control.key}`">{{ control.label }}</label>
          <template v-if="control.type === 'number'">
            <input
              :id="`opt-${control.key}`"
              type="number"
              :min="control.min"
              :max="control.max"
              :step="control.step || 1"
            :value="valueFor(control.key) ?? ''"
            @input="onNumber(control.key, $event)"
          />
        </template>
          <template v-else-if="control.type === 'select'">
            <select :id="`opt-${control.key}`" :value="valueFor(control.key)" @change="onSelect(control.key, $event)">
              <option v-for="opt in control.options || []" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </template>
          <template v-else-if="control.type === 'toggle'">
            <input
              :id="`opt-${control.key}`"
              type="checkbox"
              :checked="!!valueFor(control.key)"
              @change="onToggle(control.key, $event)"
            />
          </template>
          <template v-else-if="control.type === 'text'">
            <input
              :id="`opt-${control.key}`"
              type="text"
              :value="valueFor(control.key) ?? ''"
              @input="onText(control.key, $event)"
            />
          </template>
          <template v-else-if="control.type === 'color'">
            <ColorPickerPopover
              :model-value="valueFor(control.key) || '#ffffff'"
              :allow-clear="control.key === 'cardBg'"
              @update:model-value="(v) => emit('change', control.key, v === '' ? null : v)"
            />
          </template>
          <template v-else-if="control.type === 'colorlist'">
            <div class="colorlist">
              <ColorPickerPopover
                v-for="(col, idx) in paletteValue(control.key)"
                :key="`${control.key}-${idx}`"
                :model-value="col"
                @update:model-value="(v) => onPaletteColor(control.key, idx, v)"
              />
              <button type="button" class="ghost sm" @click="addPalette(control.key)">+</button>
            </div>
          </template>
          <template v-else-if="control.type === 'multiselect'">
            <div class="multi">
              <label v-for="opt in control.options || []" :key="`${control.key}-${opt.value}`" class="multi__item">
                <input
                  type="checkbox"
                  :value="opt.value"
                  :checked="multiSelected(control, opt.value)"
                  @change="onMulti(control, opt.value, $event)"
                />
                <span>{{ opt.label }}</span>
              </label>
            </div>
          </template>
          <template v-else-if="control.type === 'taglist'">
            <div class="taglist">
              <div v-if="control.hint" class="taglist__hint">{{ control.hint }}</div>
              <div v-else-if="isTagListDefault(control)" class="taglist__hint">
                All tags are active by default. Uncheck to hide a filter.
              </div>
              <label v-for="opt in control.options || []" :key="`${control.key}-${opt.value}`" class="taglist__item">
                <span class="taglist__meta">
                  <input
                    type="checkbox"
                    :value="opt.value"
                    :checked="tagListSelected(control, opt.value)"
                    @change="onTagListToggle(control, opt.value, $event)"
                  />
                  <span class="taglist__label">{{ opt.label }}</span>
                </span>
                <span v-if="typeof opt.count === 'number'" class="taglist__count">{{ opt.count }}</span>
              </label>
            </div>
          </template>
          <template v-else-if="control.type === 'textarea'">
            <textarea
              :id="`opt-${control.key}`"
              rows="3"
              :value="valueFor(control.key) ?? ''"
              @input="onText(control.key, $event)"
            />
          </template>
          <template v-else-if="control.type === 'filterbuilder'">
            <div class="filter-builder">
              <div class="filter-builder__hint">
                Tags and assigned can be used independently—leave one empty to ignore it. Use commas. Special assignees: <code>me</code>, <code>unassigned</code>.
              </div>
              <div
                v-for="(row, idx) in filterBuilderValue(control.key)"
                :key="`${control.key}-row-${idx}`"
                class="filter-builder__row"
              >
                <input
                  type="text"
                  class="filter-builder__label"
                  :value="row.label"
                  placeholder="Filter label"
                  @input="onFilterRow(control.key, idx, 'label', $event)"
                />
                <input
                  type="text"
                  class="filter-builder__input"
                  :value="row.labels"
                  placeholder="Tags (comma separated)"
                  @input="onFilterRow(control.key, idx, 'labels', $event)"
                />
                <input
                  type="text"
                  class="filter-builder__input"
                  :value="row.assignees"
                  placeholder="Assigned (comma separated)"
                  @input="onFilterRow(control.key, idx, 'assignees', $event)"
                />
                <button type="button" class="ghost sm" @click="removeFilterRow(control.key, idx)">✕</button>
              </div>
              <button type="button" class="ghost sm" @click="addFilterRow(control.key)">+ Add filter</button>
            </div>
          </template>
        </div>
      </div>
      <div v-if="tabTargets.length" class="opt-section">
        <div class="opt-section__title">Tab actions</div>
        <div class="tab-actions">
          <div v-for="tab in tabTargets" :key="tab.id" class="tab-actions__row">
            <div class="tab-actions__meta">
              <div class="tab-actions__label">{{ tab.label }}</div>
              <div v-if="tab.current" class="tab-actions__hint">Current tab</div>
            </div>
            <div class="tab-actions__buttons">
              <button
                type="button"
                class="ghost sm"
                :disabled="tab.current"
                @click.stop="emit('move-to-tab', tab.id)"
              >
                Move
              </button>
              <button
                type="button"
                class="ghost sm"
                :disabled="tab.current"
                @click.stop="emit('duplicate-to-tab', tab.id)"
              >
                Copy
              </button>
            </div>
          </div>
        </div>
      </div>
      <div v-else-if="showTabActionsHint" class="opt-section">
        <div class="opt-section__title">Tab actions</div>
        <div class="tab-actions__empty">Add another tab to move or copy this widget between tabs.</div>
      </div>
      <div v-if="showAdvanced" class="opt-row opt-row--footer">
        <button
          type="button"
          class="link-btn"
          title="Opens a full targets editor for this widget only. Use the reset inside to go back to global."
          @click.stop="emit('open-advanced')"
        >
          Advanced targets (widget only)
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue'
import ColorPickerPopover from '../ColorPickerPopover.vue'

const triggerRef = ref<HTMLElement | null>(null)
const popStyle = ref<Record<string, string>>({})

function togglePop() {
  open.value = !open.value
  if (open.value) {
    nextTick(() => {
      const btn = triggerRef.value
      if (!btn) return
      const r = btn.getBoundingClientRect()
      const popW = Math.min(440, window.innerWidth - 24)
      let left = r.right - popW
      if (left < 12) left = 12
      popStyle.value = {
        position: 'fixed',
        top: `${r.bottom + 8}px`,
        left: `${left}px`,
        width: `${popW}px`,
      }
    })
  }
}

const props = defineProps<{
  entry: any
  options: Record<string, any>
  open: boolean
  showAdvanced?: boolean
  context?: Record<string, any>
  tabs?: Array<{ id: string; label: string }>
  currentTabId?: string | null
}>()

const emit = defineEmits<{
  (e: 'change', key: string, value: any): void
  (e: 'toggle', open: boolean): void
  (e: 'open-advanced'): void
  (e: 'move-to-tab', tabId: string): void
  (e: 'duplicate-to-tab', tabId: string): void
}>()

const local = ref<Record<string, any>>({})
const CORE_DEFAULT_OPTIONS = {
  showHeader: true,
  dense: false,
  scale: 'md',
}

const coreControls = [
  { key: 'titlePrefix', label: 'Title prefix', type: 'text' },
  { key: 'showHeader', label: 'Show title', type: 'toggle' },
  {
    key: 'cardBg',
    label: 'Card background',
    type: 'color',
    hint: 'Pick a card fill; leave empty for default.',
  },
  {
    key: 'scale',
    label: 'Scale',
    type: 'select',
    options: [
      { value: 'sm', label: 'Small' },
      { value: 'md', label: 'Normal' },
      { value: 'lg', label: 'Large' },
      { value: 'xl', label: 'Extra large' },
    ],
  },
  { key: 'dense', label: 'Dense mode', type: 'toggle' },
]

const specificControls = computed(() => {
  const blockKeys = new Set(coreControls.map((c) => c.key))
  const controls = props.entry?.controls || []
  const dynamic =
    typeof props.entry?.dynamicControls === 'function'
      ? props.entry.dynamicControls(local.value || {}, props.context || {})
      : []
  const merged = [...controls, ...dynamic].filter((c: any) => !blockKeys.has(c.key))
  return merged
})

const tabTargets = computed(() =>
  (props.tabs || []).map((tab) => ({
    id: String(tab.id),
    label: String(tab.label || tab.id),
    current: String(tab.id) === String(props.currentTabId || ''),
  })),
)

const showTabActionsHint = computed(() => (props.tabs || []).length === 1)

watch(
  () => [props.entry, props.options],
  ([entry, next]) => {
    const defaults = entry?.defaultOptions || {}
    const resolved =
      typeof entry?.resolveOptions === 'function'
        ? entry.resolveOptions(next || {}, props.context || {})
        : {}
    local.value = { ...CORE_DEFAULT_OPTIONS, ...defaults, ...resolved, ...(next || {}) }
  },
  { immediate: true, deep: true },
)

const open = computed({
  get: () => !!props.open,
  set: (val: boolean) => emit('toggle', val),
})

function onNumber(key: string, event: Event) {
  const value = Number((event.target as HTMLInputElement).value)
  emit('change', key, value)
}
function onSelect(key: string, event: Event) {
  const value = (event.target as HTMLSelectElement).value
  emit('change', key, value)
}
function onToggle(key: string, event: Event) {
  const value = (event.target as HTMLInputElement).checked
  emit('change', key, value)
}
function onText(key: string, event: Event) {
  const value = (event.target as HTMLInputElement | HTMLTextAreaElement).value
  emit('change', key, value)
}
function multiOptionValues(control: any): any[] {
  if (!Array.isArray(control?.options)) return []
  return control.options.map((opt: any) => opt?.value)
}
function multiSelected(control: any, value: any): boolean {
  const raw = valueFor(control.key)
  if (Array.isArray(raw) && raw.length) return raw.includes(value)
  if (control?.defaultAll) return true
  return false
}
function onMulti(control: any, value: any, event: Event) {
  const checked = (event.target as HTMLInputElement).checked
  const raw = valueFor(control.key)
  const current = Array.isArray(raw) && raw.length
    ? [...raw]
    : (control?.defaultAll ? multiOptionValues(control) : [])
  const next = new Set(current)
  if (checked) next.add(value)
  else next.delete(value)
  emit('change', control.key, Array.from(next))
}
function tagListOptionValues(control: any): string[] {
  if (!Array.isArray(control?.options)) return []
  return control.options.map((opt: any) => String(opt?.value ?? '')).filter(Boolean)
}
function tagListSelected(control: any, value: any) {
  const raw = valueFor(control.key)
  const selected = Array.isArray(raw) ? raw.map((entry: any) => String(entry)) : tagListOptionValues(control)
  return selected.includes(String(value))
}
function isTagListDefault(control: any) {
  return !Array.isArray(valueFor(control.key))
}
function onTagListToggle(control: any, value: any, event: Event) {
  const checked = (event.target as HTMLInputElement).checked
  const options = tagListOptionValues(control)
  const raw = valueFor(control.key)
  const current = Array.isArray(raw) ? raw.map((entry: any) => String(entry)) : options
  const next = new Set(current)
  const key = String(value)
  if (checked) next.add(key)
  else next.delete(key)
  const ordered = options.filter((opt) => next.has(opt))
  emit('change', control.key, ordered)
}
function valueFor(key: string) {
  if (key === 'scale') {
    return local.value.scale ?? local.value.textSize
  }
  if (!key.includes('.')) return local.value[key]
  const parts = key.split('.')
  let cur: any = local.value
  for (const p of parts) {
    if (cur == null) return undefined
    cur = cur[p]
  }
  return cur
}
function paletteValue(key: string) {
  const raw = valueFor(key)
  if (Array.isArray(raw) && raw.length) return raw
  if (typeof raw === 'string' && raw.trim()) {
    return raw.split(',').map((v: string) => v.trim()).filter(Boolean)
  }
  return defaultPalette.slice(0, 5)
}
function onPalette(key: string, idx: number, event: Event) {
  const value = (event.target as HTMLInputElement).value
  const arr = [...paletteValue(key)]
  arr[idx] = value
  emit('change', key, arr)
}
function onPaletteColor(key: string, idx: number, value: string) {
  const arr = [...paletteValue(key)]
  arr[idx] = value
  emit('change', key, arr)
}
function addPalette(key: string) {
  const arr = [...paletteValue(key), '#cccccc']
  emit('change', key, arr)
}

const defaultPalette = ['#2563EB', '#F97316', '#10B981', '#A855F7', '#EC4899']

type FilterBuilderRow = {
  label: string
  labels: string
  assignees: string
}

function filterBuilderValue(key: string): FilterBuilderRow[] {
  const raw = valueFor(key)
  if (Array.isArray(raw)) {
    return raw.map((row) => ({
      label: String(row?.label || ''),
      labels: Array.isArray(row?.labels) ? row.labels.join(', ') : String(row?.labels || ''),
      assignees: Array.isArray(row?.assignees) ? row.assignees.join(', ') : String(row?.assignees || ''),
    }))
  }
  if (typeof raw === 'string' && raw.trim()) {
    try {
      const parsed = JSON.parse(raw)
      if (Array.isArray(parsed)) {
        return parsed.map((row) => ({
          label: String(row?.label || ''),
          labels: Array.isArray(row?.labels) ? row.labels.join(', ') : String(row?.labels || ''),
          assignees: Array.isArray(row?.assignees) ? row.assignees.join(', ') : String(row?.assignees || ''),
        }))
      }
    } catch {
      return []
    }
  }
  return []
}

function addFilterRow(key: string) {
  const next = [...filterBuilderValue(key), { label: '', labels: '', assignees: '' }]
  emit('change', key, serializeFilterRows(next))
}

function removeFilterRow(key: string, idx: number) {
  const next = filterBuilderValue(key).filter((_, i) => i !== idx)
  emit('change', key, serializeFilterRows(next))
}

function onFilterRow(key: string, idx: number, field: keyof FilterBuilderRow, event: Event) {
  const value = (event.target as HTMLInputElement).value
  const next = filterBuilderValue(key).map((row, i) => (i === idx ? { ...row, [field]: value } : row))
  emit('change', key, serializeFilterRows(next))
}

function serializeFilterRows(rows: FilterBuilderRow[]) {
  return rows.map((row) => ({
    label: row.label.trim(),
    labels: splitFilterValue(row.labels),
    assignees: splitFilterValue(row.assignees),
  }))
}

function splitFilterValue(value: string) {
  return value
    .split(',')
    .map((entry) => entry.trim())
    .filter(Boolean)
}
</script>

<style scoped>
.options-wrapper {
  position: relative;
}

.options-pop {
  --opt-pop-bg: #ffffff;
  --opt-pop-border: color-mix(in oklab, #cbd5e1, transparent 18%);
  --opt-pop-text: #0f172a;
  --opt-pop-muted: #64748b;
  --opt-section-bg: color-mix(in oklab, #ffffff, #f8fafc 78%);
  --opt-section-border: color-mix(in oklab, #cbd5e1, transparent 28%);
  --opt-input-bg: color-mix(in oklab, #ffffff, #f8fafc 65%);
  --opt-input-border: color-mix(in oklab, #94a3b8, transparent 34%);
  --opt-input-text: #0f172a;
  --opt-code-bg: color-mix(in oklab, #e2e8f0, #f8fafc 62%);
  position: fixed;
  background: var(--opt-pop-bg);
  color: var(--opt-pop-text);
  border: 1px solid var(--opt-pop-border);
  border-radius: 14px;
  box-shadow:
    0 18px 44px rgba(15, 23, 42, 0.26),
    inset 0 0 0 1px color-mix(in oklab, var(--color-primary, #2563eb), transparent 86%);
  padding: 12px;
  width: min(440px, calc(100vw - 24px));
  z-index: 30;
  max-height: min(420px, calc(100vh - 108px));
  overflow: auto;
  overflow-x: hidden;
  transform-origin: top right;
}

:global(body.opsdash-theme-dark .options-pop) {
  --opt-pop-bg: #0f172a;
  --opt-pop-border: color-mix(in oklab, #475569, transparent 16%);
  --opt-pop-text: #e2e8f0;
  --opt-pop-muted: #94a3b8;
  --opt-section-bg: color-mix(in oklab, #111827, #0b1220 74%);
  --opt-section-border: color-mix(in oklab, #475569, transparent 38%);
  --opt-input-bg: color-mix(in oklab, #0b1220, #111827 68%);
  --opt-input-border: color-mix(in oklab, #64748b, transparent 38%);
  --opt-input-text: #e2e8f0;
  --opt-code-bg: color-mix(in oklab, #0b1220, #1e293b 52%);
  box-shadow: 0 14px 28px rgba(2, 6, 23, 0.52);
}

.opt-row {
  display: grid;
  grid-template-columns: minmax(120px, 1fr) minmax(140px, 1fr);
  align-items: center;
  gap: 10px;
  margin-bottom: 6px;
}
.opt-row:last-child {
  margin-bottom: 0;
}

.opt-row--footer {
  grid-template-columns: 1fr;
  justify-content: flex-end;
  margin-top: 4px;
}

.tab-actions {
  display: grid;
  gap: 8px;
}

.tab-actions__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border: 1px solid var(--opt-section-border);
  border-radius: 10px;
  background: var(--opt-section-bg);
}

.tab-actions__meta {
  min-width: 0;
}

.tab-actions__label {
  color: var(--opt-pop-text);
  font-size: 0.83rem;
  font-weight: 600;
}

.tab-actions__hint,
.tab-actions__empty {
  color: var(--opt-pop-muted);
  font-size: 0.76rem;
  line-height: 1.4;
}

.tab-actions__buttons {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.link-btn {
  border: none;
  background: transparent;
  color: var(--color-primary, #2563eb);
  cursor: pointer;
  font-size: 12px;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.link-btn:hover {
  opacity: 0.85;
}

.opt-row label {
  font-size: 0.8rem;
  color: var(--opt-pop-text);
  line-height: 1.35;
}
.opt-row input[type=\"number\"],
.opt-row select,
.opt-row input[type=\"text\"] {
  width: 100%;
  background: var(--opt-input-bg);
  border: 1px solid var(--opt-input-border);
  color: var(--opt-input-text);
  border-radius: 7px;
  padding: 6px 8px;
  font-size: 13px;
}

.opt-row input[type='color'] {
  width: 100%;
  min-height: 30px;
  border-radius: 7px;
  border: 1px solid var(--opt-input-border);
  background: var(--opt-input-bg);
  padding: 3px;
}

.opt-row input[type='checkbox'],
.multi__item input[type='checkbox'],
.taglist__meta input[type='checkbox'] {
  width: 15px !important;
  min-width: 15px;
  max-width: 15px;
  height: 15px !important;
  min-height: 15px;
  max-height: 15px;
  margin: 0;
  display: inline-block;
  flex: 0 0 15px;
  align-self: center;
}

.opt-row textarea {
  width: 100%;
  background: var(--opt-input-bg);
  border: 1px solid var(--opt-input-border);
  color: var(--opt-input-text);
  border-radius: 7px;
  padding: 7px;
  font-size: 13px;
}

.opt-section {
  margin-bottom: 11px;
  padding: 10px;
  border-radius: 10px;
  border: 1px solid var(--opt-section-border);
  background: var(--opt-section-bg);
}

.opt-section:last-child {
  margin-bottom: 0;
}

.opt-section__title {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--opt-pop-muted);
  margin-bottom: 9px;
  font-weight: 700;
}

.colorlist {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.colorlist__item input[type='color'] {
  width: 38px;
  height: 28px;
  padding: 0;
  border: 1px solid var(--opt-input-border);
  background: var(--opt-input-bg);
}

.multi {
  display: grid;
  gap: 6px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.multi__item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}
.multi__item span {
  color: var(--opt-pop-text);
}

.taglist {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.taglist__hint {
  font-size: 12px;
  color: var(--opt-pop-muted);
  line-height: 1.35;
}

.taglist__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  font-size: 13px;
}

.taglist__meta {
  display: flex;
  align-items: center;
  gap: 6px;
}

.taglist__label {
  color: var(--opt-pop-text);
}

.taglist__count {
  font-size: 12px;
  color: var(--opt-pop-muted);
}

.filter-builder {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
}

.filter-builder__row {
  display: grid;
  grid-template-columns: minmax(120px, 1fr) minmax(140px, 1fr) minmax(140px, 1fr) auto;
  gap: 6px;
  align-items: center;
}

.filter-builder__hint {
  font-size: 12px;
  color: var(--opt-pop-muted);
  line-height: 1.35;
}

.filter-builder__hint code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 10px;
  color: var(--opt-pop-text);
  background: var(--opt-code-bg);
  padding: 1px 4px;
  border-radius: 4px;
}
.filter-builder__label,
.filter-builder__input {
  background: var(--opt-input-bg);
  border: 1px solid var(--opt-input-border);
  color: var(--opt-input-text);
  border-radius: 6px;
  padding: 4px 6px;
  font-size: 13px;
}

@media (max-width: 720px) {
  .options-pop {
    width: min(440px, calc(100vw - 16px));
    max-height: min(420px, calc(100vh - 96px));
  }

  .filter-builder__row {
    grid-template-columns: 1fr;
    align-items: stretch;
  }

  .filter-builder__row .ghost {
    justify-self: end;
  }
}
</style>
