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

function neutralCardFill(ctx: CanvasRenderingContext2D): string {
  const cvEl = ctx.canvas as HTMLCanvasElement
  const cardBg = themeVar(cvEl, '--card', '#ffffff')
  const fgColor = themeVar(cvEl, '--fg', '#0f172a')
  const cardRgb = parseColorString(cardBg) ?? { r: 255, g: 255, b: 255 }
  const fgRgb = parseColorString(fgColor) ?? { r: 15, g: 23, b: 42 }
  // 6% fg mix — the original neutral, per user preference.
  const mix = (a: number, b: number) => Math.round(a * 0.94 + b * 0.06)
  return `rgb(${mix(cardRgb.r, fgRgb.r)}, ${mix(cardRgb.g, fgRgb.g)}, ${mix(cardRgb.b, fgRgb.b)})`
}

function perceivedLuminance(rgb: RgbColor): number {
  // Rec. 709 relative luminance, 0..1 on 0..255 input.
  return (0.2126 * rgb.r + 0.7152 * rgb.g + 0.0722 * rgb.b) / 255
}

function isCardLight(ctx: CanvasRenderingContext2D): boolean {
  const cvEl = ctx.canvas as HTMLCanvasElement
  const cardBg = themeVar(cvEl, '--card', '#ffffff')
  const cardRgb = parseColorString(cardBg) ?? { r: 255, g: 255, b: 255 }
  return perceivedLuminance(cardRgb) > 0.55
}

// In outline mode on a light card the raw item color often reads
// too pale (bright yellows, mints, sky blues on white barely
// register). Nudge it toward black so the stroke keeps clear
// contrast without shifting hue much.
function outlineStrokeColor(ctx: CanvasRenderingContext2D, color: string): string {
  if (!isCardLight(ctx)) return color
  const rgb = parseColorString(color)
  if (!rgb) return color
  const lum = perceivedLuminance(rgb)
  if (lum <= 0.35) return color
  // Ramp: the brighter the color, the more we darken it.
  // lum 0.35 → 0 mix, lum 1.0 → 0.42 mix toward black.
  const ratio = Math.min(0.42, ((lum - 0.35) / 0.65) * 0.42)
  return mixToward(rgb, 0, ratio)
}

function tintOverlay(color: string, alphaTop: number, alphaBottom: number): (grad: CanvasGradient) => void {
  const rgb = parseColorString(color) ?? { r: 147, g: 197, b: 253 }
  return (grad) => {
    grad.addColorStop(0, `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alphaTop})`)
    grad.addColorStop(0.6, `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alphaBottom})`)
    grad.addColorStop(1, `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0)`)
  }
}

export type ChartColorStyle = 'fill' | 'outline'

// Filled bar: base color at bottom, ~18% lighter at top. TimeSummary
// week-bar look.
function paintFilledBar(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  color: string,
): void {
  const rgb = parseColorString(color) ?? { r: 147, g: 197, b: 253 }
  const light = mixToward(rgb, 255, 0.18)
  const base = `rgb(${rgb.r}, ${rgb.g}, ${rgb.b})`
  const grad = ctx.createLinearGradient(0, y, 0, y + h)
  grad.addColorStop(0, light)
  grad.addColorStop(1, base)
  ctx.fillStyle = grad
  ctx.fillRect(x, y, w, h)
}

// Outlined bar: neutral card-ish fill + item color as a 2px inset left
// accent and 1px outer stroke. Optional color-tint gradient overlay
// (intensity 0-1) fades from top-of-bar towards transparent.
function paintOutlinedBar(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  color: string,
  tint = 0,
): void {
  ctx.fillStyle = neutralCardFill(ctx)
  ctx.fillRect(x, y, w, h)
  if (tint > 0) {
    const grad = ctx.createLinearGradient(0, y, 0, y + h)
    tintOverlay(color, 0.16 * tint, 0.04 * tint)(grad)
    ctx.fillStyle = grad
    ctx.fillRect(x, y, w, h)
  }
  const stroke = outlineStrokeColor(ctx, color)
  const accentW = Math.min(2, w)
  ctx.fillStyle = stroke
  ctx.fillRect(x, y, accentW, h)
  if (w >= 2 && h >= 2) {
    ctx.strokeStyle = stroke
    ctx.lineWidth = isCardLight(ctx) ? 1.25 : 1
    ctx.strokeRect(x + 0.5, y + 0.5, Math.max(0, w - 1), Math.max(0, h - 1))
  }
}

// Outlined stacked segment: neutral fill + 2px left accent, 2px right
// accent, and a 2px top color band. Bottom is intentionally left off
// because adjacent segments would double up on the seam.
function paintOutlinedStackedSegment(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  color: string,
  tint = 0,
): void {
  ctx.fillStyle = neutralCardFill(ctx)
  ctx.fillRect(x, y, w, h)
  if (tint > 0) {
    const grad = ctx.createLinearGradient(0, y, 0, y + h)
    tintOverlay(color, 0.18 * tint, 0.05 * tint)(grad)
    ctx.fillStyle = grad
    ctx.fillRect(x, y, w, h)
  }
  const stroke = outlineStrokeColor(ctx, color)
  const accentW = Math.min(2, w)
  ctx.fillStyle = stroke
  ctx.fillRect(x, y, accentW, h)
  if (w > accentW) {
    ctx.fillRect(x + w - accentW, y, accentW, h)
  }
  if (h > 2) {
    const topH = Math.min(2, h)
    ctx.fillRect(x, y, w, topH)
  }
}

