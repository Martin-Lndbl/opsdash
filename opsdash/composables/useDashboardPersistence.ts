import { ref, type Ref } from 'vue'

import {
  cloneTargetsConfig,
  normalizeTargetsConfig,
  type TargetsConfig,
} from '../src/services/targets'
import type { ScopePreference } from './useGlobalPreferences'
import type { WidgetTabsState } from '../src/services/widgetsRegistry'
import type { ThemePreference } from './useThemePreference'
import {
  normalizeDeckSettings,
  normalizeReportingConfig,
  type DeckFeatureSettings,
  type ReportingConfig,
} from '../src/services/reporting'
import { normalizeWidgetTabs, createDefaultWidgetTabs } from '../src/services/widgetsRegistry'
import { compareReleaseVersions, normalizeReleaseVersion } from '../src/services/releaseNotes'
import type { OnboardingState } from './useDashboard'

interface DashboardPersistenceDeps {
  route: (name: 'persist') => string
  postJson: (url: string, body: Record<string, unknown>) => Promise<any>
  notifyError: (message: string) => void
  notifySuccess: (message: string) => void
  onReload?: () => Promise<void> | void
  selected: Ref<string[]>
  groupsById: Ref<Record<string, number>>
  targetsWeek: Ref<Record<string, number>>
  targetsMonth: Ref<Record<string, number>>
  targetsConfig: Ref<TargetsConfig>
  themePreference?: Ref<ThemePreference>
  reportingConfig?: Ref<ReportingConfig>
  deckSettings?: Ref<DeckFeatureSettings>
  widgetTabs?: Ref<WidgetTabsState>
  onboardingState?: Ref<OnboardingState | null>
  activePreset?: Ref<string | null>
  preferredScope?: Ref<ScopePreference>
  globalAppBg?: Ref<string | null>
  globalAppBgLight?: Ref<string | null>
  globalAppBgDark?: Ref<string | null>
}

