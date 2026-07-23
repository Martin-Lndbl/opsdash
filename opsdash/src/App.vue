<template>
  <div id="opsdash" class="opsdash" :class="[{ 'is-nav-collapsed': !navOpen }, opsdashThemeClass]" :style="opsdashRootStyle">
    <OnboardingWizard
      :key="onboardingRunId"
      :visible="onboardingWizardVisible"
      :calendars="wizardCalendars"
      :initial-selection="wizardInitialSelection"
      :initial-strategy="wizardInitialStrategy"
      :onboarding-version="ONBOARDING_VERSION"
      :closable="!autoWizardNeeded"
      :initial-theme-preference="themePreference"
      :system-theme="systemTheme"
      :initial-all-day-hours="wizardInitialAllDayHours"
      :initial-total-hours="wizardInitialTotalHours"
      :initial-targets-config="wizardInitialTargetsConfig"
      :initial-targets-week="targetsWeek"
      :initial-deck-settings="wizardInitialDeckSettings"
      :initial-reporting-config="wizardInitialReportingConfig"
      :initial-dashboard-mode="wizardInitialDashboardMode"
      :initial-categories="wizardInitialCategories"
      :initial-assignments="wizardInitialAssignments"
      :start-step="wizardStartStep"
      :has-existing-config="hasExistingConfig"
      :saving="isOnboardingSaving"
      :snapshot-saving="isWizardSnapshotSaving"
      :snapshot-notice="wizardSnapshotNotice"
      :persist-step="handleWizardSaveStep"
      :send-test-report="handleWizardTestReport"
      :send-checkpoint-report="handleWizardCheckpointReport"
      @close="handleWizardClose"
      @skip="handleWizardSkip"
      @complete="handleWizardComplete"
      @save-current-config="handleWizardSaveSnapshot"
    />
    <ProfilesOverlay
      :visible="profilesOverlayOpen"
      :theme="effectiveTheme"
      :presets="presets"
      :is-loading="presetsLoading"
      :is-saving="presetSaving"
      :is-applying="presetApplying"
      :warnings="presetWarnings"
      @close="profilesOverlayOpen = false"
      @save="savePreset"
      @load="loadPreset"
      @delete="deletePreset"
      @refresh="refreshPresets"
      @clear-warnings="clearPresetWarnings"
      @export-config="exportSidebarConfig"
      @import-config="importSidebarConfig"
    />
    <VersionNotesOverlay
      v-if="activeReleaseNotesEntry"
      :visible="releaseNotesOverlayOpen"
      :theme="effectiveTheme"
      :entry="activeReleaseNotesEntry"
      :history="releaseNotesHistory"
      :selected-version="selectedReleaseNotesVersion"
      :is-saving="isReleaseNotesSaving"
      @close="closeReleaseNotesOverlay"
      @select-version="openReleaseNotesVersion"
      @action="handleReleaseNotesAction"
    />
    <NcAppContent app-name="Operational Dashboard" :show-navigation="navOpen">
      <template #navigation>
        <Sidebar
          id="opsdash-sidebar"
          :is-loading="isInitialLoading"
          :range="range"
          :offset="offset"
          :from="from"
          :to="to"
          :nav-toggle-label="navToggleLabel"
          :nav-toggle-icon="navToggleIcon"
          :dashboard-mode="dashboardMode"
          :guided-hints="guidedHints"
          :guided-hint-statuses="guidedStepStatuses"
          :last-sync="lastSyncLabel"
          :release-notes-available="releaseNotesAvailable"
          :release-notes-open="releaseNotesOverlayOpen"
          @load="performLoad"
          @update:range="(v)=>{ range=v as any; offset=0; performLoad() }"
          @update:offset="(v)=>{ offset=v as number; performLoad() }"
          @toggle-nav="toggleNav"
          @rerun-onboarding="openWizardFromSidebar"
          @open-profiles="openProfilesPanel"
          @open-release-notes="openCurrentReleaseNotes"
          @open-shortcuts="(el) => openShortcuts(el)"
        />
      </template>

      <template #sidebar-toggle>
        <NcAppSidebarToggle :open="navOpen" @toggle="toggleNav" />
      </template>

      <div class="app-shell">
        <div class="app-main">
          <div class="app-container">
            <div class="banner warn" v-if="isTruncated" :title="truncTooltip">
              Showing partial data to keep things fast.
              <template v-if="truncLimits && truncLimits.totalProcessed != null">
                Processed {{ truncLimits.totalProcessed }} items.
              </template>
            </div>

            <!-- ── App bar: unified browse + edit surface ── -->
            <div
              ref="appBarSlotRef"
              class="app-bar-slot"
            >
            <div
              ref="appBarRef"
              class="app-bar"
              :class="{ 'app-bar--editing': isLayoutEditing }"
            >

              <!-- ══ BROWSE MODE ══ -->
              <template v-if="!isLayoutEditing">
                <!-- Row 1: range nav (sidebar closed only) -->
                <div v-if="!navOpen" class="bar-row">
                  <button class="show-btn" type="button" @click="toggleNav" :aria-label="navToggleLabel">
                    <svg width="10" height="11" viewBox="0 0 10 11" fill="none">
                      <path d="M1 1l4 4.5L1 10M5 1l4 4.5L5 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                  </button>
                  <div class="app-seg w2">
                    <button type="button" :class="{ on: range === 'week' }" :disabled="isInitialLoading" @click="setRange('week')">Week</button>
                    <button type="button" :class="{ on: range === 'month' }" :disabled="isInitialLoading" @click="setRange('month')">Month</button>
                  </div>
                  <div class="app-navc">
                    <button class="navc-btn" type="button" :disabled="isInitialLoading" @click="goPrevious">
                      <svg width="6" height="11" viewBox="0 0 6 11" fill="none">
                        <path d="M5 1L1 5.5l4 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                      </svg>
                    </button>
                    <div class="navc-date">
                      <span class="navc-sub">
                        <span class="navc-date-start">{{ from }}</span>
                        <span class="navc-title">{{ rangeHeadline }}</span>
                        <span class="navc-date-end">{{ to }}</span>
                      </span>
                    </div>
                    <button class="navc-btn" type="button" :disabled="isInitialLoading" @click="goNext">
                      <svg width="6" height="11" viewBox="0 0 6 11" fill="none">
                        <path d="M1 1l4 4.5L1 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                      </svg>
                    </button>
                  </div>
                  <button class="bar-btn-ref" type="button" :disabled="isInitialLoading || isRefreshing" @click="loadCurrent">Refresh</button>
                </div>

                <!-- Tab strip + Edit layout button -->
                <div class="bar-row" :class="{ sep: !navOpen }">
                  <div class="tab-strip" role="tablist" aria-label="Dashboard tabs">
                    <div v-for="tab in layoutTabs" :key="tab.id" class="tab-item">
                      <button
                        type="button"
                        class="tab"
                        :class="{ on: tab.id === activeTabId }"
                        role="tab"
                        :aria-selected="tab.id === activeTabId"
                        @click="handleTabClick(tab.id)"
                        @contextmenu.prevent="openTabContextMenu($event, tab.id)"
                      >
                        <span class="tab-label">{{ tab.label }}</span>
                        <span v-if="tab.id === defaultTabId" class="tab-default-badge">Default</span>
                      </button>
                    </div>
                  </div>
                  <div class="bar-flex1" />
                  <div v-if="activePresetRef || globalLookbackLabel" class="dashboard-context-badges">
                    <button
                      v-if="activePresetRef"
                      class="active-profile-badge"
                      type="button"
                      title="Open profiles"
                      @click="openProfilesPanel"
                    >{{ activePresetRef }}</button>
                    <span v-if="globalLookbackLabel" class="global-lookback-badge" title="Global trend lookback">
                      <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                        <path d="M10.5 6a4.5 4.5 0 1 1-1.32-3.18" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        <path d="M10.5 1.5v3h-3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                      </svg>
                      {{ globalLookbackLabel }}
                    </span>
                  </div>
                  <span v-if="isRefreshing" class="refresh-badge" role="status" aria-live="polite">Updating…</span>
                  <button class="btn-ghost" type="button" @click="toggleLayoutEditing">Edit layout</button>
                </div>
              </template>

              <!-- ══ EDIT MODE (hard split) ══ -->
              <template v-else>
                <!-- Row 1: tabs only -->
                <div class="bar-row">
                  <button
                    v-if="!navOpen"
                    class="show-btn"
                    type="button"
                    title="Open sidebar"
                    :aria-label="navToggleLabel"
                    @click="openSidebarFromEdit"
                  >
                    <svg width="10" height="11" viewBox="0 0 10 11" fill="none">
                      <path d="M1 1l4 4.5L1 10M5 1l4 4.5L5 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                  </button>
                  <div class="tab-strip" role="tablist" aria-label="Dashboard tabs">
                    <div v-for="tab in layoutTabs" :key="tab.id" class="tab-item">
                      <button
                        type="button"
                        class="tab"
                        :class="{ on: tab.id === activeTabId }"
                        role="tab"
                        :aria-selected="tab.id === activeTabId"
                        @click="handleTabClick(tab.id)"
                        @contextmenu.prevent="openTabContextMenu($event, tab.id)"
                      >
                        <template v-if="tabEditingId === tab.id">
                          <input
                            class="tab-input"
                            v-model="tabLabelDraft"
                            @blur="commitTabLabel"
                            @keydown.enter.prevent="commitTabLabel"
                            @keydown.esc.prevent="cancelTabLabel"
                            @click.stop
                          />
                        </template>
                        <template v-else>
                          <span class="tab-label">{{ tab.label }}</span>
                        </template>
                      </button>
                      <button
                        type="button"
                        class="tab-menu-btn"
                        :aria-label="`Tab actions for ${tab.label}`"
                        @click.stop="openTabContextMenuFromButton($event, tab.id)"
                      >⋯</button>
                    </div>
                    <button type="button" class="tab tab--add" @click="addTab()">+ Tab</button>
                  </div>
                  <div class="bar-flex1" />
                  <div v-if="activePresetRef || globalLookbackLabel" class="dashboard-context-badges">
                    <button
                      v-if="activePresetRef"
                      class="active-profile-badge"
                      type="button"
                      title="Open profiles"
                      @click="openProfilesPanel"
                    >{{ activePresetRef }}</button>
                    <span v-if="globalLookbackLabel" class="global-lookback-badge" title="Global trend lookback">
                      <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                        <path d="M10.5 6a4.5 4.5 0 1 1-1.32-3.18" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        <path d="M10.5 1.5v3h-3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                      </svg>
                      {{ globalLookbackLabel }}
                    </span>
                  </div>
                </div>

                <!-- Row 2: edit context + actions -->
                <div class="bar-row sep bar-row--edit-actions">
                  <span class="mode-hint">Editing layout · no date navigation</span>
                  <button class="btn-ghost btn-ghost--primary" type="button" @click="showAddWidgetModal = true">Add widget</button>
                  <button class="btn-done" type="button" @click="toggleLayoutEditing">Done editing</button>
                </div>

                <!-- Row 3: inline widget controls -->
                <div
                  ref="itbRowSlotRef"
                  class="itb-row-slot"
                  :style="itbFloating && itbRowHeight ? { minHeight: `${itbRowHeight}px` } : undefined"
                >
                  <div ref="itbRowRef" class="bar-row sep itb-row" :class="{ 'itb-row--floating': itbFloating, 'itb-row--empty': !inlineSelectedItem }">
                    <div class="itb">

                    <!-- Selected widget chip (far left) -->
                    <div v-if="inlineSelectedItem" class="sel-chip sel-chip--named">
                      <div class="sel-dot" />
                      <div class="sel-chip__body">
                        <div class="sel-chip__type">{{ inlineSelectedItemType }}</div>
                        <input
                          type="text"
                          class="sel-chip__name"
                          :value="selectedTitlePrefix"
                          :placeholder="inlineSelectedItemType"
                          @input="setSelectedOption('titlePrefix', ($event.target as HTMLInputElement).value)"
                        />
                      </div>
                    </div>
                    <div v-else class="sel-chip sel-chip--empty">
                      <div class="sel-dot sel-dot--empty" />
                      <span class="sel-chip__empty-copy">
                        <strong>Select a widget</strong>
                        <span>to edit its layout and settings</span>
                      </span>
                    </div>

                    <template v-if="inlineSelectedItem">
                    <div class="vsep" />

                    <!-- Width group -->
                    <div class="ic-group" :class="{ open: inlineGroupOpen === 'width' }">
                      <button class="ic ic-group__trigger" type="button" :class="{ on: inlineGroupOpen === 'width' }" :disabled="!inlineSelectedItem" title="Width options" @click="toggleInlineGroup('width')">
                        <svg width="16" height="12" viewBox="0 0 16 12" fill="none">
                          <rect x="1" y="2" width="14" height="8" rx="2" stroke="currentColor" stroke-width="1.4"/>
                          <path d="M5 1v10M11 1v10" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" opacity=".55"/>
                        </svg>
                        <span class="ic-lbl">Width</span>
                      </button>
                      <div v-if="inlineGroupOpen === 'width'" class="ic-group__rail">
                        <button class="ic ic-sub" type="button" :class="{ on: selectedWidth === 'quarter' }" :disabled="!inlineSelectedItem" title="Quarter width" @click="setInlineWidth('quarter')">
                          <svg width="14" height="12" viewBox="0 0 14 12" fill="none"><rect x="1" y="1" width="3" height="10" rx="1.4" stroke="currentColor" stroke-width="1.4"/></svg>
                          <span class="ic-lbl">¼</span>
                        </button>
                        <button class="ic ic-sub" type="button" :class="{ on: selectedWidth === 'half' }" :disabled="!inlineSelectedItem" title="Half width" @click="setInlineWidth('half')">
                          <svg width="14" height="12" viewBox="0 0 14 12" fill="none"><rect x="1" y="1" width="5" height="10" rx="1.5" stroke="currentColor" stroke-width="1.4"/></svg>
                          <span class="ic-lbl">½</span>
                        </button>
                        <button class="ic ic-sub" type="button" :class="{ on: selectedWidth === 'full' }" :disabled="!inlineSelectedItem" title="Full width" @click="setInlineWidth('full')">
                          <svg width="14" height="12" viewBox="0 0 14 12" fill="none"><rect x="1" y="1" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.4"/></svg>
                          <span class="ic-lbl">Full</span>
                        </button>
                      </div>
                    </div>

                    <div class="vsep" />

                    <!-- Height group -->
                    <div class="ic-group" :class="{ open: inlineGroupOpen === 'height' }">
                      <button class="ic ic-group__trigger" type="button" :class="{ on: inlineGroupOpen === 'height' }" :disabled="!inlineSelectedItem" title="Height options" @click="toggleInlineGroup('height')">
                        <svg width="12" height="16" viewBox="0 0 12 16" fill="none">
                          <rect x="2" y="1" width="8" height="14" rx="2" stroke="currentColor" stroke-width="1.4"/>
                          <path d="M1 5h10M1 11h10" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" opacity=".55"/>
                        </svg>
                        <span class="ic-lbl">Height</span>
                      </button>
                      <div v-if="inlineGroupOpen === 'height'" class="ic-group__rail">
                        <button class="ic ic-sub" type="button" :class="{ on: selectedHeight === 's' && !isAutoHeight }" :disabled="!inlineSelectedItem" title="Small height" @click="setInlineHeight('s')">
                          <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><rect x="1" y="4" width="10" height="4" rx="1.4" stroke="currentColor" stroke-width="1.4"/></svg>
                          <span class="ic-lbl">S</span>
                        </button>
                        <button class="ic ic-sub" type="button" :class="{ on: selectedHeight === 'm' && !isAutoHeight }" :disabled="!inlineSelectedItem" title="Medium height" @click="setInlineHeight('m')">
                          <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><rect x="1" y="2.5" width="10" height="7" rx="1.4" stroke="currentColor" stroke-width="1.4"/></svg>
                          <span class="ic-lbl">M</span>
                        </button>
                        <button class="ic ic-sub" type="button" :class="{ on: selectedHeight === 'l' && !isAutoHeight }" :disabled="!inlineSelectedItem" title="Large height" @click="setInlineHeight('l')">
                          <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><rect x="1" y="1" width="10" height="10" rx="1.4" stroke="currentColor" stroke-width="1.4"/></svg>
                          <span class="ic-lbl">L</span>
                        </button>
                        <button class="ic ic-sub" type="button" :class="{ on: selectedHeight === 'xl' && !isAutoHeight }" :disabled="!inlineSelectedItem" title="Extra large height" @click="setInlineHeight('xl')">
                          <svg width="12" height="13" viewBox="0 0 12 13" fill="none"><rect x="1" y="1" width="10" height="11" rx="1.4" stroke="currentColor" stroke-width="1.4"/><path d="M4 5h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                          <span class="ic-lbl">XL</span>
                        </button>
                        <button class="ic ic-sub" type="button" :class="{ on: isAutoHeight }" :disabled="!inlineSelectedItem" title="Auto height" @click="toggleAutoHeight">
                          <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M6 1v10M3.5 3.5L6 1l2.5 2.5M3.5 8.5L6 11l2.5-2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                          <span class="ic-lbl">Auto</span>
                        </button>
                      </div>
                    </div>

                    <div class="vsep" />

                    <!-- Scale group -->
                    <div class="ic-group" :class="{ open: inlineGroupOpen === 'scale' }">
                      <button class="ic ic-group__trigger" type="button" :class="{ on: inlineGroupOpen === 'scale' }" :disabled="!inlineSelectedItem" title="Scale options" @click="toggleInlineGroup('scale')">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                          <path d="M3 11L11 3M6 3h5v5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                          <path d="M10 13H3V6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" opacity=".7"/>
                        </svg>
                        <span class="ic-lbl">Scale</span>
                      </button>
                      <div v-if="inlineGroupOpen === 'scale'" class="ic-group__rail">
                        <button class="ic ic-sub" type="button" :class="{ on: selectedScale === 'sm' }" :disabled="!inlineSelectedItem" title="Small scale" @click="setInlineScale('sm')">
                          <span class="ic-lbl">S-</span>
                        </button>
                        <button class="ic ic-sub" type="button" :class="{ on: selectedScale === 'md' }" :disabled="!inlineSelectedItem" title="Normal scale" @click="setInlineScale('md')">
                          <span class="ic-lbl">M</span>
                        </button>
                        <button class="ic ic-sub" type="button" :class="{ on: selectedScale === 'lg' }" :disabled="!inlineSelectedItem" title="Large scale" @click="setInlineScale('lg')">
                          <span class="ic-lbl">L+</span>
                        </button>
                        <button class="ic ic-sub" type="button" :class="{ on: selectedScale === 'xl' }" :disabled="!inlineSelectedItem" title="Extra large scale" @click="setInlineScale('xl')">
                          <span class="ic-lbl">XL</span>
                        </button>
                      </div>
                    </div>

                    <div class="vsep" />

                    <!-- Color group — controls the GLOBAL app / widget background -->
                    <div class="ic-group" :class="{ open: inlineGroupOpen === 'color' }">
                      <button class="ic ic-group__trigger" type="button" :class="{ on: inlineGroupOpen === 'color' }" title="App background color (applies to every widget by default)" @click="toggleInlineGroup('color')">
                        <span class="ic-color-dot" :class="{ 'ic-color-dot--none': !globalAppBg }" :style="globalAppBg ? { background: globalAppBg } : {}" />
                        <span class="ic-lbl">Color</span>
                      </button>
                      <div v-if="inlineGroupOpen === 'color'" class="ic-group__rail ic-group__rail--color">
                        <!-- Reset / no color -->
                        <button
                          type="button"
                          class="ic-color-swatch ic-color-reset"
                          :class="{ on: !globalAppBg }"
                          title="Follow theme"
                          @click.stop="() => { globalAppBg = null; inlineGroupOpen = null }"
                        />
                        <!-- Palette -->
                        <button
                          v-for="color in CARD_BG_PALETTE"
                          :key="color"
                          type="button"
                          class="ic-color-swatch"
                          :class="{ on: globalAppBg?.toUpperCase() === color.toUpperCase() }"
                          :style="{ background: color }"
                          :title="color"
                          @click.stop="() => { globalAppBg = color; inlineGroupOpen = null }"
                        />
                        <!-- Custom -->
                        <label class="ic-color-custom" title="Custom color">
                          <input
                            type="color"
                            :value="globalAppBg ?? '#ffffff'"
                            @change.stop="(e) => { globalAppBg = (e.target as HTMLInputElement).value; inlineGroupOpen = null }"
                          />
                        </label>
                      </div>
                    </div>

                    <!-- Widget config (WidgetOptionsMenu) -->
                    <div class="ic-config-wrap">
                      <WidgetOptionsMenu
                        v-if="inlineSelectedItem && widgetsRegistry[inlineSelectedItem.type]?.configurable"
                        :entry="widgetsRegistry[inlineSelectedItem.type]"
                        :options="inlineSelectedItem.options"
                        :open="inlineOptionsOpen"
                        :show-advanced="inlineSelectedItem.type === 'targets_v2'"
                        :context="widgetContext"
                        :tabs="layoutTabs.map(t => ({ id: t.id, label: t.label }))"
                        :current-tab-id="activeTabId"
                        @toggle="(nextOpen) => { inlineOptionsOpen = nextOpen }"
                        @open-advanced="handleOpenAdvancedFromInline"
                        @change="(key, val) => setSelectedOption(key, val)"
                        @move-to-tab="(tabId) => handleMoveWidgetToTab(inlineSelectedItem!.id, tabId)"
                        @duplicate-to-tab="(tabId) => handleDuplicateWidgetToTab(inlineSelectedItem!.id, tabId)"
                      />
                      <button v-else class="ic ic--config" type="button" disabled title="Widget settings">
                        <svg width="14" height="12" viewBox="0 0 14 12" fill="none">
                          <path d="M1 3h12M1 6h12M1 9h12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                          <circle cx="4" cy="3" r="1.5" stroke="currentColor" stroke-width="1.3"/>
                          <circle cx="10" cy="6" r="1.5" stroke="currentColor" stroke-width="1.3"/>
                          <circle cx="6" cy="9" r="1.5" stroke="currentColor" stroke-width="1.3"/>
                        </svg>
                        <span class="ic-lbl">Settings</span>
                      </button>
                    </div>

                    <!-- Move up / down -->
                    <button class="ic" type="button" :disabled="!inlineSelectedItem" title="Move earlier" @click="moveSelectedWidget('up')">
                      <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M6 9V3M3 6l3-3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                      <span class="ic-lbl">Up</span>
                    </button>
                    <button class="ic" type="button" :disabled="!inlineSelectedItem" title="Move later" @click="moveSelectedWidget('down')">
                      <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M6 3v6M3 6l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                      <span class="ic-lbl">Down</span>
                    </button>

                    <!-- Remove -->
                    <button class="ic ic-danger" type="button" :disabled="!inlineSelectedItem" title="Remove widget" @click="removeSelectedWidget">
                      <svg width="11" height="12" viewBox="0 0 11 12" fill="none"><path d="M1 3h9M3.5 3V2a.5.5 0 01.5-.5h2a.5.5 0 01.5.5v1M4.5 5.5v3.5M6.5 5.5v3.5M2 3l.6 7a.5.5 0 00.5.5h4.8a.5.5 0 00.5-.5L9 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                      <span class="ic-lbl">Remove</span>
                    </button>
                    </template>
                    <div v-else class="itb-empty-preview" aria-hidden="true">
                      <svg width="16" height="14" viewBox="0 0 14 12" fill="none">
                        <path d="M1 3h12M1 6h12M1 9h12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        <circle cx="4" cy="3" r="1.5" stroke="currentColor" stroke-width="1.3"/>
                        <circle cx="10" cy="6" r="1.5" stroke="currentColor" stroke-width="1.3"/>
                        <circle cx="6" cy="9" r="1.5" stroke="currentColor" stroke-width="1.3"/>
                      </svg>
                      <span>Settings and layout controls appear here</span>
                    </div>

                    </div>
                  </div>
                </div>
              </template>

            </div>
            </div>
            <div
              v-if="tabContext.open"
              class="tab-context-menu"
              :style="{ top: `${tabContext.y}px`, left: `${tabContext.x}px` }"
              role="menu"
            >
              <button type="button" role="menuitem" @click="setDefaultTabFromMenu">Set as default</button>
              <button type="button" role="menuitem" @click="renameTabFromMenu">Rename</button>
              <button
                type="button"
                role="menuitem"
                :disabled="layoutTabs.length <= 1"
                @click="removeTabFromMenu"
              >
                Remove
              </button>
            </div>

            <AddWidgetModal
              :open="showAddWidgetModal"
              :widget-type-list="availableWidgetTypesForStrategy"
              @close="showAddWidgetModal = false"
              @select="handleAddWidgetFromModal"
            />

            <div class="cards">
              <DashboardLayout
                ref="layoutRef"
                :widgets="widgets"
                :context="widgetContext"
                :editable="isLayoutEditing"
                :widget-types="availableWidgetTypesForStrategy"
                :preset-label="dashboardModeLabel"
                :tabs="layoutTabs.map((tab) => ({ id: tab.id, label: tab.label }))"
                :current-tab-id="activeTabId"
                @edit:width="cycleWidth"
                @edit:height="cycleHeight"
                @edit:remove="removeWidget"
                @edit:move="moveWidget"
                @edit:move-tab="handleMoveWidgetToTab"
                @edit:duplicate-tab="handleDuplicateWidgetToTab"
                @edit:options="updateWidgetOptions"
                @edit:add="addWidgetAt"
                @edit:reorder="reorderWidget"
                @open:onboarding="openOnboardingFromLayout"
                @reset:preset="applyDashboardPreset(dashboardMode.value)"
                @select:cell="setAddHint"
              />
            </div>

            <div class="hint footer">
              <template v-if="appVersion">
                Operational Dashboard • v{{ appVersion }} • Built by Blade34242 @ Gellert Innovation
              </template>
              <template v-else>
                Operational Dashboard • v{{ pkg?.version || '0.6.0' }} • Built by Blade34242 @ Gellert Innovation
              </template>
            </div>
          </div>
        </div>
      </div>
    </NcAppContent>
    <KeyboardShortcutsModal
      :visible="shortcutsOpen"
      :groups="shortcutGroups"
      :theme="effectiveTheme"
      @close="closeShortcuts"
    />
  </div>
