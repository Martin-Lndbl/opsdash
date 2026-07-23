<template>
  <canvas ref="cv" class="chart" />
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ctxFor, paintPolishedBar, themeVar } from '../../services/charts'

const props = defineProps<{
  data?: { labels?: string[]; data?: number[]; colors?: string[] }
  showLabels?: boolean
  xLabel?: string
  yLabel?: string
}>()

const cv = ref<HTMLCanvasElement | null>(null)
let ro: ResizeObserver | null = null
let mo: MutationObserver | null = null

function formatHours(value: number): string {
  const normalized = Math.max(0, Number(value) || 0)
  return (Math.round(normalized * 10) / 10).toFixed(1)
}

function draw() {
  const cvEl = cv.value
  if (!cvEl || !props.data) return
  const ctx = ctxFor(cvEl)
  if (!ctx) return
  const styles = getComputedStyle(cvEl)
  const widgetScale = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-scale')) || 1)
  const widgetSpace = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-space')) || widgetScale)
  const widgetDensity = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-density')) || 1)
  const textScale = widgetScale * widgetDensity
  const padSpace = widgetDensity < 1 ? widgetSpace / widgetDensity : widgetSpace
  const W = cvEl.clientWidth
  const H = cvEl.clientHeight
  const pad = 28 * padSpace
  const x0 = pad * 1.4
  const y0 = H - pad
  const x1 = W - pad
  const line = themeVar(cvEl, '--line', '#e5e7eb')
  const fg = themeVar(cvEl, '--fg', '#0f172a')
  ctx.clearRect(0, 0, W, H)
  ctx.fillStyle = fg
  ctx.font = `${12 * textScale}px ui-sans-serif,system-ui`
  const xLabel = String(props.xLabel ?? '').trim()
  if (xLabel) {
    const tw = ctx.measureText(xLabel).width
    const y = Math.min(H - 6 * textScale, y0 + 18 * textScale)
    ctx.fillText(xLabel, x0 + (x1 - x0) / 2 - tw / 2, y)
  }
  const yLabel = String(props.yLabel ?? '').trim()
  if (yLabel) {
    ctx.fillText(yLabel, 6 * padSpace, pad * 0.8)
  }

  const labels = (props.data.labels || []).map((label) => String(label ?? ''))
  const data = (props.data.data || []).map((val) => Math.max(0, Number(val) || 0))
  const colors = Array.isArray(props.data.colors) ? props.data.colors : []
  if (!labels.length || !data.length) return
  const max = Math.max(1, ...data)
  const n = labels.length
  const gap = 8 * widgetSpace
  const bw = Math.max(6 * widgetSpace, (x1 - x0 - gap * (n + 1)) / n)
  const chartScale = (y0 - pad) / max
  labels.forEach((label, i) => {
    const val = data[i] ?? 0
    const h = Math.max(0, val * chartScale)
    const x = x0 + gap + i * (bw + gap)
    const y = y0 - h
    paintPolishedBar(ctx, x, y, bw, h, colors[i] || '#93c5fd')
    ctx.fillStyle = fg
    ctx.font = `${12 * textScale}px ui-sans-serif,system-ui`
    if (bw > 26) {
      const tw = ctx.measureText(label).width
      ctx.fillText(label, x + bw / 2 - tw / 2, y0 + 14 * textScale)
    }
    if (props.showLabels !== false && h > 14 * textScale && bw > 22 * textScale && val > 0.01) {
      const labelVal = `${formatHours(val)}h`
      const tw = ctx.measureText(labelVal).width
      ctx.fillText(labelVal, x + bw / 2 - tw / 2, y + h / 2 + 4 * textScale)
    }
  })
}

function bindObservers() {
  try {
    ro = new ResizeObserver(() => draw())
    if (cv.value) ro.observe(cv.value)
  } catch (_) {}
  try {
    const target = cv.value?.closest('.layout-item') || cv.value?.parentElement
    if (target) {
      mo = new MutationObserver(() => draw())
      mo.observe(target, { attributes: true, attributeFilter: ['style', 'class'] })
    }
  } catch (_) {}
}

onMounted(() => {
  draw()
  bindObservers()
  window.addEventListener('resize', draw)
})
onBeforeUnmount(() => {
  try { window.removeEventListener('resize', draw) } catch (_) {}
  try { ro && cv.value && ro.unobserve(cv.value) } catch (_) {}
  try { mo && mo.disconnect() } catch (_) {}
  ro = null
  mo = null
})
watch(() => props.data, () => draw(), { deep: true })
watch(() => props.showLabels, () => draw())
</script>
