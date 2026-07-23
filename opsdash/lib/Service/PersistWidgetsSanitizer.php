<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

final class PersistWidgetsSanitizer {
    private const MAX_WIDGET_TABS = 10;
    private const MAX_WIDGETS_PER_TAB = 50;
    private const MAX_WIDGETS_TOTAL = 100;
    private const MAX_ID_LIST_COUNT = 100;
    private const MAX_ID_LEN = 128;
    private const MAX_TEXT_LEN = 128;

    private const ALLOWED_TYPES = [
        'activity_schedule' => true,
        'balance_index' => true,
        'calendar_table' => true,
        'calendar_stats' => true,
        'category_mix_trend' => true,
        'chart_dow' => true,
        'chart_hod' => true,
        'chart_per_day' => true,
        'chart_pie' => true,
        'chart_stacked' => true,
        'dayoff_trend' => true,
        'deck_cards' => true,
        'deck_stats' => true,
        'targets_v2' => true,
        'text_block' => true,
        'time_summary_lookback' => true,
        'time_summary_overview' => true,
        'time_summary_v2' => true,
    ];

    private const FORECAST_MODES = ['off', 'total', 'calendar', 'category'];
    private const FILTER_MODES = ['category', 'calendar'];
    private const LABEL_MODES = ['date', 'period', 'compact', 'offset'];
    private const HISTORY_VIEWS = ['timeline', 'accordion'];
    private const HEIGHT_MODES = ['auto', 'fixed'];
    private const SCALE_MODES = ['sm', 'md', 'lg', 'xl'];

    private ?PersistSanitizer $persistSanitizer = null;