</template>

<script setup lang="ts">
import { NcAppContent, NcLoadingIcon } from '@nextcloud/vue'
import Sidebar from './components/sidebar/Sidebar.vue'
import OnboardingWizard from './components/onboarding/OnboardingWizard.vue'
import ProfilesOverlay from './components/overlays/ProfilesOverlay.vue'
import KeyboardShortcutsModal from './components/modals/KeyboardShortcutsModal.vue'
import DashboardLayout from './components/layout/DashboardLayout.vue'
import AddWidgetModal from './components/layout/AddWidgetModal.vue'
import WidgetOptionsMenu from './components/layout/WidgetOptionsMenu.vue'
import { buildTargetsSummary, normalizeTargetsConfig, createEmptyTargetsSummary, createDefaultActivityCardConfig, createDefaultBalanceConfig, cloneTargetsConfig, convertWeekToMonth, type ActivityCardConfig, type BalanceConfig, type TargetsConfig } from './services/targets'
import { normalizeReportingConfig, normalizeDeckSettings, type DeckFilterMode } from './services/reporting'
import { globalAppBg, preferredScope } from '../composables/useGlobalPreferences'
import { ONBOARDING_VERSION, getStrategyDefinitions } from './services/onboarding'
import {
  createDefaultWidgetTabs,
  filterWidgetTabsForStrategy,
  normalizeWidgetTabs,
  widgetsRegistry,
} from './services/widgetsRegistry'
import { formatDateKey, getWeekNumber, parseDateKey } from './services/dateTime'
// Lightweight notifications without @nextcloud/dialogs
function notifySuccess(msg: string){
  const w:any = window as any
  if (w.OC?.Notification?.showTemporary) { w.OC.Notification.showTemporary(msg) }
  else { console.log('SUCCESS:', msg) }
}
function notifyError(msg: string){
  const w:any = window as any
  if (w.OC?.Notification?.showTemporary) { w.OC.Notification.showTemporary(msg) }
  else { console.error('ERROR:', msg); alert(msg) }
}

