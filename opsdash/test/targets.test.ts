import { describe, it, expect } from 'vitest'
import { clampTarget, convertMonthToWeek, convertWeekToMonth, normalizeTargetsConfig, progressPercent } from '../src/services/targets'

describe('targets helpers', () => {
  it('clamps targets to [0..10000] and rounds to 2 decimals', () => {
    expect(clampTarget(-5)).toBe(0)
    expect(clampTarget(2.345)).toBe(2.35)
    expect(clampTarget(20000)).toBe(10000)
  })

  it('converts week to month (×4) with clamp', () => {
    expect(convertWeekToMonth(10)).toBe(40)
    expect(convertWeekToMonth(2500)).toBe(10000) // clamped
  })

  it('converts month to week (÷4) with rounding', () => {
    expect(convertMonthToWeek(60)).toBe(15)
    expect(convertMonthToWeek(2.5)).toBe(0.63)
  })

  it('computes progress percent with bounds', () => {
    expect(progressPercent(10, 20)).toBe(50)
    expect(progressPercent(0, 0)).toBe(0)
    expect(progressPercent(30, 20)).toBe(150)
    expect(progressPercent(-5 as unknown as number, 10)).toBe(0)
  })

  it('normalizes calendar priorities: clamps to ±10, drops zero and garbage', () => {
    const cfg = normalizeTargetsConfig({ calendarPriorities: { lunch: 99, lectures: -99, zero: 0, junk: 'x', '  ': 3, half: 2.7 } } as any)
    expect(cfg.calendarPriorities).toEqual({ lunch: 10, lectures: -10, half: 2 })
    expect(normalizeTargetsConfig(undefined).calendarPriorities).toEqual({})
    expect(normalizeTargetsConfig({ calendarPriorities: [1, 2] } as any).calendarPriorities).toEqual({})
  })

  it('clamps all-day hours to 0–24 and keeps defaults', () => {
    const cfg = normalizeTargetsConfig({ allDayHours: 42 } as any)
    expect(cfg.allDayHours).toBe(24)
    const cfgNeg = normalizeTargetsConfig({ allDayHours: -5 } as any)
    expect(cfgNeg.allDayHours).toBe(0)
    const cfgDefault = normalizeTargetsConfig(undefined)
    expect(cfgDefault.allDayHours).toBeGreaterThan(0)
  })
})
