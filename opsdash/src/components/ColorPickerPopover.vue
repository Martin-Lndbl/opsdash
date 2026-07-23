<template>
  <div class="cpp-wrap" data-color-popover>
    <button
      type="button"
      class="cpp-trigger"
      :disabled="disabled"
      :aria-expanded="open"
      :aria-label="ariaLabel"
      @click.stop="toggle"
    >
      <span class="cpp-dot" :style="{ background: modelValue }" />
      <slot>Color</slot>
    </button>

    <div
      v-if="open"
      ref="popoverRef"
      class="cpp-popover"
      tabindex="-1"
      @keydown.esc.prevent="close"
    >
      <div class="cpp-grid" role="group" aria-label="Preset colors">
        <button
          v-for="swatch in palette"
          :key="swatch"
          type="button"
          class="cpp-swatch"
          :class="{ active: modelValue?.toUpperCase() === swatch.toUpperCase() }"
          :style="{ background: swatch }"
          :title="swatch"
          :aria-label="swatch"
          @click="pick(swatch)"
        />
      </div>
      <label class="cpp-custom">
        <span>Custom</span>
        <input
          type="color"
          class="cpp-custom-input"
          :value="modelValue"
          @input="onCustom"
        />
      </label>
      <button
        v-if="allowClear"
        type="button"
        class="cpp-clear"
        @click="clear"
      >{{ clearLabel }}</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: string
  palette?: string[]
  ariaLabel?: string
  disabled?: boolean
  allowClear?: boolean
  clearLabel?: string
}>(), {
  palette: () => ['#2563EB', '#F97316', '#10B981', '#A855F7', '#EC4899', '#14B8A6', '#F59E0B', '#6366F1', '#EF4444', '#64748B', '#000000', '#ffffff'],
  ariaLabel: 'Choose color',
  disabled: false,
  allowClear: false,
  clearLabel: 'Follow global',
})

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

const open = ref(false)
const popoverRef = ref<HTMLElement | null>(null)

function toggle() {
  if (props.disabled) return
  open.value = !open.value
  if (open.value) nextTick(() => popoverRef.value?.focus())
}

function close() { open.value = false }

function pick(color: string) {
  emit('update:modelValue', color)
  close()
}

function clear() {
  emit('update:modelValue', '')
  close()
}

function onCustom(e: Event) {
  const v = (e.target as HTMLInputElement).value
  if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(v)) emit('update:modelValue', v)
}

function clickAway(e: MouseEvent) {
  if (!(e.target as HTMLElement).closest('[data-color-popover]')) close()
}

onMounted(() => document.addEventListener('click', clickAway))
onBeforeUnmount(() => document.removeEventListener('click', clickAway))
</script>

<style scoped>
.cpp-wrap { position: relative; display: inline-flex; }

.cpp-trigger {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 8px; border-radius: 7px; cursor: pointer;
  border: 1px solid color-mix(in oklab, var(--line), transparent 40%);
  background: transparent; font: inherit; font-size: 11px; font-weight: 700; color: inherit;
}
.cpp-trigger:hover { background: color-mix(in oklab, var(--brand), transparent 92%); }
.cpp-trigger:disabled { opacity: .5; cursor: not-allowed; }

.cpp-dot {
  width: 14px; height: 14px; border-radius: 50%;
  border: 1px solid rgba(0,0,0,.18); flex-shrink: 0;
}

.cpp-popover {
  position: absolute; top: calc(100% + 6px); left: 0; z-index: 200;
  display: flex; flex-direction: column; gap: 8px; padding: 10px;
  border-radius: 10px; border: 1px solid color-mix(in oklab, var(--line), transparent 10%);
  background: var(--card); box-shadow: 0 6px 18px rgba(0,0,0,.18); min-width: 148px;
}
.cpp-popover:focus-visible { outline: 2px solid color-mix(in oklab, var(--brand), transparent 40%); }

.cpp-grid { display: grid; grid-template-columns: repeat(6, 20px); gap: 5px; }

.cpp-swatch {
  width: 20px; height: 20px; border-radius: 50%; cursor: pointer; padding: 0;
  border: 1px solid rgba(0,0,0,.15);
}
.cpp-swatch.active { box-shadow: 0 0 0 2px var(--brand); }
.cpp-swatch:focus-visible { outline: 2px solid color-mix(in oklab, var(--brand), transparent 40%); outline-offset: 2px; }

.cpp-custom {
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px; font-size: 11px; color: color-mix(in oklab, var(--text), transparent 35%);
}
.cpp-custom-input {
  width: 32px; height: 22px; border-radius: 4px; padding: 1px; cursor: pointer;
  border: 1px solid color-mix(in oklab, var(--line), transparent 30%); background: none;
}
</style>