import { ref, watch, computed, onBeforeUnmount } from 'vue'
import { useDashboard, type OnboardingState } from '../composables/useDashboard'
import { useDashboardPersistence } from '../composables/useDashboardPersistence'
import { useDashboardSelection } from '../composables/useDashboardSelection'
import { useDashboardPresets } from '../composables/useDashboardPresets'
import { useChartScheduler } from '../composables/useChartScheduler'
import { useOcHttp } from '../composables/useOcHttp'
import { useAppMeta } from '../composables/useAppMeta'
import { useCalendarLinks } from '../composables/useCalendarLinks'
import { useCharts } from '../composables/useCharts'
import { useCategories } from '../composables/useCategories'
import { useSummaries } from '../composables/useSummaries'
import { useBalance } from '../composables/useBalance'
import { useThemeController } from '../composables/useThemeController'
import { useOnboardingFlow } from '../composables/useOnboardingFlow'
import { useVersionOverlay } from '../composables/useVersionOverlay'
import { useRangeToolbar } from '../composables/useRangeToolbar'
import { useConfigExportImport } from '../composables/useConfigExportImport'
import { useDetailsToggle } from '../composables/useDetailsToggle'
import { useSidebarState } from '../composables/useSidebarState'
import { useKeyboardShortcuts } from '../composables/useKeyboardShortcuts'
import { useOnboardingActions, type WizardStepSavePayload } from '../composables/useOnboardingActions'
import { useDeckCards } from '../composables/useDeckCards'
import { useDeckFiltering, sanitizeDeckFilter } from '../composables/useDeckFiltering'
import { useWidgetLayoutManager } from '../composables/useWidgetLayoutManager'
import { useDashboardBoot } from '../composables/useDashboardBoot'
import { useWidgetRenderContext } from '../composables/useWidgetRenderContext'
import { useLayoutTabsContext } from '../composables/useLayoutTabsContext'
import './styles/widgetTextScale.css'
import VersionNotesOverlay from './components/overlays/VersionNotesOverlay.vue'
// Ensure a visible version even if backend attrs are empty: use package.json as fallback
// @ts-ignore
import pkg from '../package.json'