// Router. Each chart widget picks its style; default is filled.
// variant='segment' hints that the bar is one slice of a stacked bar,
// so the outline treatment avoids doubling up at segment seams.
// tint (0-1) controls the intensity of the outline-mode color gradient
// overlay. tint=0 (default) keeps the pre-gradient neutral fill; higher
// values add more color character.
export function paintPolishedBar(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  color: string,
  style: ChartColorStyle = 'fill',
  variant: 'bar' | 'segment' = 'bar',
  tint = 0,
): void {
  if (w <= 0 || h <= 0) return
  if (style === 'outline') {
    if (variant === 'segment') paintOutlinedStackedSegment(ctx, x, y, w, h, color, tint)
    else paintOutlinedBar(ctx, x, y, w, h, color, tint)
  } else paintFilledBar(ctx, x, y, w, h, color)
}

function paintFilledSlice(
  ctx: CanvasRenderingContext2D,
  cx: number,
  cy: number,
  r: number,
  startAngle: number,
  endAngle: number,
  color: string,
): void {
  const rgb = parseColorString(color) ?? { r: 147, g: 197, b: 253 }
  const light = mixToward(rgb, 255, 0.22)
  const base = `rgb(${rgb.r}, ${rgb.g}, ${rgb.b})`
  const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, r)
  grad.addColorStop(0, light)
  grad.addColorStop(0.65, base)
  grad.addColorStop(1, base)
  ctx.fillStyle = grad
  ctx.beginPath()
  ctx.moveTo(cx, cy)
  ctx.arc(cx, cy, r, startAngle, endAngle)
  ctx.closePath()
  ctx.fill()
}

function paintOutlinedSlice(
  ctx: CanvasRenderingContext2D,
  cx: number,
  cy: number,
  r: number,
  startAngle: number,
  endAngle: number,
  color: string,
  tint = 0,
): void {
  ctx.beginPath()
  ctx.moveTo(cx, cy)
  ctx.arc(cx, cy, r, startAngle, endAngle)
  ctx.closePath()
  ctx.fillStyle = neutralCardFill(ctx)
  ctx.fill()
  if (tint > 0) {
    const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, r)
    const rgb = parseColorString(color) ?? { r: 147, g: 197, b: 253 }
    grad.addColorStop(0, `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${0.02 * tint})`)
    grad.addColorStop(0.75, `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${0.08 * tint})`)
    grad.addColorStop(1, `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${0.22 * tint})`)
    ctx.fillStyle = grad
    ctx.fill()
  }
  ctx.strokeStyle = outlineStrokeColor(ctx, color)
  ctx.lineWidth = isCardLight(ctx) ? 2 : 1.5
  ctx.stroke()
}

export function paintPolishedSlice(
  ctx: CanvasRenderingContext2D,
  cx: number,
  cy: number,
  r: number,
  startAngle: number,
  endAngle: number,
  color: string,
  style: ChartColorStyle = 'fill',
  tint = 0,
): void {
  if (r <= 0) return
  if (style === 'outline') paintOutlinedSlice(ctx, cx, cy, r, startAngle, endAngle, color, tint)
  else paintFilledSlice(ctx, cx, cy, r, startAngle, endAngle, color)
}

// Small floating tooltip painted onto a chart canvas near the cursor.
// Clamped to stay inside the canvas box.
export function drawChartTooltip(
  ctx: CanvasRenderingContext2D,
  opts: {
    cursorX: number
    cursorY: number
    canvasWidth: number
    canvasHeight: number
    text: string
    bg: string
    fg: string
    scale?: number
  },
): void {
  const scale = Math.max(0.8, Number(opts.scale) || 1)
  const fontSize = 11 * scale
  const padX = 8 * scale
  const padY = 5 * scale
  ctx.save()
  ctx.font = `${fontSize}px ui-sans-serif,system-ui`
  const tw = ctx.measureText(opts.text).width
  const w = tw + padX * 2
  const h = fontSize + padY * 2
  const margin = 6
  let bx = opts.cursorX + 12
  let by = opts.cursorY - h - 12
  if (bx + w + margin > opts.canvasWidth) bx = opts.cursorX - w - 12
  if (bx < margin) bx = margin
  if (by < margin) by = opts.cursorY + 16
  if (by + h + margin > opts.canvasHeight) by = opts.canvasHeight - h - margin
  const r = 6
  ctx.beginPath()
  ctx.moveTo(bx + r, by)
  ctx.lineTo(bx + w - r, by)
  ctx.quadraticCurveTo(bx + w, by, bx + w, by + r)
  ctx.lineTo(bx + w, by + h - r)
  ctx.quadraticCurveTo(bx + w, by + h, bx + w - r, by + h)
  ctx.lineTo(bx + r, by + h)
  ctx.quadraticCurveTo(bx, by + h, bx, by + h - r)
  ctx.lineTo(bx, by + r)
  ctx.quadraticCurveTo(bx, by, bx + r, by)
  ctx.closePath()
  ctx.shadowColor = 'rgba(0,0,0,0.16)'
  ctx.shadowBlur = 8
  ctx.shadowOffsetY = 2
  ctx.fillStyle = opts.bg
  ctx.fill()
  ctx.shadowColor = 'transparent'
  ctx.shadowBlur = 0
  ctx.shadowOffsetY = 0
  ctx.fillStyle = opts.fg
  ctx.textBaseline = 'middle'
  ctx.textAlign = 'left'
  ctx.fillText(opts.text, bx + padX, by + h / 2)
  ctx.restore()
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
