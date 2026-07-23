import { computed, ref, type WritableComputedRef } from 'vue'

export type ScopePreference = 'calendar' | 'category'
export type ThemeMode = 'light' | 'dark'

/**
 * Module-level reactive singletons so every widget observes the same
 * value without prop drilling. Persistence flows through the Nextcloud
 * user config: App.vue seeds these refs from the initial load payload
 * and watches for changes to trigger useDashboardPersistence.queueSave().
 *
 * globalAppBg is a theme-aware alias: reads and writes route to
 * globalAppBgLight or globalAppBgDark depending on activeThemeMode,
 * which App.vue keeps in sync with effectiveTheme. That way the picker
 * naturally shows / edits a different value per theme instead of one
 * saved color bleeding across themes.
 */
export const preferredScope = ref<ScopePreference>('category')
export const activeThemeMode = ref<ThemeMode>('light')
export const globalAppBgLight = ref<string | null>(null)
export const globalAppBgDark = ref<string | null>(null)

export const globalAppBg: WritableComputedRef<string | null> = computed({
  get: () => (activeThemeMode.value === 'dark' ? globalAppBgDark.value : globalAppBgLight.value),
  set: (value) => {
    if (activeThemeMode.value === 'dark') {
      globalAppBgDark.value = value
    } else {
      globalAppBgLight.value = value
    }
  },
})

export function useGlobalPreferences() {
  return {
    preferredScope,
    globalAppBg,
    globalAppBgLight,
    globalAppBgDark,
    activeThemeMode,
  }
}
