<template>
  <canvas ref="cv" class="chart" />
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ctxFor, drawChartTooltip, paintPolishedBar, themeVar } from '../../services/charts'
import { globalAppBg, activeThemeMode } from '../../../composables/useGlobalPreferences'

const props = defineProps<{
  data?: {
    labels?: string[]
    series?: Array<{ id?: string; name?: string; label?: string; color?: string; data?: number[] }>
  }
  showLabels?: boolean
  xLabel?: string
  yLabel?: string
  colorStyle?: 'fill' | 'outline'
  colorTint?: number
}>()

const cv = ref<HTMLCanvasElement | null>(null)
let ro: ResizeObserver | null = null
let mo: MutationObserver | null = null
let geometry: Array<{ x: number; y: number; w: number; h: number; label: string; value: number }> = []
const hoverPos = ref<{ x: number; y: number } | null>(null)
let hoverInfo: { label: string; value: number } | null = null

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
  const series = Array.isArray(props.data.series) ? props.data.series : []
  if (!labels.length || !series.length) return
  const values: number[] = []
  series.forEach((row) => {
    const data = Array.isArray(row.data) ? row.data : []
    data.forEach((val) => values.push(Math.max(0, Number(val) || 0)))
  })
  const max = Math.max(1, ...values)
  const groupCount = labels.length
  const seriesCount = Math.max(1, series.length)
  const groupGap = 10 * widgetSpace
  const innerGap = 4 * widgetSpace
  const groupWidth = Math.max(12 * widgetSpace, (x1 - x0 - groupGap * (groupCount + 1)) / groupCount)
  const barWidth = Math.max(4 * widgetSpace, (groupWidth - innerGap * (seriesCount - 1)) / seriesCount)
  const chartScale = (y0 - pad) / max

  geometry = []
  const bg = themeVar(cvEl, '--bg', '#ffffff')
  labels.forEach((label, groupIdx) => {
    const groupX = x0 + groupGap + groupIdx * (groupWidth + groupGap)
    series.forEach((row, seriesIdx) => {
      const val = Math.max(0, Number(row?.data?.[groupIdx] ?? 0))
      const h = Math.max(0, val * chartScale)
      const x = groupX + seriesIdx * (barWidth + innerGap)
      const y = y0 - h
      paintPolishedBar(ctx, x, y, barWidth, h, row?.color || '#93c5fd', props.colorStyle ?? 'fill', 'bar', (props.colorTint ?? 0) / 100)
      const seriesLabel = String(row?.name ?? row?.label ?? row?.id ?? '')
      const tooltipLabel = seriesLabel ? `${label} · ${seriesLabel}` : label
      geometry.push({ x, y, w: barWidth, h, label: tooltipLabel, value: val })
      if (props.showLabels !== false && h > 14 * textScale && barWidth > 20 * textScale && val > 0.01) {
        ctx.fillStyle = fg
        ctx.font = `${12 * textScale}px ui-sans-serif,system-ui`
        const labelVal = `${formatHours(val)}h`
        const tw = ctx.measureText(labelVal).width
        ctx.fillText(labelVal, x + barWidth / 2 - tw / 2, y + h / 2 + 4 * textScale)
      }
    })
    if (groupWidth > 26 * textScale) {
      ctx.fillStyle = fg
      ctx.font = `${12 * textScale}px ui-sans-serif,system-ui`
      const tw = ctx.measureText(label).width
      ctx.fillText(label, groupX + groupWidth / 2 - tw / 2, y0 + 14 * textScale)
    }
  })
  if (hoverInfo && hoverPos.value) {
    drawChartTooltip(ctx, {
      cursorX: hoverPos.value.x,
      cursorY: hoverPos.value.y,
      canvasWidth: W,
      canvasHeight: H,
      text: `${hoverInfo.label}: ${hoverInfo.value.toFixed(1)}h`,
      bg,
      fg,
      scale: textScale,
    })
  }
}

function onMouseMove(event: MouseEvent) {
  const cvEl = cv.value
  if (!cvEl) return
  const rect = cvEl.getBoundingClientRect()
  const x = event.clientX - rect.left
  const y = event.clientY - rect.top
  let nextInfo: { label: string; value: number } | null = null
  for (let i = geometry.length - 1; i >= 0; i -= 1) {
    const seg = geometry[i]
    if (x >= seg.x && x <= seg.x + seg.w && y >= seg.y && y <= seg.y + seg.h) {
      nextInfo = { label: seg.label, value: seg.value }
      break
    }
  }
  hoverPos.value = { x, y }
  hoverInfo = nextInfo
  draw()
}
function onMouseLeave() {
  if (hoverInfo || hoverPos.value) {
    hoverInfo = null
    hoverPos.value = null
    draw()
  }
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
  const el = cv.value
  if (el) {
    el.addEventListener('mousemove', onMouseMove)
    el.addEventListener('mouseleave', onMouseLeave)
  }
})
onBeforeUnmount(() => {
  try { window.removeEventListener('resize', draw) } catch (_) {}
  try { ro && cv.value && ro.unobserve(cv.value) } catch (_) {}
  try { mo && mo.disconnect() } catch (_) {}
  try {
    const el = cv.value
    if (el) {
      el.removeEventListener('mousemove', onMouseMove)
      el.removeEventListener('mouseleave', onMouseLeave)
    }
  } catch (_) {}
  ro = null
  mo = null
})
watch(() => props.data, () => draw(), { deep: true })
watch(() => props.showLabels, () => draw())
watch(() => props.colorStyle, () => draw())
watch(() => props.colorTint, () => draw())
watch([globalAppBg, activeThemeMode], () => draw())

</script>