type BalanceCategorySummary = {
  id: string
  label: string
  hours: number
  share: number
  prevShare: number
  delta: number
  color?: string
}

type BalanceOverviewSummary = {
  index: number
  categories: BalanceCategorySummary[]
  relations: { label: string; value: string }[]
  trend: { delta: Array<{ id: string; label: string; delta: number }>; badge: string }
  daily: Array<{ date: string; weekday: string; total_hours: number; categories: Array<{ id: string; label: string; hours: number; share: number }> }>
  warnings: string[]
} | null

const { navOpen, toggleNav, navToggleLabel, navToggleIcon } = useSidebarState()
const profilesOverlayOpen = ref(false)
function isCompactViewport() {
  return typeof window !== 'undefined' && window.matchMedia('(max-width: 1100px)').matches
}

function ensureSidebarVisible() {
  // Below the desktop breakpoint the navigation is an overlay. Keeping edit
  // mode active there would put the edit surface behind (or, when floating,
  // above) the sidebar, so leave edit mode before opening it.
  if (isLayoutEditing.value && isCompactViewport()) {
    toggleLayoutEditing()
  }
  if (!navOpen.value) {
    toggleNav()
  }
}

function openSidebarFromEdit() {
  ensureSidebarVisible()
}

function openProfilesPanel() {
  ensureSidebarVisible()
  profilesOverlayOpen.value = true
}

watch(
  () => navOpen.value,
  (next) => {
    if (typeof document === 'undefined') return
    const offset = next ? 'var(--app-navigation-width, 300px)' : '0px'
    document.body.style.setProperty('--opsdash-nav-offset', offset)
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  if (typeof document === 'undefined') return
  document.body.style.removeProperty('--opsdash-nav-offset')
  document.body.style.removeProperty('--opsdash-sticky-left')
})

const range = ref<'week'|'month'>('week')
const offset = ref<number>(0)

const truncTooltip = computed(()=>{
  const l:any = truncLimits.value
  if (!l) return 'Partial data due to caps'
  const parts = [] as string[]
  if (l.maxPerCal != null) parts.push(`cap per calendar: ${l.maxPerCal}`)
  if (l.maxTotal != null) parts.push(`cap total: ${l.maxTotal}`)
  if (l.totalProcessed != null) parts.push(`processed: ${l.totalProcessed}`)
  return parts.join(' · ')
})

const userChangedSelection = ref(false)

const { scheduleDraw } = useChartScheduler()

const { route, getJson, postJson, deleteJson, root } = useOcHttp()

const { calendarDayLink } = useCalendarLinks({ root })

// Widget layout storage must be declared before downstream composables consume it
const hasInitialLoad = ref(false)
const dashboardMode = ref<'quick' | 'standard' | 'pro'>('standard')
const widgetStrategy = ref<string | null>(null)
const widgetsQueueSaveRef = ref<null | ((silent?: boolean) => void)>(null)
const deckEnabledForWidgets = ref(true)

const {
  layoutTabs,
  defaultTabId,
  activeTabId,
  widgetsDirty,
  isLayoutEditing,
  newWidgetType,
  widgets,
  availableWidgetTypes,
  activeTab,
  setActiveTab,
  setDefaultTab,
  addTab,
  renameTab,
  removeTab,
  setTabsFromPayload,
  applyDashboardPreset,
  updateWidget,
  cycleWidth,
  cycleHeight,
  moveWidget,
  moveWidgetToTab,
  duplicateWidgetToTab,
  removeWidget,
  addWidgetAt,
  reorderWidget,
  updateWidgetOptions,
  resetWidgets,
} = useWidgetLayoutManager({
  storageKey: 'opsdash.widgets.v1',
  widgetsRegistry,
  createDefaultTabs: () => filterWidgetTabsForStrategy(createDefaultWidgetTabs(dashboardMode.value, widgetStrategy.value), widgetStrategy.value),
  normalizeWidgetTabs,
  dashboardMode,
  deckEnabled: deckEnabledForWidgets,
  hasInitialLoad,
  queueSaveRef: widgetsQueueSaveRef,
})

const widgetTabsState = computed(() => ({
  tabs: layoutTabs.value,
  defaultTabId: defaultTabId.value,
}))

const widgetTabsRef = computed({
  get: () => widgetTabsState.value,
  set: (value) => {
    if (!value) return
    setTabsFromPayload(value)
  },
})

// Time Off Trend rebuilds its values from the per-calendar history when a
// calendar/category filter is selected, so it needs the same history payload
// as the dedicated lookback charts.
const lookbackWidgetTypes = new Set(['chart_per_day', 'chart_dow', 'chart_hod', 'time_summary_lookback', 'time_summary_v2', 'dayoff_trend'])
const shouldIncludeLookback = () => {
  if (trendLookbackWeeks.value <= 1) return false
  for (const tab of layoutTabs.value || []) {
    const widgets = tab?.widgets || []
    for (const widget of widgets) {
      const type = String(widget?.type ?? '')
      if (!lookbackWidgetTypes.has(type)) continue
      if (type === 'time_summary_v2' && widget?.options?.showHistory === false) continue
      return true
    }
  }
  return false
}

const {
  tabLabelDraft,
  tabEditingId,
  tabContext,
  addOrderHint,
  handleTabClick,
  openTabContextMenu,
  openTabContextMenuFromButton,
  setDefaultTabFromMenu,
  removeTabFromMenu,
  renameTabFromMenu,
  commitTabLabel,
  cancelTabLabel,
  setAddHint,
  resetContext: resetTabEditContext,
} = useLayoutTabsContext({
  layoutTabs,
  activeTabId,
  activeTabLabel: computed(() => activeTab.value?.label || ''),
  isLayoutEditing,
  setActiveTab,
  setDefaultTab,
  removeTab,
  renameTab,
})

function handleAddWidget() {
  if (!newWidgetType.value) return
  const hint = Number.isFinite(addOrderHint.value ?? NaN) ? addOrderHint.value ?? undefined : undefined
  addWidgetAt(newWidgetType.value, hint)
  newWidgetType.value = ''
  addOrderHint.value = null
}

