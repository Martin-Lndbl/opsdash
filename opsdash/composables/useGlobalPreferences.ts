import { ref, watch } from 'vue'

export type ScopePreference = 'calendar' | 'category'

const SCOPE_STORAGE_KEY = 'opsdash:preferredScope'
const APP_BG_STORAGE_KEY = 'opsdash:globalAppBg'

function loadInitialScope(): ScopePreference {
  try {
    const raw = typeof localStorage !== 'undefined' ? localStorage.getItem(SCOPE_STORAGE_KEY) : null
    if (raw === 'calendar' || raw === 'category') return raw
  } catch (_) { /* ignore */ }
  return 'category'
}

function loadInitialAppBg(): string | null {
  try {
    const raw = typeof localStorage !== 'undefined' ? localStorage.getItem(APP_BG_STORAGE_KEY) : null
    if (typeof raw === 'string' && raw.trim()) return raw
  } catch (_) { /* ignore */ }
  return null
}

// Module-level refs so every widget observes the same values.
export const preferredScope = ref<ScopePreference>(loadInitialScope())
export const globalAppBg = ref<string | null>(loadInitialAppBg())

watch(preferredScope, (value) => {
  try {
    if (typeof localStorage !== 'undefined') localStorage.setItem(SCOPE_STORAGE_KEY, value)
  } catch (_) { /* ignore */ }
})

watch(globalAppBg, (value) => {
  try {
    if (typeof localStorage === 'undefined') return
    if (value) localStorage.setItem(APP_BG_STORAGE_KEY, value)
    else localStorage.removeItem(APP_BG_STORAGE_KEY)
  } catch (_) { /* ignore */ }
})

export function useGlobalPreferences() {
  return { preferredScope, globalAppBg }
}
