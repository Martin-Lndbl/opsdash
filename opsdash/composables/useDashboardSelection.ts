import { type Ref } from 'vue'

import {
  CALENDAR_PRIORITY_MAX,
  CALENDAR_PRIORITY_MIN,
  normalizeTargetsConfig,
  type TargetsConfig,
} from '../src/services/targets'

interface DashboardSelectionDeps {
  calendars: Ref<Array<{ id: string }>>
  selected: Ref<string[]>
  groupsById: Ref<Record<string, number>>
  targetsWeek: Ref<Record<string, number>>
  targetsMonth: Ref<Record<string, number>>
  targetsConfig: Ref<TargetsConfig>
  range: Ref<'week' | 'month'>
  queueSave: (reload?: boolean) => void
  userChangedSelection: Ref<boolean>
}

const MAX_TARGET = 10000

export function useDashboardSelection(deps: DashboardSelectionDeps) {
  function isSelected(id: string): boolean {
    return deps.selected.value.includes(id)
  }

  function setSelected(id: string, checked: boolean) {
    const set = new Set(deps.selected.value)
    if (checked) {
      set.add(id)
    } else {
      set.delete(id)
    }
    deps.selected.value = Array.from(set)
  }

  function toggleCalendar(id: string) {
    setSelected(id, !isSelected(id))
    deps.userChangedSelection.value = true
    deps.queueSave()
  }

  function selectAll(includeAll: boolean) {
    deps.selected.value = includeAll
      ? deps.calendars.value.map((cal) => String(cal.id))
      : []
  }

  function setGroup(id: string, raw: number) {
    const n = Math.max(0, Math.min(9, Math.trunc(Number(raw) || 0)))
    const current = deps.groupsById.value ?? {}
    deps.groupsById.value = {
      ...current,
      [id]: n,
    }
    deps.queueSave(false)
  }

  function clampTarget(value: number): number {
    const round = Math.round(value * 100) / 100
    return Math.min(MAX_TARGET, Math.max(0, round))
  }

  function setTarget(id: string, hours: any) {
    const num = Number(hours)
    if (!Number.isFinite(num) || num < 0) {
      return
    }
    const main = clampTarget(num)
    if (deps.range.value === 'month') {
      const weekConverted = clampTarget(main / 4)
      deps.targetsMonth.value = {
        ...(deps.targetsMonth.value || {}),
        [id]: main,
      }
      deps.targetsWeek.value = {
        ...(deps.targetsWeek.value || {}),
        [id]: weekConverted,
      }
    } else {
      const monthConverted = clampTarget(main * 4)
      deps.targetsWeek.value = {
        ...(deps.targetsWeek.value || {}),
        [id]: main,
      }
      deps.targetsMonth.value = {
        ...(deps.targetsMonth.value || {}),
        [id]: monthConverted,
      }
    }
    deps.queueSave(false)
  }

  function updateTargetsConfig(next: TargetsConfig) {
    deps.targetsConfig.value = normalizeTargetsConfig(next)
    deps.queueSave(false)
  }

  function setCalendarPriority(id: string, raw: number) {
    const n = Math.max(CALENDAR_PRIORITY_MIN, Math.min(CALENDAR_PRIORITY_MAX, Math.trunc(Number(raw) || 0)))
    const priorities = { ...(deps.targetsConfig.value.calendarPriorities ?? {}) }
    if (n === 0) {
      delete priorities[id]
    } else {
      priorities[id] = n
    }
    deps.targetsConfig.value = normalizeTargetsConfig({ ...deps.targetsConfig.value, calendarPriorities: priorities })
    // Priorities change how overlapping hours are credited server-side, so refetch after saving.
    deps.queueSave(true)
  }

  return {
    isSelected,
    toggleCalendar,
    setGroup,
    setTarget,
    setCalendarPriority,
    updateTargetsConfig,
    selectAll,
  }
}