function handleMoveWidgetToTab(id: string, tabId: string) {
  const targetTab = layoutTabs.value.find((tab) => tab.id === tabId)
  if (!targetTab) return
  const moved = moveWidgetToTab(id, tabId)
  if (moved) {
    notifySuccess(`Moved widget to ${targetTab.label}`)
  }
}

function handleDuplicateWidgetToTab(id: string, tabId: string) {
  const targetTab = layoutTabs.value.find((tab) => tab.id === tabId)
  if (!targetTab) return
  const duplicated = duplicateWidgetToTab(id, tabId)
  if (duplicated) {
    notifySuccess(`Copied widget to ${targetTab.label}`)
  }
}

function toggleLayoutEditing() {
  const enteringEditMode = !isLayoutEditing.value
  // At compact widths the sidebar is fixed above the dashboard. Close it
  // before showing layout controls so borders and controls never overlap.
  if (enteringEditMode && navOpen.value && isCompactViewport()) {
    toggleNav()
  }
  isLayoutEditing.value = !isLayoutEditing.value
  if (!isLayoutEditing.value) {
    resetTabEditContext()
    inlineOptionsOpen.value = false
    inlineGroupOpen.value = null
  }
}

function keepCompactEditSurfaceClear() {
  if (isLayoutEditing.value && navOpen.value && isCompactViewport()) {
    toggleNav()
  }
}

// The Nextcloud sidebar toggle can also be reached without our app-bar
// button. Keep that path safe too: compact edit mode never shares its canvas
// with the overlay sidebar.
watch(navOpen, (open) => {
  if (open && isLayoutEditing.value && isCompactViewport()) {
    toggleLayoutEditing()
  }
})

// ── New bar helpers ────────────────────────────────────────
const showAddWidgetModal = ref(false)
const inlineOptionsOpen = ref(false)
const lastLoadedAt = ref<Date | null>(null)
const activePresetRef = ref<string | null>(null)
const itbFloating = ref(false)
const appBarSlotRef = ref<HTMLElement | null>(null)
const appBarRef = ref<HTMLElement | null>(null)
const itbRowRef = ref<HTMLElement | null>(null)
const itbRowSlotRef = ref<HTMLElement | null>(null)
let teardownItbScroll: null | (() => void) = null

function setupItbScroll() {
  teardownItbScroll?.()
  const container = document.querySelector('.app-main') as HTMLElement | null
  if (!container) return
  const update = () => {
    measureItbRow(container)
    check()
  }
  const check = () => {
    const row = itbRowRef.value
    if (!row || !isLayoutEditing.value) {
      itbFloating.value = false
      return
    }
    itbFloating.value = container.scrollTop > itbFloatThreshold
  }
  container.addEventListener('scroll', check, { passive: true })
  window.addEventListener('resize', update, { passive: true })
  teardownItbScroll = () => {
    container.removeEventListener('scroll', check)
    window.removeEventListener('resize', update)
  }
  update()
}

import { nextTick, onMounted, watch } from 'vue'
const itbRowHeight = ref(0)
let itbFloatThreshold = 0

function measureItbRow(container: HTMLElement) {
  const appBar = appBarRef.value
  const row = itbRowRef.value
  const slot = itbRowSlotRef.value
  if (!row || !appBar) {
    itbRowHeight.value = 0
    itbFloatThreshold = 0
    return
  }
  // A floating row is positioned against the viewport, while the app bar is
  // positioned inside the dashboard grid. Measure the real grid edge instead
  // of duplicating sidebar width and page-padding calculations in CSS.
  document.body.style.setProperty('--opsdash-sticky-left', `${Math.round(appBar.getBoundingClientRect().left)}px`)
  const floatTopOffset = getItbFloatTopOffset()
  const rowRect = row.getBoundingClientRect()
  const containerRect = container.getBoundingClientRect()
  const thresholdRect = (slot ?? row).getBoundingClientRect()
  itbRowHeight.value = Math.ceil(rowRect.height)
  itbFloatThreshold = Math.max(0, thresholdRect.top - containerRect.top + container.scrollTop - floatTopOffset)
}

function getItbFloatTopOffset() {
  if (typeof window === 'undefined') return 62
  const raw = getComputedStyle(document.documentElement).getPropertyValue('--header-height').trim()
  const headerHeight = Number.parseFloat(raw || '50')
  return (Number.isFinite(headerHeight) ? headerHeight : 50) + 12
}

onMounted(() => {
  setupItbScroll()
  window.addEventListener('resize', keepCompactEditSurfaceClear, { passive: true })
  keepCompactEditSurfaceClear()
})
watch(isLayoutEditing, async (editing) => {
  if (!editing) {
    itbFloating.value = false
    itbRowHeight.value = 0
    return
  }
  await nextTick()
  requestAnimationFrame(() => {
    setupItbScroll()
  })
})
onBeforeUnmount(() => {
  teardownItbScroll?.()
  window.removeEventListener('resize', keepCompactEditSurfaceClear)
})

function setRange(v: 'week' | 'month') {
  range.value = v
  offset.value = 0
  performLoad()
}

function handleAddWidgetFromModal(type: string) {
  showAddWidgetModal.value = false
  const hint = Number.isFinite(addOrderHint.value ?? NaN) ? addOrderHint.value ?? undefined : undefined
  addWidgetAt(type, hint)
  addOrderHint.value = null
}

const {
  calendars,
  colorsByName,
  colorsById,
  groupsById,
  selected,
  isInitialLoading,
  isRefreshing,
  isTruncated,
  truncLimits,
  uid,
  from,
  to,
  stats,
  byCal,
  byDay,
  longest,
  charts,
  targetsWeek,
  targetsMonth,
  targetsConfig,
  onboarding,
  load,
  themePreference: dashboardThemePreference,
  reportingConfig,
  deckSettings,
} = useDashboard({
  range,
  offset,
  userChangedSelection,
  route: (name) => route(name),
  getJson,
  postJson,
  notifyError,
  scheduleDraw,
  isDebug: isDbg,
  includeLookback: () => shouldIncludeLookback(),
  widgetTabs: widgetTabsRef,
  onCoreLoaded: (payload) => {
    if (!hasInitialLoad.value) {
      hasInitialLoad.value = true
    }
    evaluateOnboarding(payload?.onboarding ?? null)
    if (typeof payload?.activePreset === 'string' && payload.activePreset !== '') {
      activePresetRef.value = payload.activePreset
    }
  },
})

function handleReportingConfigSave(value: any) {
  reportingConfig.value = normalizeReportingConfig(value, reportingConfig.value)
  queueSave(false)
}

function handleDeckSettingsSave(value: any) {
  deckSettings.value = normalizeDeckSettings(value, deckSettings.value)
  const nextFilter = sanitizeDeckFilter(deckSettings.value.defaultFilter)
  deckFilter.value = nextFilter
  queueSave(false)
}

watch(
  () => deckSettings.value.enabled,
  (enabled) => {
    deckEnabledForWidgets.value = enabled
  },
  { immediate: true },
)

const {
  deckCards,
  deckLoading,
  deckLastFetchedAt,
  deckError,
  refreshDeck,
} = useDeckCards({
  from,
  to,
  notifyError,
})

const {
  deckFilter,
  deckVisibleCards,
  deckFilteredCards,
  deckSummaryBuckets,
  deckTickerConfig,
  deckCanFilterMine,
  deckUrl,
  deckFilterOptions,
} = useDeckFiltering({
  deckSettings,
  deckCards,
  uid,
  root,
})
const setDeckFilter = (value: DeckFilterMode) => {
  deckFilter.value = sanitizeDeckFilter(value)
}

const onboardingState = onboarding
watch(
  () => onboardingState.value?.strategy ?? null,
  (strategy) => {
    widgetStrategy.value = strategy
  },
  { immediate: true },
)

const {
  themePreference,
  effectiveTheme,
  systemTheme,
  setThemePreference,
} = useThemeController({
  serverPreference: dashboardThemePreference,
  route: (name) => route(name),
  postJson,
  notifySuccess,
  notifyError,
})
const opsdashThemeClass = computed(() =>
  effectiveTheme.value === 'dark' ? 'opsdash-theme-dark' : 'opsdash-theme-light',
)
const opsdashRootStyle = computed(() => {
  const bg = globalAppBg.value
  if (!bg) return {}
  // Cascade the chosen color into every widget via CSS custom properties
  // (both --bg for the page area and --card for widget surfaces).
  return { '--bg': bg, '--card': bg } as Record<string, string>
})

function openOnboardingFromLayout(step?: string) {
  openWizardFromSidebar((step as any) || 'goals')
}

const { exportSidebarConfig, importSidebarConfig } = useConfigExportImport({
  selected,
  groupsById,
  targetsWeek,
  targetsMonth,
  targetsConfig,
  themePreference,
  onboardingState,
  widgetTabs: widgetTabsRef,
  setThemePreference,
  postJson,
  route: (name) => route(name),
  performLoad: () => performLoad(),
  notifySuccess,
  notifyError,
})

