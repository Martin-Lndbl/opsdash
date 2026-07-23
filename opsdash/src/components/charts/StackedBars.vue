<template>
  <!-- Stacked bars: hours per day stacked by calendar -->
  <canvas ref="cv" class="chart" />
  </template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ctxFor, drawChartTooltip, paintPolishedBar, themeVar } from '../../services/charts'
import { formatDateOnly, getFirstDayOfWeek, parseDateKey } from '../../services/dateTime'
import { globalAppBg, activeThemeMode } from '../../../composables/useGlobalPreferences'

const props = defineProps<{ stacked?: any, colorsById: Record<string,string>, showLabels?: boolean, highlightId?: string | null, colorStyle?: 'fill' | 'outline'
  colorTint?: number }>()
const cv = ref<HTMLCanvasElement|null>(null)
let ro: ResizeObserver | null = null
let mo: MutationObserver | null = null
let geometry: { segments: Array<{ x: number; y: number; width: number; height: number; id: string; label: string; value: number; forecast?: boolean }> } | null = null
const hoverId = ref<string | null>(null)
const hoverPos = ref<{ x: number; y: number } | null>(null)
let hoverInfo: { label: string; value: number; forecast?: boolean } | null = null

function drawOutlinedText(
  ctx: CanvasRenderingContext2D,
  text: string,
  x: number,
  y: number,
  options?: { align?: CanvasTextAlign; baseline?: CanvasTextBaseline; font?: string; fill?: string; stroke?: string },
) {
  ctx.save()
  if (options?.font) ctx.font = options.font
  if (options?.align) ctx.textAlign = options.align
  if (options?.baseline) ctx.textBaseline = options.baseline
  ctx.lineWidth = 3
  ctx.strokeStyle = options?.stroke || 'rgba(0,0,0,0.55)'
  ctx.strokeText(text, x, y)
  ctx.fillStyle = options?.fill || '#ffffff'
  ctx.fillText(text, x, y)
  ctx.restore()
}

