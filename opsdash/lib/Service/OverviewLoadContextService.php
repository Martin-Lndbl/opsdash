<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

final class OverviewLoadContextService {
    private const MAX_GROUP = 9;
    private const ONBOARDING_VERSION = 1;

    public function __construct(
        private CalendarAccessService $calendarAccess,
        private CalendarColorService $calendarColors,
        private PersistSanitizer $persistSanitizer,
        private UserConfigService $userConfigService,
        private OverviewSelectionService $selectionService,
        private OverviewIncludeResolver $includeResolver,
        private DashboardDefaultsService $dashboardDefaults,
        private \OCP\IConfig $config,
    ) {}

    /**
     * @param array{
     *   range: string,
     *   offset: int,
     *   provided: bool,
     *   cals: array|null,
     *   debug: bool,
     *   forceReset: bool,
     *   include: string[]
     * } $request
     * @return array<string,mixed>
     */
    public function build(string $appName, string $uid, array $request): array {
        $range = $request['range'];
        $offset = $request['offset'];
        $provided = $request['provided'];
        $calsOverride = $request['cals'] ?? null;
        $dbgFlag = $this->userConfigService->isDebugEnabled() && !empty($request['debug']);
        $forceReset = !empty($request['forceReset']);

        $includes = $this->includeResolver->resolve($request['include'] ?? []);
        $flags = $this->includeResolver->buildFlags($includes);
        $includeAll = $flags['includeAll'];
        $includeDebugRequested = $flags['includeDebugRequested'];
        $includeDebug = $dbgFlag && $includeDebugRequested;
        $includeLookback = $flags['includeLookback'];
        $includeCharts = $flags['includeCharts'];
        $includeData = $flags['includeData'];

        $shouldInclude = fn(string $key): bool => $includeAll || isset($includes[$key]);
        $includeTargets = $includeData || $shouldInclude('targets');
        $includeTargetsConfig = $includeData || $shouldInclude('targetsConfig');
        $includeReportingConfig = $includeData || $shouldInclude('reportingConfig');
        $includeDeckSettings = $includeData || $shouldInclude('deckSettings');
        $includeWidgets = $shouldInclude('widgets');
        $includeOnboarding = $shouldInclude('onboarding');
        $includeTheme = $shouldInclude('themePreference');

        $userTz = $this->calendarAccess->resolveUserTimezone($uid);
        $userTzName = $userTz->getName();
        $userLocale = $this->resolveUserLocale($uid);
        $userWeekStart = $this->calendarAccess->resolveUserWeekStart($uid);

        [$from, $to] = $this->calendarAccess->rangeBounds($range, $offset, $userTz, $userWeekStart);
        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr   = $to->format('Y-m-d H:i:s');

        // Distinguish between: no saved value vs saved empty list (user config)
        $savedRaw = (string)$this->config->getUserValue($uid, $appName, 'selected_cals', '__UNSET__');
        $sel = $this->selectionService->resolveInitialSelection($savedRaw, $provided, $calsOverride);
        $hasSaved = $sel['hasSaved'];
        $savedIds = $sel['savedIds'];
        $selectedIds = $sel['selectedIds'];

        $cals = $this->calendarAccess->getCalendarsFor($uid);
        $principal = 'principals/users/' . $uid;

        $sidebar = [];
        $idToName = [];
        $colorsById = [];
        $colorsByName = [];
        $calDebug = [];
        $includeAllSelection = $sel['includeAll'];
        foreach ($cals as $cal) {
            $id   = (string)($cal->getUri() ?? spl_object_id($cal));
            $name = $cal->getDisplayName() ?: ($cal->getUri() ?? 'calendar');
            $colorInfo = $this->calendarColors->resolveCalendarColor($cal, $name !== '' ? $name : $id);
            $color = $colorInfo['color'];
            $raw = $colorInfo['raw'];
            $src = $colorInfo['source'];
            $sidebar[] = [
                'id'=>$id, 'displayname'=>$name, 'color'=>$color,
                'color_raw'=>$raw, 'color_src'=>$src,
                // checked: if no saved selection exists, default to all checked; otherwise use saved/toggled set
                'checked'=> $includeAllSelection ? true : in_array($id, $selectedIds, true)
            ];
            $colorsById[$id] = $color; $colorsByName[$name] = $color; $idToName[$id] = $name;
            if ($includeDebug) { $calDebug[$id] = ['name'=>$name,'raw'=>$raw,'norm'=>$color,'src'=>$src]; }
        }
        $calendarIds = array_map(fn($x) => $x['id'], $sidebar);

        // Selected list to return to client: if not provided and no saved, default to all calendars
        $selectedIds = $this->selectionService->finalizeSelectedIds(
            $includeAllSelection,
            $calendarIds,
            $selectedIds,
        );

        // Read user-defined calendar groups (id -> 0..9). Default missing IDs to 0.
        $groupsById = [];
        try {
            $gjson = (string)$this->config->getUserValue($uid, $appName, 'cal_groups', '');
            if ($gjson !== '') { $tmp = json_decode($gjson, true); if (is_array($tmp)) $groupsById = $tmp; }
        } catch (\Throwable) {}
        $allowedSet = array_flip($calendarIds);
        $groupsById = $this->persistSanitizer->cleanGroups($groupsById, $allowedSet, $calendarIds);

        // Per-calendar targets (hours) for week and month
        $targetsWeek = [];
        $targetsMonth = [];
        if ($includeTargets) {
            try {
                $tw = (string)$this->config->getUserValue($uid, $appName, 'cal_targets_week', '');
                $tm = (string)$this->config->getUserValue($uid, $appName, 'cal_targets_month', '');
                if ($tw !== '') { $tmp = json_decode($tw, true); if (is_array($tmp)) $targetsWeek = $tmp; }
                if ($tm !== '') { $tmp = json_decode($tm, true); if (is_array($tmp)) $targetsMonth = $tmp; }
            } catch (\Throwable) {}
            // Clean: only allow known ids, clamp values
            $targetsWeek = $this->persistSanitizer->cleanTargets($targetsWeek, $allowedSet);
            $targetsMonth = $this->persistSanitizer->cleanTargets($targetsMonth, $allowedSet);
        }

        $targetsConfig = [];
        if ($includeTargetsConfig) {
            $targetsConfig = $this->userConfigService->readTargetsConfig($appName, $uid);
        }

        // Derive category metadata and group mapping for balance calculations
        $categoryMeta = [];
        $groupToCategory = [];
        if ($includeData && !empty($targetsConfig['categories']) && is_array($targetsConfig['categories'])) {
            foreach ($targetsConfig['categories'] as $cat) {
                if (!is_array($cat)) continue;
                $catId = substr((string)($cat['id'] ?? ''), 0, 64);
                if ($catId === '') continue;
                $label = trim((string)($cat['label'] ?? '')) ?: ucfirst($catId);
                $categoryMeta[$catId] = ['id'=>$catId, 'label'=>$label];
                if (!empty($cat['groupIds']) && is_array($cat['groupIds'])) {
                    foreach ($cat['groupIds'] as $gid) {
                        $n = (int)$gid;
                        if ($n < 0 || $n > self::MAX_GROUP) continue;
                        $groupToCategory[$n] = $catId;
                    }
                }
            }
        }
        $categoryMeta['__uncategorized__'] = ['id'=>'__uncategorized__', 'label'=>'Unassigned'];
        if ($includeTargetsConfig && (!isset($targetsConfig['balance']) || !is_array($targetsConfig['balance']))) {
            $targetsConfig['balance'] = $this->defaultBalanceConfig();
        }

        $reportingConfig = $includeReportingConfig
            ? $this->userConfigService->readReportingConfig($appName, $uid)
            : [];
        $deckSettings = $includeDeckSettings
            ? $this->userConfigService->readDeckSettings($appName, $uid)
            : [];
        $themePreference = $includeTheme
            ? $this->userConfigService->readThemePreference($appName, $uid)
            : 'auto';
        $activePreset = (string)$this->config->getUserValue($uid, $appName, 'active_preset', '');
        $prefScopeRaw = (string)$this->config->getUserValue($uid, $appName, 'preferred_scope', '');
        $preferredScope = ($prefScopeRaw === 'calendar' || $prefScopeRaw === 'category') ? $prefScopeRaw : null;
        $bgRaw = (string)$this->config->getUserValue($uid, $appName, 'global_app_bg', '');
        $globalAppBg = (is_string($bgRaw) && preg_match('/^#[0-9a-fA-F]{6}$/', $bgRaw) === 1) ? $bgRaw : null;
        $bgLightRaw = (string)$this->config->getUserValue($uid, $appName, 'global_app_bg_light', '');
        $globalAppBgLight = (is_string($bgLightRaw) && preg_match('/^#[0-9a-fA-F]{6}$/', $bgLightRaw) === 1) ? $bgLightRaw : null;
        $bgDarkRaw = (string)$this->config->getUserValue($uid, $appName, 'global_app_bg_dark', '');
        $globalAppBgDark = (is_string($bgDarkRaw) && preg_match('/^#[0-9a-fA-F]{6}$/', $bgDarkRaw) === 1) ? $bgDarkRaw : null;

        $onboardingPayload = [];
        if ($includeOnboarding) {
            $onboardingState = $this->userConfigService->readOnboardingState($appName, $uid);
            $needsOnboarding = !$onboardingState['completed'] || $onboardingState['version'] < self::ONBOARDING_VERSION;
            if ($forceReset) {
                $needsOnboarding = true;
            }
            $onboardingPayload = $onboardingState;
            $onboardingPayload['version_required'] = self::ONBOARDING_VERSION;
            $onboardingPayload['needsOnboarding'] = $needsOnboarding;
            if ($forceReset) {
                $onboardingPayload['resetRequested'] = true;
            }
        }

        $widgets = [];
        $widgetPresets = [];
        $dashboardMode = 'standard';
        if (!empty($onboardingPayload['dashboardMode'])) {
            $candidate = (string)$onboardingPayload['dashboardMode'];
            if (in_array($candidate, ['quick', 'standard', 'pro'], true)) {
                $dashboardMode = $candidate;
            }
        }
        $dashboardStrategy = (string)($onboardingPayload['strategy'] ?? '');
        if ($includeWidgets) {
            $widgetPresets = $this->dashboardDefaults->getPresets();
            try {
                $widgetsRaw = (string)$this->config->getUserValue($uid, $appName, 'widgets_layout', '');
                if ($widgetsRaw !== '') {
                    $tmp = json_decode($widgetsRaw, true);
                    if (is_array($tmp)) {
                        $widgets = $this->persistSanitizer->sanitizeWidgets($tmp);
                    }
                }
            } catch (\Throwable) {}
            if (empty($widgets)) {
                $widgets = $this->dashboardDefaults->createDefaultTabs($dashboardMode, $dashboardStrategy);
            }
        }

        $userSettings = ['timezone' => $userTzName, 'firstDayOfWeek' => $userWeekStart];
        if ($userLocale !== '') {
            $userSettings['locale'] = $userLocale;
        }

        $coreInput = [
            'includeAll' => $includeAll,
            'includes' => $includes,
            'userSettings' => $userSettings,
            'calendars' => $sidebar,
            'selected' => $selectedIds,
            'colorsById' => $colorsById,
            'colorsByName' => $colorsByName,
            'groupsById' => $groupsById,
            'targetsWeek' => $targetsWeek,
            'targetsMonth' => $targetsMonth,
            'targetsConfig' => $targetsConfig,
            'themePreference' => $themePreference,
            'reportingConfig' => $reportingConfig,
            'deckSettings' => $deckSettings,
            'widgets' => $widgets,
            'widgetPresets' => $widgetPresets,
            'onboarding' => $onboardingPayload,
            'activePreset' => $activePreset,
            'preferredScope' => $preferredScope,
            'globalAppBg' => $globalAppBg,
            'globalAppBgLight' => $globalAppBgLight,
            'globalAppBgDark' => $globalAppBgDark,
        ];

        return [
            'range' => $range,
            'offset' => $offset,
            'provided' => $provided,
            'calsOverride' => $calsOverride,
            'forceReset' => $forceReset,
            'debugEnabled' => $dbgFlag,
            'includeDebugRequested' => $includeDebugRequested,
            'includeDebug' => $includeDebug,
            'includeLookback' => $includeLookback,
            'includeCharts' => $includeCharts,
            'includeData' => $includeData,
            'includeTargets' => $includeTargets,
            'includeTargetsConfig' => $includeTargetsConfig,
            'includeReportingConfig' => $includeReportingConfig,
            'includeDeckSettings' => $includeDeckSettings,
            'includeWidgets' => $includeWidgets,
            'includeOnboarding' => $includeOnboarding,
            'includeTheme' => $includeTheme,
            'includeAll' => $includeAll,
            'includes' => $includes,
            'userTz' => $userTz,
            'userTzName' => $userTzName,
            'userLocale' => $userLocale,
            'userWeekStart' => $userWeekStart,
            'from' => $from,
            'to' => $to,
            'fromStr' => $fromStr,
            'toStr' => $toStr,
            'principal' => $principal,
            'calendars' => $cals,
            'calendarIds' => $calendarIds,
            'idToName' => $idToName,
            'colorsById' => $colorsById,
            'colorsByName' => $colorsByName,
            'calDebug' => $calDebug,
            'sidebar' => $sidebar,
            'groupsById' => $groupsById,
            'targetsWeek' => $targetsWeek,
            'targetsMonth' => $targetsMonth,
            'targetsConfig' => $targetsConfig,
            'categoryMeta' => $categoryMeta,
            'groupToCategory' => $groupToCategory,
            'reportingConfig' => $reportingConfig,
            'deckSettings' => $deckSettings,
            'themePreference' => $themePreference,
            'onboardingPayload' => $onboardingPayload,
            'activePreset' => $activePreset,
            'widgets' => $widgets,
            'widgetPresets' => $widgetPresets,
            'dashboardMode' => $dashboardMode,
            'selection' => [
                'hasSaved' => $hasSaved,
                'savedIds' => $savedIds,
                'selectedIds' => $selectedIds,
                'includeAll' => $includeAllSelection,
            ],
            'selectedIds' => $selectedIds,
            'coreInput' => $coreInput,
            'userSettings' => $userSettings,
        ];
    }

    private function resolveUserLocale(string $uid): string {
        $candidates = ['lang', 'locale', 'language'];
        foreach ($candidates as $key) {
            try {
                $value = (string)$this->config->getUserValue($uid, 'core', $key, '');
            } catch (\Throwable) {
                $value = '';
            }
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function defaultBalanceConfig(): array {
        return [
            'categories' => ['work', 'hobby', 'sport'],
            'useCategoryMapping' => true,
            'index' => ['method' => 'simple_range', 'basis' => 'category'],
            'thresholds' => [
                // Deviation vs expected share (absolute). Defaults are tuned for target alignment, not raw dominance.
                'noticeAbove' => 0.15,
                'noticeBelow' => 0.15,
                'warnAbove' => 0.30,
                'warnBelow' => 0.30,
                'warnIndex' => 0.60,
            ],
            'relations' => ['displayMode' => 'ratio'],
            'trend' => ['lookbackWeeks' => 3],
            'dayparts' => ['enabled' => false],
            'ui' => [
                'showNotes' => false,
            ],
        ];
    }
}