    /**
     * Per-widget option schemas.
     * Format: 'key' => ['t' => type, ...constraints]
     * Types: bool, select, number, color, text, id_list, targets_config, targets_map
     *
     * @return array<string, array<string, array<string,mixed>>>
     */
    private static function optionSchemas(): array {
        $FORECAST = ['t' => 'select', 'v' => self::FORECAST_MODES];
        $FILTER_MODE = ['t' => 'select', 'v' => self::FILTER_MODES];
        $LABEL_MODE = ['t' => 'select', 'v' => self::LABEL_MODES];
        $BOOL = ['t' => 'bool'];
        $COLOR = ['t' => 'color'];
        $ID_LIST = ['t' => 'id_list'];
        $TEXT = ['t' => 'text'];
        $CORE = self::coreOptionSchema();

        return [
            'balance_index' => $CORE + [
                'showConfig'     => $BOOL,
                'showTrend'      => $BOOL,
                'showMessages'   => $BOOL,
                'showCurrent'    => $BOOL,
                'reverseOrder'   => $BOOL,
                'messageDensity' => ['t' => 'select', 'v' => ['few', 'normal', 'many']],
                'indexBasis'     => ['t' => 'select', 'v' => ['off', 'category', 'calendar', 'both']],
                'labelMode'      => $LABEL_MODE,
                'noticeAbove'    => ['t' => 'number', 'min' => 0.0, 'max' => 1.0],
                'noticeBelow'    => ['t' => 'number', 'min' => 0.0, 'max' => 1.0],
                'warnAbove'      => ['t' => 'number', 'min' => 0.0, 'max' => 1.0],
                'warnBelow'      => ['t' => 'number', 'min' => 0.0, 'max' => 1.0],
                'warnIndex'      => ['t' => 'number', 'min' => 0.0, 'max' => 1.0],
                'trendColor'     => $COLOR,
            ],
            'calendar_table' => $CORE + [
                'calendarFilter' => $ID_LIST,
                'compact'        => $BOOL,
            ],
            'calendar_stats' => $CORE + [
                'metrics' => $ID_LIST,
            ],
            'category_mix_trend' => $CORE + [
                'density'          => ['t' => 'select', 'v' => ['normal', 'dense']],
                'squareCells'      => $BOOL,
                'reverseOrder'     => $BOOL,
                'showBadge'        => $BOOL,
                'labelMode'        => $LABEL_MODE,
                'colorMode'        => ['t' => 'select', 'v' => ['label', 'hybrid', 'share', 'trend']],
                'trendIndicator'   => ['t' => 'select', 'v' => ['none', 'symbol', 'delta', 'both']],
                'shareLowColor'    => $COLOR,
                'shareHighColor'   => $COLOR,
                'toneLowColor'     => $COLOR,
                'toneHighColor'    => $COLOR,
                'filterMode'       => $FILTER_MODE,
                'filterIds'        => $ID_LIST,
            ],
            'chart_dow' => $CORE + [
                'showLabels'   => $BOOL,
                'compact'      => $BOOL,
                'reverseOrder' => $BOOL,
                'forecastMode' => $FORECAST,
                'filterMode'   => $FILTER_MODE,
                'filterIds'    => $ID_LIST,
                'colorStyle'   => ['t' => 'select', 'v' => ['fill', 'outline']],
            ],
            'chart_hod' => $CORE + [
                'showLegend'   => $BOOL,
                'showHint'     => $BOOL,
                'compact'      => $BOOL,
                'reverseOrder' => $BOOL,
                'lookbackMode' => ['t' => 'select', 'v' => ['stacked', 'overlay']],
                'filterMode'   => $FILTER_MODE,
                'filterIds'    => $ID_LIST,
            ],
            'chart_per_day' => $CORE + [
                'showLabels'   => $BOOL,
                'compact'      => $BOOL,
                'reverseOrder' => $BOOL,
                'forecastMode' => $FORECAST,
                'filterMode'   => $FILTER_MODE,
                'filterIds'    => $ID_LIST,
                'colorStyle'   => ['t' => 'select', 'v' => ['fill', 'outline']],
            ],
            'chart_pie' => $CORE + [
                'showLegend' => $BOOL,
                'showLabels' => $BOOL,
                'compact'    => $BOOL,
                'filterMode' => $FILTER_MODE,
                'filterIds'  => $ID_LIST,
                'colorStyle' => ['t' => 'select', 'v' => ['fill', 'outline']],
            ],
            'chart_stacked' => $CORE + [
                'showLegend'   => $BOOL,
                'showLabels'   => $BOOL,
                'compact'      => $BOOL,
                'forecastMode' => $FORECAST,
                'filterMode'   => $FILTER_MODE,
                'filterIds'    => $ID_LIST,
                'colorStyle'   => ['t' => 'select', 'v' => ['fill', 'outline']],
            ],
            'dayoff_trend' => $CORE + [
                'reverseOrder'    => $BOOL,
                'labelMode'       => $LABEL_MODE,
                'interpretation'  => ['t' => 'select', 'v' => ['more_off_positive', 'more_off_warning']],
                'toneLowColor'    => $COLOR,
                'toneHighColor'   => $COLOR,
                'ignoreCalendarIds' => $ID_LIST,
                'ignoreCategoryIds' => $ID_LIST,
            ],
            'deck_cards' => $CORE + [
                'allowMine'       => $BOOL,
                'includeArchived' => $BOOL,
                'includeCompleted'=> $BOOL,
                'showCount'       => $BOOL,
                'autoScroll'      => $BOOL,
                'compactList'     => $BOOL,
                'boardIds'        => $ID_LIST,
                'stackIds'        => $ID_LIST,
                'filters'         => $ID_LIST,
                'autoTagSelection'=> $ID_LIST,
                'intervalSeconds' => ['t' => 'number', 'min' => 3.0, 'max' => 10.0],
                'minFilterCount'  => ['t' => 'number', 'min' => 0.0, 'max' => 999.0],
                'maxVisible'      => ['t' => 'number', 'min' => 3.0, 'max' => 50.0],
                'defaultFilter'   => $TEXT,
            ],
            'deck_stats' => $CORE + [
                'includeArchived' => $BOOL,
                'includeCompleted'=> $BOOL,
                'boardIds'        => $ID_LIST,
                'stackIds'        => $ID_LIST,
                'metrics'         => $ID_LIST,
                'scope'           => ['t' => 'select', 'v' => ['all', 'mine', 'unassigned']],
                'mineMode'        => ['t' => 'select', 'v' => ['assignee', 'creator', 'both']],
            ],
            'targets_v2' => $CORE + [
                'showLegend'             => $BOOL,
                'showDelta'              => $BOOL,
                'showForecast'           => $BOOL,
                'showPace'               => $BOOL,
                'neverFinishedMode'      => $BOOL,
                'showToday'              => $BOOL,
                'showTotalDelta'         => $BOOL,
                'showNeedPerDay'         => $BOOL,
                'showCategoryBlocks'     => $BOOL,
                'badges'                 => $BOOL,
                'includeWeekendToggle'   => $BOOL,
                'includeZeroDaysInStats' => $BOOL,
                'useLocalConfig'         => $BOOL,
                'localConfig'            => ['t' => 'targets_config'],
                'localGroupsById'        => ['t' => 'targets_map'],
                'localTargetsWeek'       => ['t' => 'targets_map'],
                'localTargetsMonth'      => ['t' => 'targets_map'],
                'colorStyle'             => ['t' => 'select', 'v' => ['fill', 'outline']],
            ],
            'text_block' => $CORE + [
                'content' => ['t' => 'text', 'maxlen' => 4096],
            ],
            'time_summary_lookback' => $CORE + [
                'showTotal'              => $BOOL,
                'showAverage'            => $BOOL,
                'showMedian'             => $BOOL,
                'showBusiest'            => $BOOL,
                'showWorkday'            => $BOOL,
                'showWeekend'            => $BOOL,
                'showWeekendShare'       => $BOOL,
                'showCalendarSummary'    => $BOOL,
                'showTopCategory'        => $BOOL,
                'showToday'              => $BOOL,
                'showActivity'           => $BOOL,
                'showActivityDetails'    => $BOOL,
                'showHistoryCoreMetrics' => $BOOL,
                'showDelta'              => $BOOL,
                'historyView'            => ['t' => 'select', 'v' => self::HISTORY_VIEWS],
                'activeDayMode'          => ['t' => 'select', 'v' => ['active', 'all']],
            ],
            'time_summary_overview' => $CORE + [
                'defaultView'            => ['t' => 'select', 'v' => ['auto', 'daily', 'calendars', 'categories']],
                'showDailyKpis'          => $BOOL,
                'showWeekMiniChart'      => $BOOL,
                'showEmptyLanes'         => $BOOL,
                'maxLanes'               => ['t' => 'number', 'min' => 1.0, 'max' => 12.0],
                'showActivityNote'       => $BOOL,
                'showTotal'              => $BOOL,
                'showAverage'            => $BOOL,
                'showMedian'             => $BOOL,
                'showBusiest'            => $BOOL,
                'showWorkday'            => $BOOL,
                'showWeekend'            => $BOOL,
                'showWeekendShare'       => $BOOL,
                'showCalendarSummary'    => $BOOL,
                'showTopCategory'        => $BOOL,
                'showToday'              => $BOOL,
                'showActivity'           => $BOOL,
                'showActivityDetails'    => $BOOL,
                'showHistoryCoreMetrics' => $BOOL,
                'showDelta'              => $BOOL,
                'historyView'            => ['t' => 'select', 'v' => self::HISTORY_VIEWS],
                'activeDayMode'          => ['t' => 'select', 'v' => ['active', 'all']],
            ],
            'time_summary_v2' => $CORE + [
                'showTotal'              => $BOOL,
                'showAverage'            => $BOOL,
                'showMedian'             => $BOOL,
                'showBusiest'            => $BOOL,
                'showWorkday'            => $BOOL,
                'showWeekend'            => $BOOL,
                'showWeekendShare'       => $BOOL,
                'showCalendarSummary'    => $BOOL,
                'showTopCategory'        => $BOOL,
                'showToday'              => $BOOL,
                'showActivity'           => $BOOL,
                'showActivityDetails'    => $BOOL,
                'showHistoryCoreMetrics' => $BOOL,
                'showDelta'              => $BOOL,
                'historyView'            => ['t' => 'select', 'v' => self::HISTORY_VIEWS],
                'activeDayMode'          => ['t' => 'select', 'v' => ['active', 'all']],
            ],
            'activity_schedule' => $CORE,
        ];
    }