function hexToRgb(hex:string){ const m=/^#?([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i.exec(hex||''); if(!m) return null; return {r:parseInt(m[1],16), g:parseInt(m[2],16), b:parseInt(m[3],16)} }
function textColorFor(bg:string, fallback:string): string {
  const rgb = hexToRgb(bg)
  if (!rgb) return fallback
  const lum = (0.2126*rgb.r + 0.7152*rgb.g + 0.0722*rgb.b)/255
  return lum < 0.55 ? '#ffffff' : fallback
}

function formatHours(value: number): string {
  const normalized = Math.max(0, Number(value) || 0)
  return (Math.round(normalized * 10) / 10).toFixed(1)
}

function lightenColor(hex: string, factor = 0.5): string {
  const rgb = hexToRgb(hex)
  if (!rgb) return hex
  const mix = Math.max(0, Math.min(1, factor))
  const r = Math.round(rgb.r + (255 - rgb.r) * mix)
  const g = Math.round(rgb.g + (255 - rgb.g) * mix)
  const b = Math.round(rgb.b + (255 - rgb.b) * mix)
  return `rgb(${r},${g},${b})`
}

function darkenColor(hex: string, factor = 0.5): string {
  const rgb = hexToRgb(hex)
  if (!rgb) return hex
  const mix = Math.max(0, Math.min(1, factor))
  const r = Math.round(rgb.r * (1 - mix))
  const g = Math.round(rgb.g * (1 - mix))
  const b = Math.round(rgb.b * (1 - mix))
  return `rgb(${r},${g},${b})`
}

type ForecastVariant = 'future' | 'mixed'

function drawForecastOverlay(
  ctx: CanvasRenderingContext2D,
  opts: { x: number; y: number; width: number; height: number; baseColor: string; variant: ForecastVariant; alphaScale?: number },
) {
  const { x, y, width, height, baseColor, variant } = opts
  const isMixed = variant === 'mixed'
  const alphaScale = Math.max(0, Math.min(1, Number(opts.alphaScale ?? 1)))

  const baseInset = Math.min(width * (isMixed ? 0.35 : 0.22), 6)
  const innerWidth = Math.max(2, isMixed ? width * 0.45 : width - baseInset * 2)
  const innerX = isMixed ? x + width - innerWidth - baseInset : x + (width - innerWidth) / 2
  const fillColor = lightenColor(baseColor, isMixed ? 0.6 : 0.45)
  const strokeColor = darkenColor(baseColor, isMixed ? 0.4 : 0.35)

  ctx.save()
  ctx.fillStyle = fillColor
  ctx.globalAlpha = (isMixed ? 0.12 : 0.16) * alphaScale
  ctx.fillRect(innerX, y, innerWidth, height)
  ctx.restore()

  ctx.save()
  ctx.strokeStyle = strokeColor
  ctx.lineWidth = isMixed ? 1.25 : 1.5
  ctx.globalAlpha = (isMixed ? 0.6 : 0.75) * alphaScale
  ctx.setLineDash([4, 4])
  ctx.strokeRect(innerX, y, innerWidth, height)
  ctx.restore()

  ctx.save()
  ctx.strokeStyle = strokeColor
  ctx.globalAlpha = (isMixed ? 0.35 : 0.45) * alphaScale
  ctx.lineWidth = 1
  ctx.beginPath()
  ctx.moveTo(innerX, y)
  ctx.lineTo(innerX + innerWidth, y)
  ctx.stroke()
  ctx.restore()
}

function draw(){
  const cvEl = cv.value; if (!cvEl) return
  const ctx = ctxFor(cvEl); if(!ctx) return
  const styles = getComputedStyle(cvEl)
  const widgetScale = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-scale')) || 1)
  const widgetSpace = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-space')) || widgetScale)
  const widgetDensity = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-density')) || 1)
  const stackedScale = Math.max(0.6, Number.parseFloat(styles.getPropertyValue('--stacked-scale')) || 0.85)
  const textScale = widgetScale * widgetDensity * stackedScale
  const padSpace = widgetDensity < 1 ? widgetSpace / widgetDensity : widgetSpace
  const labelFont = `${11 * textScale}px ui-sans-serif,system-ui`
  const W=cvEl.clientWidth,H=cvEl.clientHeight,pad=28*padSpace,x0=pad*1.4,y0=H-pad,x1=W-pad
  const line=themeVar(cvEl, '--line', '#e5e7eb')
  const fg=themeVar(cvEl, '--fg', '#0f172a')
  const bg=themeVar(cvEl, '--bg', '#ffffff')
  ctx.clearRect(0,0,W,H)

  const stacked:any = props.stacked
  if (stacked && stacked.labels && stacked.series) {
    const segments: Array<{ x: number; y: number; width: number; height: number; id: string; label: string; value: number; forecast?: boolean }> = []
    let labels:string[] = stacked.labels||[]
    let series:any[] = stacked.series||[]
    // Reorder to start with the user's week start if labels represent a 7-day week
    const reordered = reorderToWeekStart(labels, series)
    labels = reordered.labels
    series = reordered.series
    const seriesIds = series.map((entry) => String(entry?.id ?? ''))
    if (hoverId.value && !seriesIds.includes(hoverId.value)) {
      hoverId.value = null
    }
    const highlightKey = (props.highlightId ?? hoverId.value) ? String(props.highlightId ?? hoverId.value) : ''
    const hasHighlight = Boolean(highlightKey && seriesIds.includes(highlightKey))
    const actualTotals = labels.map((_,i)=> series.reduce((a,s)=>a+Math.max(0, Number(s.data?.[i]||0)),0))
    const forecastTotals = labels.map((_,i)=> series.reduce((a,s)=>a+Math.max(0, Number(s.forecast?.[i]||0)),0))
    const combinedTotals = actualTotals.map((val, idx)=> val + forecastTotals[idx])
    const max = Math.max(1, ...combinedTotals)
    const n=labels.length||1
    const gBase=8*widgetSpace
    const bwBase=Math.max(6*widgetSpace,(x1-x0-gBase*(n+1))/n)
    const g=gBase*stackedScale
    const bw=Math.max(4*widgetSpace*stackedScale,bwBase*stackedScale)
    const chartWidth = bw*n + g*(n+1)
    const xStart = x0 + Math.max(0, (x1 - x0 - chartWidth) / 2)
    const chartScale=(y0-pad)/max*stackedScale
    let sumActual = 0
    let sumForecast = 0
    labels.forEach((_,i)=>{
      const x = xStart+g+i*(bw+g)
      let y=y0
      let colActual = 0
      const segmentLabels: Array<{
        y: number
        text: string
        align: CanvasTextAlign
        textX: number
        lineStartX: number
        lineEndX: number
        alpha: number
      }> = []
      series.forEach((s)=>{
        const v = Math.max(0, Number(s.data?.[i]||0))
        const h = v*chartScale
        y -= h
        const id = String(s.id ?? '')
        const col = props.colorsById[s.id] || s.color || '#93c5fd'
        const isMatch = hasHighlight && id === highlightKey
        const isDim = hasHighlight && !isMatch
        if (h>0.5) {
          ctx.save()
          if (isDim) ctx.globalAlpha = 0.25
          paintPolishedBar(ctx, x, y, bw, h, col, props.colorStyle ?? 'fill', 'segment', (props.colorTint ?? 0) / 100)
          if (isMatch) {
            ctx.strokeStyle = 'rgba(255,255,255,0.9)'
            ctx.lineWidth = 1.5
            ctx.strokeRect(x, y, bw, h)
          }
          ctx.restore()
        }
        if (props.showLabels !== false && v>0.01) {
          const label = formatHours(v)
          ctx.font = labelFont
          const tw = ctx.measureText(label).width
          const canInside = h>14*textScale && bw>tw + 6*textScale
          if (canInside) {
            ctx.save()
            if (isDim) ctx.globalAlpha = 0.25
            ctx.fillStyle = textColorFor(col, fg)
            ctx.font = labelFont
            ctx.fillText(label, x + bw/2 - tw/2, y + h/2 + 4)
            ctx.restore()
          } else if (h>0.5) {
            const mid = y + h/2
            const margin = 6*widgetSpace
            const rightX = x + bw + margin
            const leftX = x - margin
            const useRight = rightX + tw <= x1
            const textX = useRight ? rightX : leftX
            const align: CanvasTextAlign = useRight ? 'left' : 'right'
            const lineStartX = useRight ? x + bw : x
            const lineEndX = useRight ? textX - 4*widgetSpace : textX + 4*widgetSpace
            segmentLabels.push({
              y: mid,
              text: label,
              align,
              textX,
              lineStartX,
              lineEndX,
              alpha: isDim ? 0.25 : 1,
            })
          }
        }
        if (h > 0.5) {
          segments.push({ x, y, width: bw, height: h, id, label: String(s.name ?? s.label ?? id), value: v })
        }
        colActual += v
      })
      const yAfterActual = y
      let yForecast = yAfterActual
      let colForecast = 0
      series.forEach((s)=>{
        const vf = Math.max(0, Number(s.forecast?.[i] || 0))
        if (vf <= 0) return
        const hf = vf * chartScale
        yForecast -= hf
        colForecast += vf
        if (hf <= 0.5) {
          return
        }
        const id = String(s.id ?? '')
        const baseCol = props.colorsById[s.id] || s.color || '#93c5fd'
        const isMatch = hasHighlight && id === highlightKey
        const isDim = hasHighlight && !isMatch
        drawForecastOverlay(ctx, {
          x,
          y: yForecast,
          width: bw,
          height: hf,
          baseColor: baseCol,
          variant: colActual > 0.01 ? 'mixed' : 'future',
          alphaScale: isDim ? 0.25 : 1,
        })
        segments.push({
          x,
          y: yForecast,
          width: bw,
          height: hf,
          id,
          label: String(s.name ?? s.label ?? id),
          value: vf,
          forecast: true,
        })
      })
      if (props.showLabels !== false && segmentLabels.length) {
        const spacing = 12 * textScale
        const minY = pad + 6 * textScale
        const maxY = y0 - 6 * textScale
        segmentLabels.sort((a, b) => a.y - b.y)
        segmentLabels.forEach((label, idx) => {
          if (idx === 0) {
            label.y = Math.max(label.y, minY)
          } else {
            label.y = Math.max(label.y, segmentLabels[idx - 1].y + spacing)
          }
        })
        const overflow = segmentLabels[segmentLabels.length - 1].y - maxY
        if (overflow > 0) {
          segmentLabels.forEach((label) => {
            label.y -= overflow
          })
        }
        const underflow = minY - segmentLabels[0].y
        if (underflow > 0) {
          segmentLabels.forEach((label) => {
            label.y += underflow
          })
        }
        ctx.save()
        ctx.font = labelFont
        ctx.textBaseline = 'middle'
        segmentLabels.forEach((label) => {
          ctx.save()
          ctx.globalAlpha = label.alpha
          ctx.strokeStyle = line
          ctx.lineWidth = 1
          ctx.beginPath()
          ctx.moveTo(label.lineStartX, label.y)
          ctx.lineTo(label.lineEndX, label.y)
          ctx.stroke()
          ctx.fillStyle = fg
          ctx.textAlign = label.align
          ctx.fillText(label.text, label.textX, label.y)
          ctx.restore()
        })
        ctx.restore()
      }
      sumActual += colActual
      sumForecast += colForecast
      ctx.fillStyle=fg; ctx.font=`${12 * textScale}px ui-sans-serif,system-ui`
      const t=weekday(String(labels[i]||''))
      if (bw>26*textScale){ const tw=ctx.measureText(t).width; ctx.fillText(t, x+bw/2-tw/2, y0+14*textScale) }
      const labelValue = colForecast>0.01
        ? `~${formatHours(colActual + colForecast)}h`
        : colActual>0.01
          ? `${formatHours(colActual)}h`
          : ''
      if (props.showLabels !== false && labelValue) {
        drawOutlinedText(ctx, labelValue, x + bw/2, Math.max(pad + 4 * textScale, yForecast - 2), {
          align: 'center',
          baseline: 'bottom',
          font: `${11 * textScale}px ui-sans-serif,system-ui`,
          fill: fg,
          stroke: bg,
        })
      }
    })
    const totalLabel = sumForecast>0.01
      ? `~${formatHours(sumActual + sumForecast)}h expected`
      : `${formatHours(sumActual)}h total`
    if (props.showLabels !== false) {
      drawOutlinedText(ctx, totalLabel, x1 - 4, pad + 4 * textScale, {
        align: 'right',
        baseline: 'top',
        font: `${12 * textScale}px ui-sans-serif,system-ui`,
        fill: fg,
        stroke: bg,
      })
    }
    geometry = { segments }
    if (hoverInfo && hoverPos.value) {
      const tooltipText = hoverInfo.forecast
        ? `${hoverInfo.label}: ~${hoverInfo.value.toFixed(1)}h (forecast)`
        : `${hoverInfo.label}: ${hoverInfo.value.toFixed(1)}h`
      drawChartTooltip(ctx, {
        cursorX: hoverPos.value.x,
        cursorY: hoverPos.value.y,
        canvasWidth: W,
        canvasHeight: H,
        text: tooltipText,
        bg: bg,
        fg: fg,
        scale: textScale,
      })
    }
    return
  }
  geometry = null
  return
}

function bindObservers() {
  try {
    ro = new ResizeObserver(() => draw())
    if (cv.value) ro.observe(cv.value)
  } catch(_) {}
  try {
    const target = cv.value?.closest('.layout-item') || cv.value?.parentElement
    if (target) {
      mo = new MutationObserver(() => draw())
      mo.observe(target, { attributes: true, attributeFilter: ['style', 'class'] })
    }
  } catch(_) {}
}

onMounted(()=>{ draw(); bindObservers(); window.addEventListener('resize', draw) })
onBeforeUnmount(() => {
  try { window.removeEventListener('resize', draw) } catch(_) {}
  try { ro && cv.value && ro.unobserve(cv.value) } catch(_) {}
  try { mo && mo.disconnect() } catch(_) {}
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
watch(()=>props.stacked, ()=> draw(), { deep:true })
watch(()=>props.colorsById, ()=> draw(), { deep:true })
watch(()=>props.showLabels, ()=> draw())
watch(()=>props.highlightId, ()=> draw())
watch(()=>props.colorStyle, ()=> draw())
watch(()=>props.colorTint, ()=> draw())
watch([globalAppBg, activeThemeMode], () => draw())

function onMouseMove(event: MouseEvent) {
  const cvEl = cv.value
  if (!cvEl || !geometry) return
  const rect = cvEl.getBoundingClientRect()
  const x = event.clientX - rect.left
  const y = event.clientY - rect.top
  let nextId: string | null = null
  let nextInfo: { label: string; value: number; forecast?: boolean } | null = null
  for (let i = geometry.segments.length - 1; i >= 0; i -= 1) {
    const seg = geometry.segments[i]
    if (x >= seg.x && x <= seg.x + seg.width && y >= seg.y && y <= seg.y + seg.height) {
      nextId = seg.id
      nextInfo = { label: seg.label, value: seg.value, forecast: seg.forecast }
      break
    }
  }
  hoverPos.value = { x, y }
  hoverId.value = nextId
  hoverInfo = nextInfo
  draw()
}

function onMouseLeave() {
  if (hoverId.value || hoverPos.value) {
    hoverId.value = null
    hoverPos.value = null
    hoverInfo = null
    draw()
  }
}

watch(
  () => cv.value,
  (el, prev) => {
    if (prev) {
      prev.removeEventListener('mousemove', onMouseMove)
      prev.removeEventListener('mouseleave', onMouseLeave)
    }
    if (el) {
      el.addEventListener('mousemove', onMouseMove)
      el.addEventListener('mouseleave', onMouseLeave)
    }
  },
  { immediate: true },
)

// Convert a YYYY-MM-DD label to localized weekday short name; otherwise keep as-is
// If labels represent 7 dates, reorder so week start comes first; reorder series.data accordingly
function reorderToWeekStart(labels: string[], series: any[]): { labels: string[]; series: any[] } {
  if (!Array.isArray(labels) || labels.length !== 7) return { labels, series }
  const dates = labels.map(s=> parseDate(s))
  if (dates.some(d=> !d)) return { labels, series }
  // Build mapping from original index to ISO day (Mon=1..Sun=7)
  const iso = dates.map(d=> isoDay(d!))
  // If already week-start sequence, keep as-is
  const firstDay = getFirstDayOfWeek()
  const want: number[] = []
  for (let i = 0; i < 7; i += 1) {
    const dow = (firstDay + i) % 7
    want.push(dow === 0 ? 7 : dow)
  }
  if (iso.join(',') === want.join(',')) return { labels, series }
  // Compute permutation indices for week-start order
  // Find indices for each iso day 1..7
  const idxByIso: Record<number, number> = {}
  iso.forEach((v,i)=>{ idxByIso[v] = i })
  if (!want.every(v=> v in idxByIso)) return { labels, series }
  const perm = want.map(v=> idxByIso[v])
  const labelsR = perm.map(i=> labels[i])
  const seriesR = (series || []).map((s) => {
    const dataSrc = Array.isArray(s.data) ? s.data : []
    const forecastSrc = Array.isArray((s as any).forecast) ? (s as any).forecast : null
    const data = perm.map((idx) => dataSrc[idx] ?? 0)
    const forecast = forecastSrc ? perm.map((idx) => forecastSrc[idx] ?? 0) : undefined
    const next: any = { ...s, data }
    if (forecast) {
      next.forecast = forecast
    }
    return next
  })
  return { labels: labelsR, series: seriesR }
}

// Convert a YYYY-MM-DD label to German weekday short name; otherwise keep as-is
function weekday(s: string): string {
  const formatted = formatDateOnly(s, { weekday: 'short' })
  return formatted || s
}

function parseDate(s: string): Date | null {
  return parseDateKey(s)
}

function isoDay(d: Date): number {
  // JS: 0=Sun..6=Sat -> ISO: 1=Mon..7=Sun
  const n = d.getUTCDay()
  return n === 0 ? 7 : n
}
</script>

<style scoped>
.chart {
  cursor: pointer;
}
</style>
