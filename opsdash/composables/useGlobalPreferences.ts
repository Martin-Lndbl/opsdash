import { computed, ref, type WritableComputedRef } from 'vue'

export type ScopePreference = 'calendar' | 'category'
export type ThemeMode = 'light' | 'dark'

// Module-level reactive singletons so every widget observes the same
// value without prop drilling. App.vue seeds these from the initial
// payload and watches them to drive queueSave().
//
// globalAppBg is a theme-aware alias: reads/writes route to the light
// or dark slot based on activeThemeMode (which App.vue keeps in sync
// with effectiveTheme) so one picked color doesn't bleed across themes.
export const preferredScope = ref<ScopePreference>('category')
export const activeThemeMode = ref<ThemeMode>('light')
export const globalAppBgLight = ref<string | null>(null)
export const globalAppBgDark = ref<string | null>(null)

export const globalAppBg: WritableComputedRef<string | null> = computed({
  get: () => (activeThemeMode.value === 'dark' ? globalAppBgDark : globalAppBgLight).value,
  set: (v) => {
    (activeThemeMode.value === 'dark' ? globalAppBgDark : globalAppBgLight).value = v
  },
})

export function useGlobalPreferences() {
  return { preferredScope, globalAppBg, globalAppBgLight, globalAppBgDark, activeThemeMode }
}