const { queueSave, isSaving: reportingSaving } = useDashboardPersistence({
  route: (name) => route(name),
  postJson,
  notifyError,
  notifySuccess,
  onReload: () => performLoad(),
  selected,
  groupsById,
  targetsWeek,
  targetsMonth,
  targetsConfig,
  themePreference,
  reportingConfig,
  deckSettings,
  widgetTabs: widgetTabsRef,
  onboardingState,
  activePreset: activePresetRef,
  preferredScope,
  globalAppBg,
})

widgetsQueueSaveRef.value = queueSave

// Persist global preferences (preferredScope, globalAppBg) whenever they
// change. The initial load will set them from the server payload; a guard
// prevents that initial write from bouncing back as a redundant save.
watch([preferredScope, globalAppBg], () => {
  if (!hasInitialLoad.value) return
  queueSave(false)
})

// Goal strategies shape only a newly applied Standard template. They never
// restrict the picker or rewrite a dashboard the user has already customized.
const availableWidgetTypesForStrategy = availableWidgetTypes

const {
  isSelected,
  toggleCalendar,
  setGroup,
  setTarget,
  updateTargetsConfig,
  selectAll,
} = useDashboardSelection({
  calendars,
  selected,
  groupsById,
  targetsWeek,
  targetsMonth,
  targetsConfig,
  range,
  queueSave,
  userChangedSelection,
})

const {
  presets,
  presetsLoading,
  presetSaving,
  presetApplying,
  presetWarnings,
  lastLoadedPreset,
  refreshPresets,
  savePreset,
  loadPreset,
  deletePreset,
  clearPresetWarnings,
} = useDashboardPresets({
  route: (name, param) => route(name, param),
  getJson,
  postJson,
  deleteJson,
  notifyError,
  notifySuccess,
  queueSave,
  selected,
  groupsById,
  targetsWeek,
  targetsMonth,
  targetsConfig,
  themePreference,
  setThemePreference,
  reportingConfig,
  deckSettings,
  widgetTabs: widgetTabsRef,
  onboardingState,
  setDashboardMode: (mode) => { dashboardMode.value = mode },
  applyDashboardPreset: (mode) => { applyDashboardPreset(mode) },
  userChangedSelection,
})

watch(lastLoadedPreset, (val) => { activePresetRef.value = val }, { immediate: true })

const onboardingActions = useOnboardingActions({
  onboardingState,
  route: (name) => route(name),
  postJson,
  notifySuccess,
  notifyError,
  setThemePreference,
  savePreset,
  reloadAfterPersist: () => performLoad(),
  setSelected: (val) => { selected.value = [...val] },
  setTargetsWeek: (val) => { targetsWeek.value = { ...val } },
  setTargetsMonth: (val) => { targetsMonth.value = { ...val } },
  setTargetsConfig: (val) => { targetsConfig.value = cloneTargetsConfig(val) },
  setGroupsById: (val) => { groupsById.value = { ...val } },
  setDeckSettings: (val) => { deckSettings.value = { ...val } },
  setReportingConfig: (val) => { reportingConfig.value = { ...val } },
  setOnboardingState: (val) => { onboarding.value = { ...(onboarding.value || {}), ...val } as any },
  setDashboardMode: (mode) => { dashboardMode.value = mode },
  setWidgetTabs: (val) => { setTabsFromPayload(val) },
})

const {
  autoWizardNeeded,
  manualWizardOpen,
  onboardingRunId,
  onboardingWizardVisible,
  openWizardFromSidebar,
  wizardStartStep,
  hasExistingConfig,
  wizardCalendars,
  wizardInitialSelection,
  wizardInitialStrategy,
  wizardInitialAllDayHours,
  wizardInitialTotalHours,
  wizardInitialTargetsConfig,
  wizardInitialDeckSettings,
  wizardInitialReportingConfig,
  wizardInitialDashboardMode,
  wizardInitialCategories,
  wizardInitialAssignments,
  isOnboardingSaving,
  isSnapshotSaving: isWizardSnapshotSaving,
  snapshotNotice: wizardSnapshotNotice,
  evaluateOnboarding,
  handleWizardComplete: handleWizardCompleteFlow,
  handleWizardSkip,
  handleWizardClose,
  handleWizardSaveSnapshot,
} = useOnboardingFlow({
  onboardingState,
  calendars,
  selected,
  targetsWeek,
  groupsById,
  targetsConfig,
  deckSettings,
  reportingConfig,
  hasInitialLoad,
  actions: onboardingActions,
})

const handleWizardComplete = async (payload: any) => {
  await handleWizardCompleteFlow(payload)
  if (payload?.dashboardMode) {
    applyDashboardPreset(payload.dashboardMode)
  }
}

const handleWizardSaveStep = async (payload: WizardStepSavePayload) => {
  if (payload?.dashboardMode) {
    applyDashboardPreset(payload.dashboardMode)
  }
  await onboardingActions.saveStep(payload)
}

type TestReportPayload = {
  selected: string[]
  groups: Record<string, number>
  targetsConfig: Record<string, unknown>
  reportingConfig: Record<string, unknown>
}

async function sendTestReportWithOffset(payload: TestReportPayload, offset: number, label: string) {
  try {
    const result = await postJson(route('reportTestSend'), {
      range: range.value,
      offset,
      cals: payload.selected,
      groups: payload.groups,
      targets_config: payload.targetsConfig,
      reporting_config: payload.reportingConfig,
    })
    notifySuccess(`Test ${label} sent to ${result.email}`)
  } catch (error) {
    console.error(error)
    notifyError(`Failed to send test ${label}`)
  }
}

const handleWizardTestReport = (payload: TestReportPayload) =>
  sendTestReportWithOffset(payload, -1, 'recap')

const handleWizardCheckpointReport = (payload: TestReportPayload) =>
  sendTestReportWithOffset(payload, 0, 'checkpoint')

async function performLoad() {
  await load()
  lastLoadedAt.value = new Date()
  if (!hasInitialLoad.value) {
    hasInitialLoad.value = true
  }
  evaluateOnboarding()
}

const {
  showCollapsedRangeControls,
  rangeToggleLabel,
  rangeDateLabel,
  loadCurrent,
  toggleRangeCollapsed,
  goPrevious,
  goNext,
} = useRangeToolbar({
  navOpen,
  range,
  offset,
  from,
  to,
  isLoading: isInitialLoading,
  performLoad: () => performLoad(),
})

const {
  shortcutsOpen,
  openShortcuts,
  closeShortcuts,
  shortcutGroups,
} = useKeyboardShortcuts({
  goPrevious,
  goNext,
  toggleRange: toggleRangeCollapsed,
  openConfigPanel: () => ensureSidebarVisible(),
  toggleEditLayout: toggleLayoutEditing,
  openWidgetOptions: () => {
    if (!isLayoutEditing.value) return
    layoutRef.value?.openOptionsForSelected?.()
  },
  ensureSidebarVisible,
})

const { iconSrc, onIconError, appVersion } = useAppMeta({
  pingUrl: () => route('ping'),
  getJson,
  pkgVersion: pkg?.version ? String(pkg.version) : '',
  root,
})

const releaseNotesOverlayBlocked = computed(() =>
  onboardingWizardVisible.value || profilesOverlayOpen.value || shortcutsOpen.value,
)

const {
  isOpen: releaseNotesOverlayOpen,
  isSaving: isReleaseNotesSaving,
  entries: releaseNotesHistory,
  currentEntry: currentReleaseNotesEntry,
  activeEntry: activeReleaseNotesEntry,
  selectedVersion: selectedReleaseNotesVersion,
  openCurrent: openCurrentReleaseNotes,
  openVersion: openReleaseNotesVersion,
  closeOverlay: closeReleaseNotesOverlay,
} = useVersionOverlay({
  appVersion,
  onboardingState,
  hasInitialLoad,
  isBlocked: releaseNotesOverlayBlocked,
  route: (name) => route(name),
  postJson,
  notifyError,
})

const releaseNotesAvailable = computed(() => Boolean(currentReleaseNotesEntry.value))

function handleReleaseNotesAction(type: string) {
  if (type === 'reload') {
    // applyDashboardPreset updates local widget state reactively and queues
    // a server save (debounced). Do NOT call performLoad() here — it would
    // race the debounced save and overwrite the reset with stale server data.
    applyDashboardPreset(dashboardMode.value)
    return
  }

  if (type === 'open_preferences') {
    closeReleaseNotesOverlay()
    openWizardFromSidebar('preferences')
  }
}

const activeDayMode = ref<'active'|'all'>('active')
const rangeLabel = computed(()=> range.value === 'month' ? 'Month' : 'Week')
const rangeEyebrow = computed(() => range.value === 'month' ? 'This month' : 'This week')
const rangeHeadline = computed(() => {
  const date = parseDateKey(from.value)
  if (!date) return range.value === 'month' ? 'Month' : 'Week'
  if (range.value === 'month') {
    return `Month ${date.getUTCMonth() + 1}`
  }
  return `Week ${getWeekNumber(date)}`
})
const layoutRef = ref<InstanceType<typeof DashboardLayout> | null>(null)

const rangeBadgePrimary = computed(() => {
  const date = parseDateKey(from.value)
  if (!date) return range.value.toUpperCase()
  if (range.value === 'month') {
    return `MONTH ${date.getUTCMonth() + 1}`
  }
  return `WEEK ${getWeekNumber(date)}`
})

const rangeBadgeSecondary = computed(() => rangeDateLabel.value)

