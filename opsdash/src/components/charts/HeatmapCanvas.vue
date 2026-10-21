<template>
  <!-- Heatmap canvas: renders a 24×7 matrix using a muted paper→steel gradient -->
  <canvas ref="cv" class="chart" />
</template>

<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, nextTick } from 'vue'
import { ctxFor, heatColor, hexToRgb, rgbToHex, themeVar } from '../../services/charts'
import { globalAppBg, activeThemeMode } from '../../../composables/useGlobalPreferences'

// Props: hod contains weekday labels (rows), hour labels (cols), and a matrix of hour sums
const props = defineProps<{ hod?: { dows:string[], hours:string[]|number[], matrix:number[][] }, baseColor?: string }>()
const cv = ref<HTMLCanvasElement|null>(null)
let ro: ResizeObserver | null = null

function draw(){
  const cvEl = cv.value, data = props.hod
  if (!cvEl) return
  if (!data) {
    // Helpful debug when nothing renders
    console.info('[opsdash] heatmap: no data provided')
    return
  }
  const ctx = ctxFor(cvEl); if(!ctx) { return }
  const styles = getComputedStyle(cvEl)
  const widgetScale = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-scale')) || 1)
  const widgetSpace = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-space')) || widgetScale)
  const widgetDensity = Math.max(0.5, Number.parseFloat(styles.getPropertyValue('--widget-density')) || 1)
  const textScale = widgetScale * widgetDensity
  const padSpace = widgetDensity < 1 ? widgetSpace / widgetDensity : widgetSpace
  const W=cvEl.clientWidth,H=cvEl.clientHeight
  const rows=data.dows||[], cols=data.hours||[], m=data.matrix||[]
  ctx.clearRect(0,0,W,H)
  const pad=36*padSpace, x0=pad, y0=pad, x1=W-pad, y1=H-pad
  const cw=(x1-x0)/Math.max(1,cols.length), rh=(y1-y0)/Math.max(1,rows.length)
  const vmax = Math.max(0, ...m.flat()) || 1
  const baseColor = typeof props.baseColor === 'string' ? props.baseColor.trim() : ''
  const lowColor = themeVar(cvEl, '--heatmap-low', '#e0f2fe')
  const lowRgb = hexToRgb(lowColor)
  const baseRgb = hexToRgb(baseColor)
  const colorFor = (ratio: number) => {
    const clamp = Math.max(0, Math.min(1, ratio))
    const tt = Math.pow(clamp, 0.6)
    if (!lowRgb || !baseRgb) return heatColor(tt)
    const mix = (a: number, b: number, p: number) => Math.round(a + (b - a) * p)
    return rgbToHex(mix(lowRgb.r, baseRgb.r, tt), mix(lowRgb.g, baseRgb.g, tt), mix(lowRgb.b, baseRgb.b, tt))
  }
  if (rows.length && cols.length) {
    console.info('[opsdash] heatmap draw', { rows: rows.length, cols: cols.length, vmax })
  }
  for(let r=0;r<rows.length;r++){
    for(let c=0;c<cols.length;c++){
      const v = (m[r]?.[c]) || 0
      const ratio = v / vmax
      const x = x0 + c*cw, y = y0 + r*rh
      ctx.fillStyle = colorFor(ratio)
      ctx.fillRect(x,y,cw-1,rh-1)
    }
  }
  ctx.fillStyle = themeVar(cvEl, '--fg', '#0f172a')
  ctx.font = `${12 * textScale}px ui-sans-serif,system-ui`
  rows.forEach((d,i)=> ctx.fillText(d, 8, y0 + i*rh + 12 * textScale))
  cols.forEach((h,i)=>{ if (i%2===0) ctx.fillText(String(h), x0 + i*cw + 2, y0 - 6 * textScale) })
}

onMounted(async ()=>{
  await nextTick()
  // Initial attempt (may be 0-size if panel hidden)
  draw()
  // Observe element size so opening the Heat tab triggers a redraw when it gains size
  try {
    ro = new ResizeObserver(()=> draw())
    if (cv.value) ro.observe(cv.value)
  } catch(_) {}
  window.addEventListener('resize', draw)
})
onBeforeUnmount(()=>{ try{ window.removeEventListener('resize', draw) }catch(_){} try{ ro && cv.value && ro.unobserve(cv.value) }catch(_){} ro=null })
watch(()=>props.hod, ()=> draw(), { deep:true })
watch([globalAppBg, activeThemeMode], () => draw())
</script>
