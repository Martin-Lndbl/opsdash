import { ref, watch } from 'vue'

export type ScopePreference = 'calendar' | 'category'

const STORAGE_KEY = 'opsdash:preferredScope'

function loadInitial(): ScopePreference {
  try {
    const raw = typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null
    if (raw === 'calendar' || raw === 'category') return raw
  } catch (_) { /* ignore */ }
  return 'category'
}

// Module-level ref so every widget observes the same value.
export const preferredScope = ref<ScopePreference>(loadInitial())

watch(preferredScope, (value) => {
  try {
    if (typeof localStorage !== 'undefined') localStorage.setItem(STORAGE_KEY, value)
  } catch (_) { /* ignore */ }
})

export function useGlobalPreferences() {
  return { preferredScope }
}
