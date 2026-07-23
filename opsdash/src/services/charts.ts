// Create a HiDPI-aware 2D context; only update backing size if needed to avoid layout loops
export function ctxFor(c: HTMLCanvasElement | null): CanvasRenderingContext2D | null {
  if (!c) return null
  const pr = window.devicePixelRatio || 1
  const r = c.getBoundingClientRect()
  if (r.width < 2 || r.height < 2) return null
  const tw = Math.floor(r.width * pr)
  const th = Math.floor(r.height * pr)
  if (c.width !== tw) c.width = tw
  if (c.height !== th) c.height = th
  const ctx = c.getContext('2d')
  if (!ctx) return null
  ctx.setTransform(pr, 0, 0, pr, 0, 0)
  return ctx
}

export function themeVar(el: Element | null, name: string, fallback: string): string {
  if (typeof document === 'undefined') return fallback
  const root = el?.closest?.('#opsdash') ?? document.getElementById('opsdash')
  const styles = root ? getComputedStyle(root) : null
  const value = styles?.getPropertyValue(name).trim()
  return value || fallback
}

interface RgbColor {
  r: number
  g: number
  b: number
}

export function hexToRgb(hex: string): RgbColor | null {
  const m = /^#?([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i.exec(hex || '')
  if (!m) return null
  return { r: parseInt(m[1], 16), g: parseInt(m[2], 16), b: parseInt(m[3], 16) }
}

export function rgbToHex(r: number, g: number, b: number): string {
  const clamp = (n: number) => Math.max(0, Math.min(255, Math.round(n)))
  const toHex = (n: number) => clamp(n).toString(16).padStart(2, '0')
  return `#${toHex(r)}${toHex(g)}${toHex(b)}`
}

export function tint(hex: string): string {
  const rgb = hexToRgb(hex)
  if (!rgb) return hex
  const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
  const factor = prefersDark ? 1.12 : 0.9
  return rgbToHex(rgb.r * factor, rgb.g * factor, rgb.b * factor)
}

export function invert(hex: string): string {
  const rgb = hexToRgb(hex)
  if (!rgb) return hex
  return rgbToHex(255 - rgb.r, 255 - rgb.g, 255 - rgb.b)
}

function parseColorString(input: string): RgbColor | null {
  const s = (input || '').trim()
  if (!s) return null
  if (s.startsWith('#')) return hexToRgb(s)
  const m = /^rgba?\(\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/.exec(s)
  if (m) return { r: Math.round(Number(m[1])), g: Math.round(Number(m[2])), b: Math.round(Number(m[3])) }
  return null
}

function mixToward(rgb: RgbColor, target: number, ratio: number): string {
  const p = Math.max(0, Math.min(1, ratio))
  const r = Math.round(rgb.r + (target - rgb.r) * p)
  const g = Math.round(rgb.g + (target - rgb.g) * p)
  const b = Math.round(rgb.b + (target - rgb.b) * p)
  return `rgb(${r}, ${g}, ${b})`
}

// Paint a rectangular bar with a subtle vertical gradient + top shine.
// Mirrors the look of TimeSummary's week bars so other bar charts feel
// consistent rather than reading as flat calendar-color slabs.
export function paintPolishedBar(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  color: string,
): void {
  if (w <= 0 || h <= 0) {
    return
  }
  const rgb = parseColorString(color) ?? { r: 147, g: 197, b: 253 }
  const light = mixToward(rgb, 255, 0.24)
  const base = `rgb(${rgb.r}, ${rgb.g}, ${rgb.b})`
  const dark = mixToward(rgb, 0, 0.14)
  const grad = ctx.createLinearGradient(0, y, 0, y + h)
  grad.addColorStop(0, light)
  grad.addColorStop(0.55, base)
  grad.addColorStop(1, dark)
  ctx.fillStyle = grad
  ctx.fillRect(x, y, w, h)
  if (h > 3) {
    const shineHeight = Math.min(h, 6)
    const shine = ctx.createLinearGradient(0, y, 0, y + shineHeight)
    shine.addColorStop(0, 'rgba(255,255,255,0.28)')
    shine.addColorStop(1, 'rgba(255,255,255,0)')
    ctx.fillStyle = shine
    ctx.fillRect(x, y, w, shineHeight)
  }
}

// Muted paper → steel gradient for heatmap cells
export function heatColor(t: number): string {
  const clamp = (x: number) => (x < 0 ? 0 : x > 1 ? 1 : x)
  const tt = Math.pow(clamp(t), 0.6)
  const root = typeof document !== 'undefined' ? document.getElementById('opsdash') : null
  const styles = root ? getComputedStyle(root) : null
  const low = styles?.getPropertyValue('--heatmap-low').trim() || '#e0f2fe'
  const high = styles?.getPropertyValue('--heatmap-high').trim() || '#7c3aed'
  const c1 = hexToRgb(low)
  const c2 = hexToRgb(high)
  if (!c1 || !c2) return '#7c3aed'
  const mix = (a: number, b: number, p: number) => Math.round(a + (b - a) * p)
  const r = mix(c1.r, c2.r, tt)
  const g = mix(c1.g, c2.g, tt)
  const b = mix(c1.b, c2.b, tt)
  return `rgb(${r}, ${g}, ${b})`
}