const targetsConfigForRange = computed(() => {
  const base = cloneTargetsConfig(targetsConfig.value)
  if (range.value === 'month') {
    base.totalHours = convertWeekToMonth(base.totalHours)
    base.categories = base.categories.map((cat) => ({
      ...cat,
      targetHours: convertWeekToMonth(cat.targetHours),
    }))
  }
  return base
})

const targetsSummary = computed(() => {
  const cfg = targetsConfigForRange.value
  try {
    return buildTargetsSummary({
      config: cfg,
      stats,
      byDay: byDay.value || [],
      byCal: byCal.value || [],
      groupsById: groupsById.value || {},
      range: range.value,
      from: from.value,
      to: to.value,
    })
  } catch (e) {
    console.error('[opsdash] targets summary failed', e)
    return createEmptyTargetsSummary(cfg)
  }
})

const currentTargets = computed<Record<string, number>>(() => {
  if (range.value === 'month') {
    const monthMap = targetsMonth.value || {}
    if (monthMap && Object.keys(monthMap).length > 0) {
      return monthMap
    }
    const weekMap = targetsWeek.value || {}
    const converted: Record<string, number> = {}
    Object.entries(weekMap).forEach(([id, value]) => {
      const num = Number(value)
      if (Number.isFinite(num)) {
        converted[id] = convertWeekToMonth(num)
      }
    })
    return converted
  }
  return targetsWeek.value || {}
})

const activityCardConfig = computed<ActivityCardConfig>(() => {
  return { ...createDefaultActivityCardConfig(), ...(targetsConfig.value?.activityCard ?? {}) }
})

const balanceConfigFull = computed<BalanceConfig>(() => {
  const base = createDefaultBalanceConfig()
  const cfg = targetsConfig.value?.balance ?? base
  return {
    ...base,
    ...cfg,
    thresholds: { ...base.thresholds, ...(cfg.thresholds ?? {}) },
    relations: { ...base.relations, ...(cfg.relations ?? {}) },
    trend: { ...base.trend, ...(cfg.trend ?? {}) },
    dayparts: { ...base.dayparts, ...(cfg.dayparts ?? {}) },
    ui: { ...base.ui, ...(cfg.ui ?? {}) },
  }
})

const balanceCardConfig = computed(() => ({
  showNotes: !!balanceConfigFull.value.ui.showNotes,
}))
const trendLookbackWeeks = computed(() =>
  Math.max(1, Math.min(6, balanceConfigFull.value.trend?.lookbackWeeks ?? 1)),
)
const globalLookbackLabel = computed(() => {
  const count = trendLookbackWeeks.value
  if (count <= 1) return ''
  const unit = range.value === 'month' ? 'month' : 'week'
  return `Last ${count} ${count === 1 ? unit : `${unit}s`}`
})

const balanceNote = computed(() => '')

const { categoryLabelById, categoryColorMap, calendarCategoryMap, calendarGroups } = useCategories({
  calendars,
  selected,
  groupsById,
  colorsById,
  targetsConfig: targetsConfigForRange,
  targetsSummary,
  byCal,
  currentTargets,
  isDebug: isDbg,
})

const { balanceOverview } = useBalance({
  stats,
  categoryColorMap,
  balanceCardConfig,
})

const categoryMappingHint = computed(() => {
  if (!activityCardConfig.value.showHint) return ''
  const order: any[] = Array.isArray(targetsConfig.value?.balance?.categories)
    ? targetsConfig.value.balance.categories
    : []
  const labels = order
    .map((raw) => {
      const id = String(raw ?? '').trim()
      if (!id) return ''
      const label = categoryLabelById.value[id]
      return label ? label : id.toUpperCase()
    })
    .filter((label) => label)
  if (!labels.length) return ''
  return `Mapping via Sidebar – ${labels.join(' / ')}`
})

const { calendarChartData, categoryChartsById } = useCharts({
  charts,
  colorsById,
  colorsByName,
  calendarGroups,
  calendarCategoryMap,
  targetsConfig: targetsConfigForRange,
  currentTargets,
  activityCardConfig,
})

const calendarTodayHours = computed<Record<string, number>>(() => {
  const stacked = calendarChartData.value?.stacked
  if (!stacked || !Array.isArray(stacked.labels) || !Array.isArray(stacked.series)) return {}
  const todayKey = formatDateKey(new Date())
  const idx = stacked.labels.findIndex((label: any) => String(label ?? '') === todayKey)
  if (idx < 0) return {}
  const map: Record<string, number> = {}
  stacked.series.forEach((row: any) => {
    const id = String(row?.id ?? '')
    const raw = Number(row?.data?.[idx] ?? 0)
    const val = Number.isFinite(raw) ? Math.max(0, raw) : 0
    if (!id) return
    map[id] = (map[id] || 0) + val
  })
  return map
})

const categoryTodayHours = computed<Record<string, number>>(() => {
  const stacked = calendarChartData.value?.stacked
  const labels = stacked?.labels
  const series = stacked?.series
  if (!stacked || !Array.isArray(labels) || !Array.isArray(series)) return {}
  const todayKey = formatDateKey(new Date())
  const idx = labels.findIndex((label: any) => String(label ?? '') === todayKey)
  if (idx < 0) return {}
  const map: Record<string, number> = {}
  series.forEach((row: any) => {
    const calId = String(row?.id ?? '')
    const catId = calendarCategoryMap.value?.[calId]
    if (!catId) return
    const raw = Number(row?.data?.[idx] ?? 0)
    const val = Number.isFinite(raw) ? Math.max(0, raw) : 0
    map[catId] = (map[catId] || 0) + val
  })
  return map
})

const calendarGroupsWithToday = computed(() =>
  calendarGroups.value.map((group) => ({
    ...group,
    todayHours: categoryTodayHours.value[group.id] || 0,
  })),
)

const topCategory = computed(() => {
  const groups = calendarGroups.value || []
  if (!groups.length) return null
  const ranked = [...groups].sort((a, b) => (b.summary.actualHours || 0) - (a.summary.actualHours || 0))
  return ranked[0] || null
})

const { timeSummary, activitySummary, activityDayOffTrend } = useSummaries({
  stats,
  byDay,
  charts,
  calendars,
  selected,
  rangeLabel,
  rangeStart: from,
  rangeEnd: to,
  offset,
  activeDayMode,
  topCategory,
})

const { detailsIndex, toggle: toggleDetails } = useDetailsToggle()
function isDbg(){ return false }

const { widgetContext } = useWidgetRenderContext({
  timeSummary,
  activeDayMode,
  targetsSummary,
  targetsConfig,
  stats,
  byDay,
  byCal,
  groupsById,
  calendarGroupsWithToday,
  balanceOverview,
  balanceCardConfig,
  rangeLabel,
  range,
  offset,
  from,
  to,
  trendLookbackWeeks,
  balanceNote,
  activitySummary,
  activityCardConfig,
  activityDayOffTrend,
  deckSummaryBuckets,
  deckLoading,
  deckError,
  deckTickerConfig,
  deckFilter,
  setDeckFilter,
  deckSettings,
  deckUrl,
  deckCards,
  refreshDeck,
  uid,
  isLoading: isInitialLoading,
  isInitialLoading,
  isRefreshing,
  hasInitialLoad,
  isLayoutEditing,
  updateWidgetOptions,
  charts,
  calendarChartData,
  categoryChartsById,
  calendarGroups,
  calendars,
  calendarCategoryMap,
  categoryColorMap,
  colorsById,
  colorsByName,
  currentTargets,
  targetsWeek,
  selected,
  calendarTodayHours,
  categoryTodayHours,
  onboardingStrategy: computed(() => onboardingState.value?.strategy ?? null),
  activePreset: activePresetRef,
})

const dashboardModeLabel = computed(() => {
  if (dashboardMode.value === 'quick') return 'Empty preset'
  if (dashboardMode.value === 'pro') return 'Advanced preset'
  return 'Standard preset'
})

function n1(v:any){ return Number(v ?? 0).toFixed(1) }
function n2(v:any){ return Number(v ?? 0).toFixed(2) }
function arrow(v:number){ return v>0?'▲':(v<0?'▼':'—') }
function fmtHours(v:any){
  const num = Number(v ?? 0)
  if (!Number.isFinite(num)) return '0'
  return Number.isInteger(num) ? String(num) : num.toFixed(1)
}

const strategyTitle = computed(() => {
  const strategyId = onboardingState.value?.strategy
  if (!strategyId) return '—'
  const match = getStrategyDefinitions().find((def) => def.id === strategyId)
  return match?.title ?? String(strategyId)
})

const selectedCalendarLabels = computed(() => {
  const map = new Map(
    (calendars.value || []).map((cal: any) => [
      String(cal?.id ?? ''),
      String(cal?.displayname ?? cal?.name ?? cal?.calendar ?? cal?.id ?? 'Calendar'),
    ]),
  )
  const labels = (selected.value || [])
    .map((id) => map.get(String(id)))
    .filter((value): value is string => !!value)
  return labels.length ? labels : ['None selected']
})

function compactList(items: string[], maxItems: number, separator = ', '): string {
  const filtered = items.filter((item) => item && item !== 'None selected')
  const shown = filtered.slice(0, maxItems)
  const extra = filtered.length - shown.length
  if (!shown.length) return 'None selected'
  return extra > 0 ? `${shown.join(separator)} +${extra}` : shown.join(separator)
}