export function useDashboardPersistence(deps: DashboardPersistenceDeps) {
  const isSaving = ref(false)
  let saveTimer: ReturnType<typeof setTimeout> | null = null
  let saveSequence = 0
  let latestRequestId = 0

  function queueSave(reload = true, silent = false) {
    if (saveTimer) {
      clearTimeout(saveTimer)
    }
    saveTimer = setTimeout(async () => {
      saveTimer = null
      const requestId = ++saveSequence
      latestRequestId = requestId
      try {
        isSaving.value = true
        const previousConfig = cloneTargetsConfig(deps.targetsConfig.value)
        const payload: Record<string, any> = {
          cals: deps.selected.value,
          groups: deps.groupsById.value,
          targets_week: deps.targetsWeek.value,
          targets_month: deps.targetsMonth.value,
          targets_config: previousConfig,
        }
        if (deps.themePreference) {
          payload.theme_preference = deps.themePreference.value
        }
        if (deps.reportingConfig) {
          payload.reporting_config = deps.reportingConfig.value
        }
        if (deps.deckSettings) {
          payload.deck_settings = deps.deckSettings.value
        }
        if (deps.widgetTabs) {
          payload.widgets = deps.widgetTabs.value
        }
        if (deps.onboardingState?.value) {
          payload.onboarding = deps.onboardingState.value
        }
        if (deps.activePreset !== undefined) {
          payload.active_preset = deps.activePreset.value ?? null
        }
        if (deps.preferredScope) {
          payload.preferred_scope = deps.preferredScope.value
        }
        if (deps.globalAppBg) {
          payload.global_app_bg = deps.globalAppBg.value ?? null
        }
        if (deps.globalAppBgLight) {
          payload.global_app_bg_light = deps.globalAppBgLight.value ?? null
        }
        if (deps.globalAppBgDark) {
          payload.global_app_bg_dark = deps.globalAppBgDark.value ?? null
        }
        const result = await deps.postJson(deps.route('persist'), payload)
        if (requestId !== latestRequestId) {
          return
        }

        if (Array.isArray(result.read)) {
          deps.selected.value = result.read.map((id: any) => String(id))
        } else if (Array.isArray(result.saved)) {
          deps.selected.value = result.saved.map((id: any) => String(id))
        }

        const cfgRead = result.targets_config_read as TargetsConfig | undefined
        const cfgSaved = result.targets_config_saved as TargetsConfig | undefined
        const nextConfig = mergeIncomingTargetsConfig(cfgRead ?? cfgSaved, previousConfig)
        if (nextConfig) {
          deps.targetsConfig.value = nextConfig
        }

        if (deps.themePreference) {
          const nextTheme = normalizeThemePreference(
            result.theme_preference_read ?? result.theme_preference_saved,
          )
          if (nextTheme) {
            deps.themePreference.value = nextTheme
          }
        }
        if (deps.reportingConfig) {
          const nextReporting = normalizeReportingConfig(
            result.reporting_config_read ?? result.reporting_config_saved,
            deps.reportingConfig.value,
          )
          if (nextReporting) {
            deps.reportingConfig.value = nextReporting
          }
        }
        if (deps.deckSettings) {
          const nextDeck = normalizeDeckSettings(
            result.deck_settings_read ?? result.deck_settings_saved,
            deps.deckSettings.value,
          )
          if (nextDeck) {
            deps.deckSettings.value = nextDeck
          }
        }
        if (deps.widgetTabs) {
          const nextWidgets = result.widgets_read ?? result.widgets_saved ?? result.widgets
          const fallback = deps.widgetTabs.value || createDefaultWidgetTabs('standard')
          deps.widgetTabs.value = normalizeWidgetTabs(nextWidgets, fallback)
        }
        if (deps.preferredScope) {
          const raw = result.preferred_scope_read ?? result.preferred_scope_saved
          if (raw === 'calendar' || raw === 'category') {
            deps.preferredScope.value = raw
          }
        }
        if (deps.globalAppBg) {
          const raw = result.global_app_bg_read ?? result.global_app_bg_saved
          if (typeof raw === 'string' && /^#[0-9a-fA-F]{6}$/.test(raw)) {
            deps.globalAppBg.value = raw
          } else if (raw === null) {
            deps.globalAppBg.value = null
          }
        }
        const applyBgSlot = (slot: Ref<string | null> | undefined, raw: unknown) => {
          if (!slot) return
          if (typeof raw === 'string' && /^#[0-9a-fA-F]{6}$/.test(raw)) {
            slot.value = raw
          } else if (raw === null) {
            slot.value = null
          }
        }
        applyBgSlot(
          deps.globalAppBgLight,
          result.global_app_bg_light_read ?? result.global_app_bg_light_saved,
        )
        applyBgSlot(
          deps.globalAppBgDark,
          result.global_app_bg_dark_read ?? result.global_app_bg_dark_saved,
        )
        if (deps.onboardingState) {
          const nextOnboarding = result.onboarding_read ?? result.onboarding_saved
          if (nextOnboarding && typeof nextOnboarding === 'object') {
            const currentSeenVersion = normalizeReleaseVersion(deps.onboardingState.value?.releaseNotesSeenVersion ?? '')
            const incomingSeenVersion = normalizeReleaseVersion(nextOnboarding.releaseNotesSeenVersion ?? '')
            deps.onboardingState.value = {
              ...(deps.onboardingState.value || {}),
              ...nextOnboarding,
              releaseNotesSeenVersion: pickLatestReleaseVersion(currentSeenVersion, incomingSeenVersion),
            } as OnboardingState
          }
        }

        if (reload && deps.onReload) {
          await deps.onReload()
        }

        if (!silent) {
          deps.notifySuccess('Selection saved')
        }
      } catch (error) {
        if (requestId !== latestRequestId) {
          return
        }
        console.error(error)
        if (!silent) {
          deps.notifyError('Failed to save selection')
        }
      } finally {
        if (requestId === latestRequestId) {
          isSaving.value = false
        }
      }
    }, 250)
  }

  return {
    queueSave,
    isSaving,
  }
}

function normalizeThemePreference(value: any): ThemePreference | null {
  if (value === 'light' || value === 'dark' || value === 'auto') {
    return value
  }
  return null
}

function pickLatestReleaseVersion(currentVersion: string, incomingVersion: string): string {
  if (!currentVersion) return incomingVersion
  if (!incomingVersion) return currentVersion
  return compareReleaseVersions(currentVersion, incomingVersion) >= 0
    ? incomingVersion
    : currentVersion
}

function mergeIncomingTargetsConfig(
  incoming: TargetsConfig | undefined,
  previous: TargetsConfig,
): TargetsConfig | undefined {
  if (!incoming) return undefined

  const raw = JSON.parse(JSON.stringify(incoming)) as any

  const prevCategories = Array.isArray(previous?.categories) ? previous.categories : []
  const prevCategoryMap = new Map<string, any>()
  prevCategories.forEach((cat: any) => {
    const id = String(cat?.id ?? '')
    if (id) prevCategoryMap.set(id, cat)
  })
  if (Array.isArray(raw.categories)) {
    raw.categories = raw.categories.map((cat: any) => {
      const id = String(cat?.id ?? '')
      if (!id) return cat
      const prev = prevCategoryMap.get(id)
      if (prev && (cat?.color == null || cat.color === '')) {
        if (typeof prev.color === 'string' && prev.color) {
          cat.color = prev.color
        }
      }
      return cat
    })
  }

  return normalizeTargetsConfig(raw as TargetsConfig)
}
