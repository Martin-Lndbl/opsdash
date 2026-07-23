import { ref } from 'vue'

export type ScopePreference = 'calendar' | 'category'

/**
 * Module-level reactive singletons so every widget observes the same
 * value without prop drilling. Persistence flows through the Nextcloud
 * user config: App.vue seeds these refs from the initial load payload
 * and watches for changes to trigger useDashboardPersistence.queueSave().
 */
export const preferredScope = ref<ScopePreference>('category')
export const globalAppBg = ref<string | null>(null)

export function useGlobalPreferences() {
  return { preferredScope, globalAppBg }
}