function compactJoin(items: string[], maxItems: number, separator = ' · '): string {
  const filtered = items.filter(Boolean)
  const shown = filtered.slice(0, maxItems)
  const extra = filtered.length - shown.length
  if (!shown.length) return ''
  return extra > 0 ? `${shown.join(separator)} +${extra}` : shown.join(separator)
}

function compactTargetLine(line: string): string {
  return line.replace(/\s+—\s+/g, ' ').replace(/\s+h$/, 'h')
}

const targetsPreviewLines = computed(() => {
  const categories = targetsConfig.value?.categories ?? []
  if (!categories.length) return ['No category targets']
  return categories.map((cat) => `${cat.label || cat.id} — ${fmtHours(cat.targetHours)} h`)
})

const totalWeeklyTargetLine = computed(() => `${n1(targetsConfig.value?.totalHours ?? 0)} h`)

const dashboardLayoutLine = computed(() => {
  if (dashboardMode.value === 'pro') return 'Advanced layout'
  if (dashboardMode.value === 'quick') return 'Empty layout'
  return 'Standard layout'
})

const themeShort = computed(() =>
  effectiveTheme.value === 'dark' ? 'Dark' : 'Light',
)

const deckSummary = computed(() => {
  if (!deckSettings.value?.enabled) {
    return { status: 'Deck tab disabled', boards: [] as string[] }
  }
  const hidden = new Set((deckSettings.value.hiddenBoards || []).map((id: any) => Number(id)))
  const boardMap = new Map<number, string>()
  ;(deckCards.value || []).forEach((card: any) => {
    const boardId = Number(card?.boardId)
    if (!Number.isFinite(boardId) || hidden.has(boardId)) return
    const title = String(card?.boardTitle ?? `Board ${boardId}`)
    if (!boardMap.has(boardId)) boardMap.set(boardId, title)
  })
  const boards = Array.from(boardMap.values())
  const status = `Showing ${boards.length} board${boards.length === 1 ? '' : 's'}`
  return { status, boards }
})

const deckLine = computed(() => {
  if (!deckSettings.value?.enabled) return 'Deck — off'
  if (deckSummary.value.boards.length === 1) {
    return `Deck — ${deckSummary.value.boards[0]}`
  }
  return `Deck — ${deckSummary.value.boards.length} boards`
})

const targetsLine = computed(() => {
  const lines = targetsPreviewLines.value
  if (!lines.length) return ''
  if (lines.length === 1 && lines[0] === 'No category targets') return 'No targets'
  const compact = compactJoin(lines.map(compactTargetLine), 3)
  if (!compact) return ''
  return `${compact} · Total ${totalWeeklyTargetLine.value}`
})

const dashboardHint = computed(() =>
  compactJoin(
    [
      dashboardLayoutLine.value,
    ],
    2,
  ),
)

const strategyHint = computed(() => strategyTitle.value && strategyTitle.value !== '—' ? strategyTitle.value : 'Choose a planning model')

const calendarsHint = computed(() => compactList(selectedCalendarLabels.value, 2))

const deckHint = computed(() => deckLine.value)

const preferencesHint = computed(() =>
  compactJoin([`Theme — ${themeShort.value}`, reportingConfig.value?.enabled ? 'Recap on' : 'Recap off'], 2),
)

const reviewHint = computed(() =>
  compactJoin(
    [
      totalWeeklyTargetLine.value ? `Total ${totalWeeklyTargetLine.value}` : '',
    ],
    2,
  ),
)

const guidedHints = computed(() => ({
  strategy: strategyHint.value,
  deck: deckHint.value,
  goals: targetsLine.value,
  dashboard: dashboardHint.value,
  calendars: calendarsHint.value,
  preferences: preferencesHint.value,
  review: reviewHint.value,
}))

const guidedStepStatuses = computed(() => ({
  strategy: onboardingState.value?.strategy ? 'done' : 'dim',
  calendars: (selected.value?.length ?? 0) > 0 ? 'done' : 'dim',
  deck: deckSettings.value?.enabled ? 'done' : 'skip',
  goals: (targetsConfig.value?.categories?.length ?? 0) > 0 ? 'done' : 'warn',
  preferences: 'done',
  dashboard: (widgets.value?.length ?? 0) > 0 ? 'done' : 'dim',
  review: hasExistingConfig.value ? 'done' : 'dim',
} as const))

const lastSyncLabel = computed<string | null>(() => {
  if (isRefreshing.value) return 'Syncing…'
  if (!lastLoadedAt.value) return null
  const mins = Math.floor((Date.now() - lastLoadedAt.value.getTime()) / 60000)
  return mins < 1 ? 'Just now' : `${mins}m ago`
})

// ── Inline widget controls ──────────────────────────────────
const inlineSelectedItem = computed(() => layoutRef.value?.selectedItem ?? null)

const inlineSelectedItemTitle = computed(() => {
  if (!inlineSelectedItem.value) return ''
  const entry = availableWidgetTypesForStrategy.value.find((e: any) => e.type === inlineSelectedItem.value!.type)
  return inlineSelectedItem.value.options?.titlePrefix || entry?.label || inlineSelectedItem.value.type
})

const inlineSelectedItemType = computed(() => {
  if (!inlineSelectedItem.value) return ''
  const entry = availableWidgetTypesForStrategy.value.find((e: any) => e.type === inlineSelectedItem.value!.type)
  return entry?.label || inlineSelectedItem.value.type
})

const selectedWidth = computed(() => inlineSelectedItem.value?.layout?.width ?? null)
const selectedHeight = computed(() => inlineSelectedItem.value?.layout?.height ?? null)
const isAutoHeight = computed(() => inlineSelectedItem.value?.heightMode === 'auto')
const selectedCardBg = computed(() => inlineSelectedItem.value?.options?.cardBg ?? null)
const selectedTitlePrefix = computed(() => inlineSelectedItem.value?.options?.titlePrefix ?? '')
const selectedScale = computed(() => inlineSelectedItem.value?.options?.scale ?? inlineSelectedItem.value?.options?.textSize ?? 'md')
const CARD_BG_PALETTE = computed(() =>
  effectiveTheme.value === 'dark'
    ? ['#1e293b', '#1e3a5f', '#2d1b69', '#14532d', '#451a03', '#4c0519', '#f8fafc']
    : ['#ffffff', '#DBEAFE', '#EDE9FE', '#DCFCE7', '#FEF3C7', '#FFE4E6', '#1E293B'],
)
const inlineGroupOpen = ref<null | 'width' | 'height' | 'scale' | 'color'>(null)

function setSelectedOption(key: string, value: any) {
  if (!inlineSelectedItem.value) return
  updateWidgetOptions(inlineSelectedItem.value.id, key, value)
}

function moveSelectedWidget(dir: 'up' | 'down') {
  if (!inlineSelectedItem.value) return
  moveWidget(inlineSelectedItem.value.id, dir)
}

function removeSelectedWidget() {
  if (!inlineSelectedItem.value) return
  removeWidget(inlineSelectedItem.value.id)
}

function toggleAutoHeight() {
  if (!inlineSelectedItem.value) return
  setSelectedOption('heightMode', isAutoHeight.value ? 'fixed' : 'auto')
}

function setInlineScale(target: 'sm' | 'md' | 'lg' | 'xl') {
  if (!inlineSelectedItem.value) return
  setSelectedOption('scale', target)
}

function toggleInlineGroup(group: 'width' | 'height' | 'scale' | 'color') {
  if (!inlineSelectedItem.value) return
  inlineGroupOpen.value = inlineGroupOpen.value === group ? null : group
}

function setInlineWidth(target: 'quarter' | 'half' | 'full') {
  if (!inlineSelectedItem.value) return
  const order = ['quarter', 'half', 'full'] as const
  const cur = order.indexOf(inlineSelectedItem.value.layout.width as any)
  const tgt = order.indexOf(target)
  if (cur === -1 || tgt === -1 || cur === tgt) return
  const id = inlineSelectedItem.value.id
  const times = (tgt - cur + 3) % 3
  for (let i = 0; i < times; i++) cycleWidth(id)
}

function setInlineHeight(target: 's' | 'm' | 'l' | 'xl') {
  if (!inlineSelectedItem.value) return
  const order = ['s', 'm', 'l', 'xl'] as const
  const cur = order.indexOf(inlineSelectedItem.value.layout.height as any)
  const tgt = order.indexOf(target)
  if (cur === -1 || tgt === -1 || cur === tgt) return
  const id = inlineSelectedItem.value.id
  const times = (tgt - cur + 4) % 4
  for (let i = 0; i < times; i++) cycleHeight(id)
}

function handleOpenAdvancedFromInline() {
  if (!inlineSelectedItem.value) return
  inlineOptionsOpen.value = false
  layoutRef.value?.openAdvancedTargets(inlineSelectedItem.value.id)
}

useDashboardBoot({
  performLoad,
  refreshPresets,
  onboardingState,
  hasInitialLoad,
  evaluateOnboarding,
  dashboardMode,
})
</script>

<!-- styles moved to global css/style.css to satisfy strict CSP -->