    /**
     * Global widget UI options supported across widget types.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function coreOptionSchema(): array {
        return [
            'heightMode'  => ['t' => 'select', 'v' => self::HEIGHT_MODES],
            'titlePrefix' => ['t' => 'text', 'maxlen' => 128],
            'showHeader'  => ['t' => 'bool'],
            'cardBg'      => ['t' => 'color'],
            'scale'       => ['t' => 'select', 'v' => self::SCALE_MODES],
            // Legacy alias still accepted from old imports; frontend normalizes to `scale`.
            'textSize'    => ['t' => 'select', 'v' => self::SCALE_MODES],
            'dense'       => ['t' => 'bool'],
        ];
    }

    public function __construct() {
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>|array<int,array<string,mixed>>
     */
    public function sanitize($value): array {
        if (!is_array($value)) {
            return [];
        }
        if (array_key_exists('tabs', $value)) {
            $tabsRaw = $value['tabs'];
            if (!is_array($tabsRaw)) {
                return [];
            }
            $tabs = [];
            $totalWidgets = 0;
            foreach ($tabsRaw as $tab) {
                if (count($tabs) >= self::MAX_WIDGET_TABS) {
                    break;
                }
                if (!is_array($tab)) {
                    continue;
                }
                $id = substr(trim((string)($tab['id'] ?? '')), 0, 48);
                if ($id === '') {
                    $id = sprintf('tab-%d', count($tabs) + 1);
                }
                $labelRaw = trim((string)($tab['label'] ?? ''));
                $label = $labelRaw !== '' ? substr($labelRaw, 0, 48) : sprintf('Tab %d', count($tabs) + 1);
                $widgets = $this->sanitizeWidgetList($tab['widgets'] ?? [], self::MAX_WIDGETS_PER_TAB, $totalWidgets);
                $tabs[] = [
                    'id' => $id,
                    'label' => $label,
                    'widgets' => $widgets,
                ];
            }
            if (empty($tabs)) {
                return [];
            }
            $defaultTabId = trim((string)($value['defaultTabId'] ?? $value['defaultTab'] ?? ''));
            $found = false;
            foreach ($tabs as $tab) {
                if ($tab['id'] === $defaultTabId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $defaultTabId = $tabs[0]['id'];
            }
            return [
                'tabs' => $tabs,
                'defaultTabId' => $defaultTabId,
            ];
        }
        $totalWidgets = 0;
        return $this->sanitizeWidgetList($value, self::MAX_WIDGETS_TOTAL, $totalWidgets);
    }

    /**
     * @param mixed $value
     * @return array<int,array<string,mixed>>
     */
    private function sanitizeWidgetList($value, int $limit, int &$totalWidgets): array {
        if (!is_array($value)) {
            return [];
        }
        $schemas = self::optionSchemas();
        $cleaned = [];
        foreach ($value as $item) {
            if ($totalWidgets >= self::MAX_WIDGETS_TOTAL || count($cleaned) >= $limit) {
                break;
            }
            if (!is_array($item)) {
                continue;
            }
            $type = substr(trim((string)($item['type'] ?? '')), 0, 64);
            if ($type === '' || !isset(self::ALLOWED_TYPES[$type])) {
                continue;
            }
            $id = substr(trim((string)($item['id'] ?? '')), 0, 64);
            $layout = $item['layout'] ?? [];
            $width = ($layout['width'] ?? '') === 'quarter' || ($layout['width'] ?? '') === 'half' ? $layout['width'] : 'full';
            $heightRaw = (string)($layout['height'] ?? '');
            $height = ($heightRaw === 's' || $heightRaw === 'l' || $heightRaw === 'xl') ? $heightRaw : 'm';
            $orderRaw = $layout['order'] ?? 0;
            $order = is_numeric($orderRaw) ? (float)$orderRaw : 0.0;
            $rawOptions = (isset($item['options']) && is_array($item['options'])) ? $item['options'] : [];
            // Legacy payloads stored heightMode at widget root; migrate into options.
            if (!array_key_exists('heightMode', $rawOptions) && isset($item['heightMode'])) {
                $rawOptions['heightMode'] = $item['heightMode'];
            }
            $options = $this->sanitizeOptions($rawOptions, $schemas[$type] ?? []);
            $cleaned[] = [
                'id' => $id !== '' ? $id : sprintf('widget-%s-%d', $type, count($cleaned) + 1),
                'type' => $type,
                'options' => $options,
                'layout' => [
                    'width' => $width,
                    'height' => $height,
                    'order' => $order,
                ],
                'version' => (int)($item['version'] ?? 1) ?: 1,
            ];
            $totalWidgets += 1;
        }
        return $cleaned;
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,array<string,mixed>> $schema
     * @return array<string,mixed>
     */
    private function sanitizeOptions(array $raw, array $schema): array {
        $out = [];
        foreach ($schema as $key => $spec) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $val = $raw[$key];
            $type = (string)($spec['t'] ?? '');
            $sanitized = match ($type) {
                'bool'           => (bool)$val,
                'select'         => $this->sanitizeSelect($val, (array)($spec['v'] ?? [])),
                'number'         => $this->sanitizeNumber($val, (float)($spec['min'] ?? 0), (float)($spec['max'] ?? PHP_INT_MAX)),
                'color'          => $this->sanitizeHexColor($val),
                'text'           => $this->sanitizeText($val, (int)($spec['maxlen'] ?? self::MAX_TEXT_LEN)),
                'id_list'        => $this->sanitizeIdList($val),
                'targets_config' => $this->sanitizeTargetsConfig($val),
                'targets_map'    => $this->sanitizeTargetsMap($val),
                default          => null,
            };
            if ($sanitized !== null) {
                $out[$key] = $sanitized;
            }
        }
        return $out;
    }

    private function sanitizeSelect(mixed $val, array $allowed): ?string {
        $v = (string)$val;
        return in_array($v, $allowed, true) ? $v : null;
    }

    private function sanitizeNumber(mixed $val, float $min, float $max): ?float {
        if (!is_numeric($val)) {
            return null;
        }
        $f = (float)$val;
        if (!is_finite($f)) {
            return null;
        }
        return max($min, min($max, $f));
    }

    private function sanitizeHexColor(mixed $val): ?string {
        if (!is_string($val)) {
            return null;
        }
        $v = trim($val);
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

    private function sanitizeText(mixed $val, int $maxLen): ?string {
        if (!is_string($val) && !is_numeric($val)) {
            return null;
        }
        $s = trim((string)$val);
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $maxLen);
    }

    /**
     * @return array<int,string>|null
     */
    private function sanitizeIdList(mixed $val): ?array {
        if (!is_array($val)) {
            return null;
        }
        $out = [];
        foreach ($val as $item) {
            $s = substr(trim((string)$item), 0, self::MAX_ID_LEN);
            if ($s !== '') {
                $out[] = $s;
            }
            if (count($out) >= self::MAX_ID_LIST_COUNT) {
                break;
            }
        }
        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sanitizeTargetsConfig(mixed $val): ?array {
        if (!is_array($val)) {
            return null;
        }
        return $this->getPersistSanitizer()->cleanTargetsConfig($val);
    }

    /**
     * Sanitize a flat map of ID → numeric value (used for localTargetsWeek/Month and localGroupsById).
     *
     * @return array<string,float>|null
     */
    private function sanitizeTargetsMap(mixed $val): ?array {
        if (!is_array($val)) {
            return null;
        }
        $out = [];
        foreach ($val as $k => $v) {
            $key = substr(trim((string)$k), 0, self::MAX_ID_LEN);
            if ($key === '' || !is_numeric($v)) {
                continue;
            }
            $out[$key] = round((float)$v, 2);
            if (count($out) >= self::MAX_ID_LIST_COUNT) {
                break;
            }
        }
        return $out;
    }

    private function getPersistSanitizer(): PersistSanitizer {
        // Avoid a DI recursion loop: PersistSanitizer owns PersistWidgetsSanitizer,
        // so this sanitizer must not request PersistSanitizer in its constructor.
        $this->persistSanitizer ??= new PersistSanitizer(
            null,
            new self(),
            null,
        );
        return $this->persistSanitizer;
    }
}
