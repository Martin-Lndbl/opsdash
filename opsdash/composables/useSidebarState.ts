import { ref, computed, watch, nextTick } from 'vue'

const SIDEBAR_STORAGE_KEY = 'opsdash.sidebarOpen'

function readInitial(): boolean {
  if (typeof window === 'undefined') return true
  try {
    const stored = window.localStorage?.getItem(SIDEBAR_STORAGE_KEY)
    if (stored === 'true') return true
    if (stored === 'false') return false
  } catch {
    return true
  }
  return true
}

export function useSidebarState() {
  const navOpen = ref<boolean>(readInitial())

  watch(
    navOpen,
    (open) => {
      if (typeof window !== 'undefined') {
        try {
          window.localStorage?.setItem(SIDEBAR_STORAGE_KEY, open ? 'true' : 'false')
        } catch {
          // Ignore storage failures; default is open on next load.
        }
        nextTick(() => {
          window.dispatchEvent(new Event('resize'))
        }).catch(() => {})
      }
    },
    { immediate: false },
  )

  const toggleNav = () => {
    navOpen.value = !navOpen.value
  }

  const navToggleLabel = computed(() => (navOpen.value ? 'Hide sidebar' : 'Show sidebar'))

  return {
    navOpen,
    toggleNav,
    navToggleLabel,
  }
}
