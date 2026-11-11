<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

final class PersistSanitizer {
    private const MAX_TARGET_HOURS = 10000;
    private const MAX_GROUP = 9;
    private const PRESET_NAME_MAX_LEN = 80;
    private const RATIO_DECIMALS = 1;

    public function __construct(
        private ?PersistDeckSanitizer $deckSanitizer = null,
        private ?PersistWidgetsSanitizer $widgetsSanitizer = null,
        private ?PersistOnboardingSanitizer $onboardingSanitizer = null,
    ) {
        $this->deckSanitizer ??= new PersistDeckSanitizer();
        $this->widgetsSanitizer ??= new PersistWidgetsSanitizer();
        $this->onboardingSanitizer ??= new PersistOnboardingSanitizer();
    }

    /**
     * @param array<string,mixed> $targets
     * @param array<string,int> $allowedSet
     * @return array<string,float>
     */
    public function cleanTargets(array $targets, array $allowedSet): array {
        $out = [];
        foreach ($targets as $id => $value) {
            $key = substr((string)$id, 0, 128);
            if (!isset($allowedSet[$key])) {
                continue;
            }
            if (!is_numeric($value)) {
                continue;
            }
            $out[$key] = round($this->clampFloat((float)$value, 0.0, self::MAX_TARGET_HOURS), 2);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $groupsById
     * @param array<string,int> $allowedSet
     * @param array<int,string> $allowedIds
     * @return array<string,int>
     */
    public function cleanGroups(array $groupsById, array $allowedSet, array $allowedIds): array {
        $out = [];
        foreach ($allowedIds as $id) {
            $key = substr((string)$id, 0, 128);
            if ($key === '') {
                continue;
            }
            $out[$key] = 0;
        }
        foreach ($groupsById as $id => $raw) {
            $key = substr((string)$id, 0, 128);
            if ($key === '' || !isset($allowedSet[$key])) {
                continue;
            }
            $n = is_numeric($raw) ? (int)floor((float)$raw) : 0;
            if ($n < 0 || $n > self::MAX_GROUP) {
                $n = 0;
            }
            $out[$key] = $n;
        }
        return $out;
    }

    /**
     * @param mixed $cfg
     * @return array<string,mixed>
     */
    public function cleanTargetsConfig($cfg): array {
        $base = $this->defaultTargetsConfig();
        if (!is_array($cfg)) {
            return $base;
        }

        $out = $base;

        if (isset($cfg['totalHours'])) {
            $out['totalHours'] = round($this->clampFloat((float)$cfg['totalHours'], 0, self::MAX_TARGET_HOURS), 2);
        }

        if (isset($cfg['categories']) && is_array($cfg['categories'])) {
            $cats = [];
            foreach ($cfg['categories'] as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $id = substr((string)($cat['id'] ?? ''), 0, 64);
                if ($id === '') {
                    $id = 'cat_' . count($cats);
                }
                $label = trim((string)($cat['label'] ?? ''));
                if ($label === '') {
                    $label = ucfirst($id);
                }
                $target = round($this->clampFloat((float)($cat['targetHours'] ?? 0), 0, self::MAX_TARGET_HOURS), 2);
                $includeWeekend = !empty($cat['includeWeekend']);
                $paceMode = ((string)($cat['paceMode'] ?? '') === 'time_aware') ? 'time_aware' : 'days_only';
                $groupIds = [];
                if (isset($cat['groupIds']) && is_array($cat['groupIds'])) {
                    foreach ($cat['groupIds'] as $gid) {
                        $n = (int)$gid;
                        if ($n < 0 || $n > self::MAX_GROUP) {
                            continue;
                        }
                        if (!in_array($n, $groupIds, true)) {
                            $groupIds[] = $n;
                        }
                    }
                }
                $color = $this->sanitizeHexColor($cat['color'] ?? null);
                $cats[] = [
                    'id' => $id,
                    'label' => $label,
                    'targetHours' => $target,
                    'includeWeekend' => $includeWeekend,
                    'paceMode' => $paceMode,
                    'color' => $color,
                    'groupIds' => $groupIds,
                ];
                if (count($cats) >= 12) {
                    break;
                }
            }
            if (!empty($cats)) {
                $out['categories'] = $cats;
            }
        }

        $out['activityCard'] = $this->cleanActivityCardConfig($cfg['activityCard'] ?? null);
        $out['balance'] = $this->cleanBalanceConfig($cfg['balance'] ?? null, $out['categories']);

        if (isset($cfg['pace']) && is_array($cfg['pace'])) {
            $pace = $cfg['pace'];
            $out['pace']['includeWeekendTotal'] = !empty($pace['includeWeekendTotal']);
            $mode = (string)($pace['mode'] ?? $out['pace']['mode']);
            $out['pace']['mode'] = $mode === 'time_aware' ? 'time_aware' : 'days_only';
            if (isset($pace['thresholds']) && is_array($pace['thresholds'])) {
                $thr = $pace['thresholds'];
                if (isset($thr['onTrack'])) {
                    $out['pace']['thresholds']['onTrack'] = round($this->clampFloat((float)$thr['onTrack'], -100, 100), 2);
                }
                if (isset($thr['atRisk'])) {
                    $out['pace']['thresholds']['atRisk'] = round($this->clampFloat((float)$thr['atRisk'], -100, 100), 2);
                }
            }
        }

        if (isset($cfg['forecast']) && is_array($cfg['forecast'])) {
            $fc = $cfg['forecast'];
            $out['forecast']['methodPrimary'] = ((string)($fc['methodPrimary'] ?? '') === 'momentum') ? 'momentum' : 'linear';
            if (isset($fc['momentumLastNDays'])) {
                $n = (int)round((float)$fc['momentumLastNDays']);
                if ($n < 1) {
                    $n = 1;
                }
                if ($n > 14) {
                    $n = 14;
                }
                $out['forecast']['momentumLastNDays'] = $n;
            }
            if (isset($fc['padding'])) {
                $out['forecast']['padding'] = round($this->clampFloat((float)$fc['padding'], 0, 100), 1);
            }
        }

        if (isset($cfg['ui']) && is_array($cfg['ui'])) {
            $ui = $cfg['ui'];
            foreach ($out['ui'] as $key => $val) {
                if (array_key_exists($key, $ui)) {
                    $out['ui'][$key] = !empty($ui[$key]);
                }
            }
        }

        if (isset($cfg['timeSummary']) && is_array($cfg['timeSummary'])) {
            $ts = $cfg['timeSummary'];
            foreach ($out['timeSummary'] as $key => $val) {
                if (array_key_exists($key, $ts)) {
                    $out['timeSummary'][$key] = (bool)$ts[$key];
                }
            }
        }

        if (isset($cfg['includeZeroDaysInStats'])) {
            $out['includeZeroDaysInStats'] = !empty($cfg['includeZeroDaysInStats']);
        }

        if (isset($cfg['allDayHours'])) {
            $out['allDayHours'] = round($this->clampFloat((float)$cfg['allDayHours'], 0, 24), 2);
        }

        if (isset($cfg['calendarPriorities']) && is_array($cfg['calendarPriorities'])) {
            $priorities = [];
            foreach ($cfg['calendarPriorities'] as $id => $raw) {
                $key = substr((string)$id, 0, 128);
                if ($key === '' || !is_numeric($raw)) {
                    continue;
                }
                $n = CalendarPriorityResolver::priorityFor([$key => $raw], $key);
                if ($n !== 0) {
                    $priorities[$key] = $n;
                }
                if (count($priorities) >= 500) {
                    break;
                }
            }
            if ($priorities !== []) {
                $out['calendarPriorities'] = $priorities;
            }
        }

        return $out;
    }

    /**
     * @param mixed $cfg
     * @return array<string,mixed>
     */
    public function cleanActivityCardConfig($cfg): array {
        $base = $this->defaultActivityCardConfig();
        if (!is_array($cfg)) {
            return $base;
        }
        $result = $base;
        $booleanKeys = [
            'showWeekendShare',
            'showEveningShare',
            'showEarliestLatest',
            'showOverlaps',
            'showLongestSession',
            'showLastDayOff',
            'showDayOffTrend',
            'showHint',
        ];
        foreach ($booleanKeys as $key) {
            if (array_key_exists($key, $cfg)) {
                $result[$key] = !empty($cfg[$key]);
            }
        }
        if (isset($cfg['forecastMode'])) {
            $mode = strtolower((string)$cfg['forecastMode']);
            if (in_array($mode, ['off', 'total', 'calendar', 'category'], true)) {
                $result['forecastMode'] = $mode;
            }
        }
        return $result;
    }

    /**
     * @param mixed $cfg
     * @param array<int,array<string,mixed>> $categories
     * @return array<string,mixed>
     */
    public function cleanBalanceConfig($cfg, array $categories): array {
        $base = $this->defaultBalanceConfig();
        $result = $base;

        $available = [];
        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $id = substr((string)($cat['id'] ?? ''), 0, 64);
            if ($id !== '') {
                $available[] = $id;
            }
        }

        if (!is_array($cfg)) {
            if (!empty($available)) {
                $result['categories'] = array_slice($available, 0, count($result['categories']));
            }
            return $result;
        }

        $orderSource = isset($cfg['categories']) && is_array($cfg['categories']) ? $cfg['categories'] : $base['categories'];
        $order = [];
        foreach ($orderSource as $rawId) {
            $id = substr((string)$rawId, 0, 64);
            if ($id === '') {
                continue;
            }
            if (!empty($available) && !in_array($id, $available, true)) {
                continue;
            }
            if (!in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        if (empty($order)) {
            $order = !empty($available) ? array_slice($available, 0, count($base['categories'])) : $base['categories'];
        }
        $result['categories'] = $order;
        $result['useCategoryMapping'] = !empty($cfg['useCategoryMapping']);

        $method = (string)($cfg['index']['method'] ?? $base['index']['method']);
        $result['index']['method'] = $method === 'shannon_evenness' ? 'shannon_evenness' : 'simple_range';
        $basis = (string)($cfg['index']['basis'] ?? $base['index']['basis']);
        $allowedBasis = ['off', 'category', 'calendar', 'both'];
        $result['index']['basis'] = in_array($basis, $allowedBasis, true) ? $basis : 'category';
        if (!$result['useCategoryMapping'] && $result['index']['basis'] === 'category') {
            $result['index']['basis'] = 'calendar';
        }

        if (isset($cfg['thresholds']) && is_array($cfg['thresholds'])) {
            $thr = $cfg['thresholds'];
            if (isset($thr['noticeAbove'])) {
                $result['thresholds']['noticeAbove'] = round($this->clampFloat((float)$thr['noticeAbove'], 0.0, 1.0), self::RATIO_DECIMALS);
            }
            if (isset($thr['noticeBelow'])) {
                $result['thresholds']['noticeBelow'] = round($this->clampFloat((float)$thr['noticeBelow'], 0.0, 1.0), self::RATIO_DECIMALS);
            }
            if (isset($thr['warnAbove'])) {
                $result['thresholds']['warnAbove'] = round($this->clampFloat((float)$thr['warnAbove'], 0.0, 1.0), self::RATIO_DECIMALS);
            }
            if (isset($thr['warnBelow'])) {
                $result['thresholds']['warnBelow'] = round($this->clampFloat((float)$thr['warnBelow'], 0.0, 1.0), self::RATIO_DECIMALS);
            }
            if (isset($thr['warnIndex'])) {
                $result['thresholds']['warnIndex'] = round($this->clampFloat((float)$thr['warnIndex'], 0.0, 1.0), self::RATIO_DECIMALS);
            }
        }

        $displayMode = (string)($cfg['relations']['displayMode'] ?? $base['relations']['displayMode']);
        $result['relations']['displayMode'] = $displayMode === 'factor' ? 'factor' : 'ratio';

        if (isset($cfg['trend']) && is_array($cfg['trend'])) {
            $lookback = (int)($cfg['trend']['lookbackWeeks'] ?? $base['trend']['lookbackWeeks']);
            if ($lookback < 1) {
                $lookback = 1;
            }
            if ($lookback > 6) {
                $lookback = 6;
            }
            $result['trend']['lookbackWeeks'] = $lookback;
        }

        if (isset($cfg['dayparts']) && is_array($cfg['dayparts'])) {
            $result['dayparts']['enabled'] = !empty($cfg['dayparts']['enabled']);
        }

        if (isset($cfg['ui']) && is_array($cfg['ui'])) {
            $result['ui']['showNotes'] = !empty($cfg['ui']['showNotes']);
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    public function sanitizeReportingConfig($value): array {
        $defaults = $this->defaultReportingConfig();
        if (!is_array($value)) {
            return $defaults;
        }
        $threshold = (float)($value['riskThreshold'] ?? $defaults['riskThreshold']);
        if (!is_finite($threshold) || $threshold < 0 || $threshold > 1) {
            $threshold = $defaults['riskThreshold'];
        }
        $legacySchedule = $value['schedule'] ?? 'both';
        if ($legacySchedule !== 'week' && $legacySchedule !== 'month') {
            $legacySchedule = 'both';
        }
        $legacyCadence = $value['interim'] ?? 'none';
        if (!in_array($legacyCadence, ['none', 'midweek', 'daily'], true)) {
            $legacyCadence = 'none';
        }
        $modes = [
            'week' => $this->sanitizeReportingModeConfig(
                is_array($value['modes']['week'] ?? null) ? $value['modes']['week'] : null,
                [
                    'enabled' => $legacySchedule === 'week' || $legacySchedule === 'both',
                    'delivery' => $legacyCadence === 'midweek' ? 'checkpoint_final' : 'final',
                    'sendTimeLocal' => '06:00',
                ]
            ),
            'month' => $this->sanitizeReportingModeConfig(
                is_array($value['modes']['month'] ?? null) ? $value['modes']['month'] : null,
                [
                    'enabled' => $legacySchedule === 'month' || $legacySchedule === 'both',
                    'delivery' => $legacyCadence === 'midweek' ? 'checkpoint_final' : 'final',
                    'sendTimeLocal' => '18:00',
                ]
            ),
        ];
        return [
            'enabled' => !empty($value['enabled']),
            'modes' => $modes,
            'alertOnRisk' => array_key_exists('alertOnRisk', $value) ? (bool)$value['alertOnRisk'] : true,
            'riskThreshold' => round($threshold, 3),
            'notifyEmail' => array_key_exists('notifyEmail', $value) ? (bool)$value['notifyEmail'] : true,
            'notifyNotification' => !empty($value['notifyNotification']),
        ];
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    public function sanitizeDeckSettings($value): array {
        return $this->deckSanitizer->sanitize($value, $this->defaultDeckSettings());
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    public function sanitizeWidgets($value): array {
        return $this->widgetsSanitizer->sanitize($value);
    }

    public function sanitizeThemePreference($value): ?string {
        $v = strtolower(trim((string)$value));
        if ($v === 'light' || $v === 'dark' || $v === 'auto') {
            return $v;
        }
        return null;
    }

    /**
     * @param mixed $state
     * @return array<string,mixed>
     */
    public function cleanOnboardingState($state): array {
        return $this->onboardingSanitizer->sanitize($state, $this->defaultOnboardingState());
    }

    public function sanitizePresetName(string $name): string {
        $clean = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($clean === '') {
            return '';
        }
        // Allow letters, numbers, space, dot, dash, underscore only; strip everything else
        $clean = preg_replace('/[^A-Za-z0-9 _\-.]/u', '', $clean) ?? '';
        if ($clean === '') {
            return '';
        }
        if (mb_strlen($clean) > self::PRESET_NAME_MAX_LEN) {
            $clean = mb_substr($clean, 0, self::PRESET_NAME_MAX_LEN);
        }
        return $clean;
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultTargetsConfig(): array {
        return [
            'totalHours' => 48,
            'categories' => [
                ['id' => 'work', 'label' => 'Work', 'targetHours' => 32, 'includeWeekend' => false, 'paceMode' => 'days_only', 'groupIds' => [1]],
                ['id' => 'hobby', 'label' => 'Hobby', 'targetHours' => 6, 'includeWeekend' => true, 'paceMode' => 'days_only', 'groupIds' => [2]],
                ['id' => 'sport', 'label' => 'Sport', 'targetHours' => 4, 'includeWeekend' => true, 'paceMode' => 'days_only', 'groupIds' => [3]],
            ],
            'pace' => [
                'includeWeekendTotal' => true,
                'mode' => 'days_only',
                'thresholds' => ['onTrack' => -2, 'atRisk' => -10],
            ],
            'forecast' => [
                'methodPrimary' => 'linear',
                'momentumLastNDays' => 2,
                'padding' => 1.5,
            ],
            'ui' => [
                'showTotalDelta' => true,
                'showNeedPerDay' => true,
                'showCategoryBlocks' => true,
                'badges' => true,
                'includeWeekendToggle' => true,
                'showCalendarCharts' => true,
                'showCategoryCharts' => true,
            ],
            'allDayHours' => 8.0,
            'timeSummary' => [
                'showTotal' => true,
                'showAverage' => true,
                'showMedian' => true,
                'showBusiest' => true,
                'showWorkday' => true,
                'showWeekend' => true,
                'showWeekendShare' => true,
                'showCalendarSummary' => true,
                'showTopCategory' => true,
                'showBalance' => true,
            ],
            'activityCard' => $this->defaultActivityCardConfig(),
            'balance' => $this->defaultBalanceConfig(),
            'includeZeroDaysInStats' => false,
        ];
    }

    /**
     * @param mixed $value
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private function sanitizeReportingModeConfig($value, array $fallback): array {
        if (!is_array($value)) {
            return $fallback;
        }
        $delivery = $value['delivery'] ?? null;
        if ($delivery !== 'final' && $delivery !== 'checkpoint_final') {
            $delivery = ($value['cadence'] ?? null) === 'mid' ? 'checkpoint_final' : ($fallback['delivery'] ?? 'final');
        }
        $sendTimeLocal = trim((string)($value['sendTimeLocal'] ?? ''));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $sendTimeLocal)) {
            $sendTimeLocal = (string)($fallback['sendTimeLocal'] ?? '06:00');
        }
        return [
            'enabled' => array_key_exists('enabled', $value) ? (bool)$value['enabled'] : !empty($fallback['enabled']),
            'delivery' => $delivery,
            'sendTimeLocal' => $sendTimeLocal,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultActivityCardConfig(): array {
        return [
            'showWeekendShare' => true,
            'showEveningShare' => true,
            'showEarliestLatest' => true,
            'showOverlaps' => true,
            'showLongestSession' => true,
            'showLastDayOff' => true,
            'showDayOffTrend' => true,
            'showHint' => true,
            'forecastMode' => 'total',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultBalanceConfig(): array {
        return [
            'categories' => ['work', 'hobby', 'sport'],
            'useCategoryMapping' => true,
            'index' => ['method' => 'simple_range', 'basis' => 'category'],
            'thresholds' => [
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

    /**
     * @return array<string,mixed>
     */
    private function defaultReportingConfig(): array {
        return [
            'enabled' => false,
            'modes' => [
                'week' => [
                    'enabled' => true,
                    'delivery' => 'final',
                    'sendTimeLocal' => '06:00',
                ],
                'month' => [
                    'enabled' => false,
                    'delivery' => 'checkpoint_final',
                    'sendTimeLocal' => '18:00',
                ],
            ],
            'alertOnRisk' => true,
            'riskThreshold' => 0.85,
            'notifyEmail' => true,
            'notifyNotification' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultDeckSettings(): array {
        return [
            'enabled' => true,
            'filtersEnabled' => true,
            'defaultFilter' => 'all',
            'hiddenBoards' => [],
            'mineMode' => 'assignee',
            'solvedIncludesArchived' => true,
            'ticker' => [
                'autoScroll' => true,
                'intervalSeconds' => 5,
                'showBoardBadges' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultOnboardingState(): array {
        return [
            'completed' => false,
            'version' => 0,
            'strategy' => '',
            'completed_at' => '',
            'dashboardMode' => 'standard',
            'releaseNotesSeenVersion' => '',
        ];
    }

    private function clampFloat(float $value, float $min, float $max): float {
        if (!is_finite($value)) {
            return $min;
        }
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    private function sanitizeHexColor($value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $v, $m)) {
            return strtoupper('#' . $m[1]);
        }
        if (preg_match('/^#([0-9a-fA-F]{3})$/', $v, $m)) {
            $r = $m[1][0];
            $g = $m[1][1];
            $b = $m[1][2];
            return strtoupper('#' . $r . $r . $g . $g . $b . $b);
        }
        return null;
    }

}
