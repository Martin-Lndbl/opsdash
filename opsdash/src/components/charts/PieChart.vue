<template>
  <!-- Pie chart: hours by calendar; uses server colors (byId/byName) when available -->
  <canvas ref="cv" class="chart" />
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ctxFor, drawChartTooltip, paintPolishedSlice, themeVar, tint, invert } from '../../services/charts'

const props = defineProps<{
  data?: any
  colorsById: Record<string, string>
  colorsByName?: Record<string, string>
  showLabels?: boolean
  highlightId?: string | null
}>()
const cv = ref<HTMLCanvasElement|null>(null)
let ro: ResizeObserver | null = null
let mo: MutationObserver | null = null
let geometry: { cx: number; cy: number; r: number; segments: Array<{ start: number; end: number; id: string }> } | null = null
const hoverId = ref<string | null>(null)
const hoverPos = ref<{ x: number; y: number } | null>(null)

function draw(){
  const cdata:any = props.data
  const cvEl = cv.value
  if (!cvEl || !cdata) return
  const ctx = ctxFor(cvEl); if(!ctx) return
  const styles = getComputedStyle(cvEl)
  const widgetScale = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-scale')) || 1)
  const widgetSpace = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-space')) || widgetScale)
  const widgetDensity = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-density')) || 1)
  const pieScale = Math.max(0.6, Number.parseFloat(styles.getPropertyValue('--pie-scale')) || 0.85)
  const textScale = widgetScale * widgetDensity * pieScale
  const W=cvEl.clientWidth,H=cvEl.clientHeight,cx=W/2,cy=H/2,r=Math.min(W,H)*(0.35*widgetSpace*pieScale)
  const dataAll=cdata.data||[], labelsAll=cdata.labels||[], idsAll=(cdata.ids||[]), baseColorsAll=(cdata.colors||[])
  const idx:number[]=[]; for (let i=0;i<dataAll.length;i++){ if ((Number(dataAll[i])||0) > 0) idx.push(i) }
  ctx.clearRect(0,0,W,H)
  if (idx.length===0) return
  const data = idx.map(i=>Number(dataAll[i])||0)
  const labels = idx.map(i=>String(labelsAll[i]||''))
  const ids = idx.map(i=>String(idsAll[i]||''))
  const baseColors = idx.map(i=>baseColorsAll[i])
  const total=data.reduce((a,b)=>a+Math.max(0,b),0)||1; let ang=-Math.PI/2
  const baseStroke=themeVar(cvEl, '--line', '#e5e7eb')
  const fg = themeVar(cvEl, '--fg', '#0f172a')
  const bg = themeVar(cvEl, '--bg', '#ffffff')
  ctx.lineWidth=1; ctx.strokeStyle=baseStroke
  const pal=['#60a5fa','#f59e0b','#ef4444','#10b981','#a78bfa','#fb7185','#22d3ee','#f97316']
  if (hoverId.value && !ids.includes(hoverId.value)) {
    hoverId.value = null
  }
  const highlightKey = (props.highlightId ?? hoverId.value) ? String(props.highlightId ?? hoverId.value) : ''
  const hasHighlight = Boolean(highlightKey && ids.includes(highlightKey))
  const segments: Array<{ start: number; end: number; id: string }> = []
  data.forEach((v,i)=>{
    const f=Math.max(0,v)/total,a2=ang+f*2*Math.PI; const name=String(labels?.[i]||''); const id=String(ids?.[i]||'');
    const srvCol = baseColors[i]
    const idCol = props.colorsById?.[id]
    const nameCol = props.colorsByName?.[name]
    let chosen = (idCol || nameCol || srvCol || pal[i%pal.length]) as string
    if (false) chosen = invert(chosen)
    if (false) chosen = tint(chosen)
    const isMatch = hasHighlight && id === highlightKey
    const isDim = hasHighlight && !isMatch
    ctx.save()
    if (isDim) ctx.globalAlpha = 0.25
    paintPolishedSlice(ctx, cx, cy, r, ang, a2, chosen)
    ctx.beginPath();ctx.moveTo(cx,cy);ctx.arc(cx,cy,r,ang,a2);ctx.closePath();
    ctx.lineWidth = isMatch ? 2 : 1
    ctx.strokeStyle = isMatch ? 'rgba(255,255,255,0.9)' : baseStroke
    ctx.stroke()
    ctx.restore()
    if (props.showLabels !== false) {
      const mid=(ang+a2)/2,lx=cx+Math.cos(mid)*(r+12*widgetSpace*pieScale),ly=cy+Math.sin(mid)*(r+12*widgetSpace*pieScale);
      ctx.save()
      ctx.font=`${12 * textScale}px ui-sans-serif,system-ui`
      ctx.fillStyle=fg
      ctx.strokeStyle=bg
      ctx.lineWidth=3
      ctx.textAlign='center'
      ctx.textBaseline='middle'
      const perc = Math.max(0, Number(v)||0) / total * 100;
      const txt=`${labels?.[i]?labels[i]+' ':''}${Number(v).toFixed(2)}h · ${perc.toFixed(1)}%`
      ctx.strokeText(txt,lx,ly)
      ctx.fillText(txt,lx,ly)
      ctx.restore()
    }
    segments.push({ start: ang, end: a2, id })
    ang=a2
  })
  geometry = { cx, cy, r, segments }
  if (hoverId.value && hoverPos.value) {
    const hIdx = ids.indexOf(hoverId.value)
    if (hIdx >= 0) {
      const hVal = data[hIdx]
      const perc = (Math.max(0, hVal) / total) * 100
      const label = labels[hIdx] || ids[hIdx]
      const tooltipText = `${label}: ${hVal.toFixed(1)}h · ${perc.toFixed(1)}%`
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

onMounted(()=>{ draw(); bindObservers(); window.addEventListener('resize', draw) })
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
watch(()=>props.data, ()=> draw(), { deep:true })
watch(()=>props.colorsById, ()=> draw(), { deep:true })
watch(()=>props.showLabels, ()=> draw())
watch(()=>props.highlightId, ()=> draw())

function onMouseMove(event: MouseEvent) {
  const cvEl = cv.value
  if (!cvEl || !geometry) return
  const rect = cvEl.getBoundingClientRect()
  const x = event.clientX - rect.left
  const y = event.clientY - rect.top
  const dx = x - geometry.cx
  const dy = y - geometry.cy
  const dist = Math.sqrt(dx * dx + dy * dy)
  if (!Number.isFinite(dist) || dist > geometry.r) {
    if (hoverId.value || hoverPos.value) {
      hoverId.value = null
      hoverPos.value = null
      draw()
    }
    return
  }
  let ang = Math.atan2(dy, dx)
  if (ang < -Math.PI / 2) ang += Math.PI * 2
  const hit = geometry.segments.find((seg) => ang >= seg.start && ang <= seg.end)
  const nextId = hit ? hit.id : null
  hoverPos.value = { x, y }
  if (nextId !== hoverId.value) {
    hoverId.value = nextId
  }
  draw()
}

function onMouseLeave() {
  if (hoverId.value || hoverPos.value) {
    hoverId.value = null
    hoverPos.value = null
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
</script>

<style scoped>
.chart {
  cursor: pointer;
}
</style>
