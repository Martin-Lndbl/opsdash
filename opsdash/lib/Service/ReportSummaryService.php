<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

use OCP\IConfig;

final class ReportSummaryService {
    private const MAX_OFFSET = 24;
    private const MAX_GROUP = 9;
    private const MAX_AGG_PER_CAL = 2000;
    private const MAX_AGG_TOTAL = 5000;

    public function __construct(
        private CalendarAccessService $calendarAccess,
        private OverviewEventsCollector $eventsCollector,
        private OverviewAggregationService $aggregationService,
        private OverviewSelectionService $selectionService,
        private OverviewBalanceService $overviewBalanceService,
        private UserConfigService $userConfigService,
        private PersistSanitizer $persistSanitizer,
        private IConfig $config,
    ) {
    }

    /**
     * @param string[]|null $requestedCals
     * @param array<string,mixed>|null $groupsOverride
     * @param array<string,mixed>|null $targetsConfigOverride
     * @return array<string,mixed>
     */
    public function build(
        string $appName,
        string $uid,
        string $range = 'week',
        int $offset = 0,
        ?array $requestedCals = null,
        ?array $groupsOverride = null,
        ?array $targetsConfigOverride = null,
    ): array {
        $range = strtolower($range) === 'month' ? 'month' : 'week';
        $offset = max(-self::MAX_OFFSET, min(self::MAX_OFFSET, $offset));

        $provided = is_array($requestedCals);
        $requested = $provided
            ? array_values(array_filter(array_map(static fn ($x) => trim((string)$x), $requestedCals), static fn ($x) => $x !== ''))
            : null;

        $savedRaw = (string)$this->config->getUserValue($uid, $appName, 'selected_cals', '__UNSET__');
        $selection = $this->selectionService->resolveInitialSelection($savedRaw, $provided, $requested);

        $userTz = $this->calendarAccess->resolveUserTimezone($uid);
        $weekStart = $this->calendarAccess->resolveUserWeekStart($uid);
        [$from, $to] = $this->calendarAccess->rangeBounds($range, $offset, $userTz, $weekStart);
        $cals = $this->calendarAccess->getCalendarsFor($uid);

        $availableIds = [];
        $calendarLabels = [];
        foreach ($cals as $cal) {
            $id = '';
            try {
                $id = (string)($cal->getUri() ?? '');
            } catch (\Throwable) {
                $id = '';
            }
            if ($id === '') {
                $id = (string)spl_object_id($cal);
            }
            $availableIds[] = $id;
            try {
                $calendarLabels[$id] = (string)($cal->getDisplayName() ?? $id);
            } catch (\Throwable) {
                $calendarLabels[$id] = $id;
            }
        }

        $allowedSet = [];
        foreach ($availableIds as $id) {
            $allowedSet[$id] = 1;
        }

        $selectedIds = $this->selectionService->finalizeSelectedIds(
            $selection['includeAll'],
            $availableIds,
            $selection['selectedIds'],
        );
        $selectedIds = array_values(array_filter($selectedIds, static fn ($id) => isset($allowedSet[$id])));

        $groupsById = [];
        if (is_array($groupsOverride)) {
            $groupsById = $groupsOverride;
        } else {
            try {
                $groupsRaw = (string)$this->config->getUserValue($uid, $appName, 'cal_groups', '');
                if ($groupsRaw !== '') {
                    $tmp = json_decode($groupsRaw, true);
                    if (is_array($tmp)) {
                        $groupsById = $tmp;
                    }
                }
            } catch (\Throwable) {
                $groupsById = [];
            }
        }
        $groupsById = $this->persistSanitizer->cleanGroups($groupsById, $allowedSet, $availableIds);

        $targetsConfig = is_array($targetsConfigOverride)
            ? $this->persistSanitizer->cleanTargetsConfig($targetsConfigOverride)
            : $this->userConfigService->readTargetsConfig($appName, $uid);
        $onboardingState = $this->userConfigService->readOnboardingState($appName, $uid);
        $calendarPriorities = is_array($targetsConfig['calendarPriorities'] ?? null) ? $targetsConfig['calendarPriorities'] : [];

        $categoryMeta = [];
        $groupToCategory = [];
        if (!empty($targetsConfig['categories']) && is_array($targetsConfig['categories'])) {
            foreach ($targetsConfig['categories'] as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $catId = substr((string)($cat['id'] ?? ''), 0, 64);
                if ($catId === '') {
                    continue;
                }
                $label = trim((string)($cat['label'] ?? '')) ?: ucfirst($catId);
                $categoryMeta[$catId] = ['id' => $catId, 'label' => $label];
                if (!empty($cat['groupIds']) && is_array($cat['groupIds'])) {
                    foreach ($cat['groupIds'] as $gid) {
                        $n = (int)$gid;
                        if ($n < 0 || $n > self::MAX_GROUP) {
                            continue;
                        }
                        $groupToCategory[$n] = $catId;
                    }
                }
            }
        }
        $categoryMeta['__uncategorized__'] = ['id' => '__uncategorized__', 'label' => 'Unassigned'];

        $mapCalToCategory = function (string $calId) use ($groupsById, $groupToCategory): string {
            $group = isset($groupsById[$calId]) ? (int)$groupsById[$calId] : 0;
            return $groupToCategory[$group] ?? '__uncategorized__';
        };

        $collect = $this->eventsCollector->collect(
            principal: 'principals/users/' . $uid,
            cals: $cals,
            includeAll: $selection['includeAll'],
            selectedIds: $selectedIds,
            from: $from,
            to: $to,
            maxPerCal: self::MAX_AGG_PER_CAL,
            maxTotal: self::MAX_AGG_TOTAL,
            debug: false,
        );

        $agg = $this->aggregationService->aggregate(
            events: $collect['events'],
            from: $from,
            to: $to,
            userTz: $userTz,
            allDayHours: (float)($targetsConfig['allDayHours'] ?? 8),
            colorsById: [],
            categoryMeta: $categoryMeta,
            mapCalToCategory: $mapCalToCategory,
            calendarPriorities: $calendarPriorities,
        );

        $categoryTotalsPrev = [];
        $prevTotal = 0.0;
        try {
            [$prevFrom, $prevTo] = $this->calendarAccess->rangeBounds($range, $offset - 1, $userTz, $weekStart);
            $prevCollect = $this->eventsCollector->collect(
                principal: 'principals/users/' . $uid,
                cals: $cals,
                includeAll: $selection['includeAll'],
                selectedIds: $selectedIds,
                from: $prevFrom,
                to: $prevTo,
                maxPerCal: self::MAX_AGG_PER_CAL,
                maxTotal: self::MAX_AGG_TOTAL,
                debug: false,
            );
            $prevAgg = $this->aggregationService->aggregate(
                events: $prevCollect['events'],
                from: $prevFrom,
                to: $prevTo,
                userTz: $userTz,
                allDayHours: (float)($targetsConfig['allDayHours'] ?? 8),
                colorsById: [],
                categoryMeta: $categoryMeta,
                mapCalToCategory: $mapCalToCategory,
                calendarPriorities: $calendarPriorities,
            );
            $categoryTotalsPrev = $prevAgg['categoryTotals'] ?? [];
            $prevTotal = (float)($prevAgg['totalHours'] ?? 0.0);
        } catch (\Throwable) {
            $categoryTotalsPrev = [];
            $prevTotal = 0.0;
        }

        $targetsWeek = $this->readTargetsMap($uid, $appName, 'cal_targets_week');
        $targetsMonth = $this->readTargetsMap($uid, $appName, 'cal_targets_month');
        $balanceConfig = is_array($targetsConfig['balance'] ?? null) ? $targetsConfig['balance'] : [];
        $balance = $this->overviewBalanceService->build(
            range: $range,
            targetsConfig: $targetsConfig,
            targetsWeek: $targetsWeek,
            targetsMonth: $targetsMonth,
            byCalMap: $agg['byCalMap'],
            idToName: $calendarLabels,
            categoryMeta: $categoryMeta,
            categoryTotals: $agg['categoryTotals'],
            totalHours: (float)$agg['totalHours'],
            categoryTotalsPrev: $categoryTotalsPrev,
            prevTotal: $prevTotal,
            categoryColors: array_map(static fn ($v) => $v ?: '#2563eb', $agg['categoryColors']),
            balanceConfig: $balanceConfig,
            perDayByCat: $agg['perDayByCat'],
            from: $from,
            to: $to,
            trendHistory: [],
        );

        $topCategory = null;
        foreach ($agg['categoryTotals'] as $catId => $hours) {
            if ($topCategory === null || $hours > $topCategory['hours']) {
                $label = $categoryMeta[$catId]['label'] ?? $catId;
                $topCategory = ['id' => $catId, 'label' => $label, 'hours' => round((float)$hours, 2)];
            }
        }

        $topCalendar = !empty($agg['byCalList'])
            ? [
                'id' => $agg['byCalList'][0]['id'],
                'label' => $agg['byCalList'][0]['calendar'],
                'hours' => round((float)$agg['byCalList'][0]['total_hours'], 2),
            ]
            : null;

        $selectedLabels = array_values(array_map(
            static fn (string $id) => $calendarLabels[$id] ?? $id,
            $selectedIds,
        ));

        $byDayList = array_values($agg['byDay']);
        usort($byDayList, static fn (array $a, array $b) => strcmp((string)$a['date'], (string)$b['date']));
        $busiestDay = null;
        foreach ($byDayList as $row) {
            if ($busiestDay === null || (float)$row['total_hours'] > (float)$busiestDay['total_hours']) {
                $busiestDay = $row;
            }
        }

        $today = (new \DateTimeImmutable('now', $userTz))->format('Y-m-d');
        $daysOff = 0;
        foreach ($byDayList as $row) {
            $rowDate = (string)($row['date'] ?? '');
            if ($rowDate > $today) {
                continue;
            }
            if ((float)($row['total_hours'] ?? 0.0) <= 0.0001) {
                $daysOff++;
            }
        }

        $longestEntry = null;
        if (!empty($agg['long']) && is_array($agg['long'][0] ?? null)) {
            $longestEntry = $agg['long'][0];
        }

        $targetSummary = $this->buildTargetSummary(
            range: $range,
            targetsConfig: $targetsConfig,
            targetsWeek: $targetsWeek,
            targetsMonth: $targetsMonth,
            byCalList: $agg['byCalList'],
            groupsById: $groupsById,
            from: $from,
            to: $to,
        );
        $reportVariant = $this->resolveReportVariant($onboardingState, $targetsConfig, $targetSummary);

        return [
            'range' => $range,
            'offset' => $offset,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'onboarding_strategy' => (string)($onboardingState['strategy'] ?? ''),
            'report_variant' => $reportVariant,
            'active_preset' => (string)$this->config->getUserValue($uid, $appName, 'active_preset', ''),
            'selected' => $selectedIds,
            'selected_labels' => $selectedLabels,
            'selected_count' => count($selectedIds),
            'total_hours' => round((float)$agg['totalHours'], 2),
            'future_hours' => round((float)$agg['futureTotalHours'], 2),
            'events' => (int)$agg['eventsCount'],
            'active_days' => (int)$agg['daysCount'],
            'avg_per_day' => round((float)$agg['avgPerDay'], 2),
            'avg_per_event' => round((float)$agg['avgPerEvent'], 2),
            'top_calendar' => $topCalendar,
            'top_category' => $topCategory,
            'busiest_day' => $busiestDay ? [
                'date' => (string)$busiestDay['date'],
                'hours' => round((float)$busiestDay['total_hours'], 2),
                'events' => (int)($busiestDay['events_count'] ?? 0),
            ] : null,
            'days_off' => $daysOff,
            'longest_session' => $longestEntry ? [
                'calendar' => (string)($longestEntry['calendar'] ?? ''),
                'summary' => (string)($longestEntry['summary'] ?? ''),
                'hours' => round((float)($longestEntry['duration_h'] ?? 0.0), 2),
                'start' => (string)($longestEntry['start'] ?? ''),
            ] : null,
            'balance' => [
                'index' => round((float)($balance['balanceIndex'] ?? 0.0), 3),
                'warnings' => array_values(array_map('strval', $balance['balanceOverview']['warnings'] ?? [])),
            ],
            'targets' => $targetSummary,
            'generated_at' => (new \DateTimeImmutable('now', $userTz))->format(\DateTimeInterface::ATOM),
            'charts' => $this->buildChartData($agg, $categoryMeta, $from, $to),
        ];
    }

    /**
     * @return array<string,float>
     */
    private function readTargetsMap(string $uid, string $appName, string $key): array {
        try {
            $raw = (string)$this->config->getUserValue($uid, $appName, $key, '');
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return [];
            }
            $clean = [];
            foreach ($decoded as $id => $value) {
                $hours = (float)$value;
                if ($hours > 0) {
                    $clean[(string)$id] = $hours;
                }
            }
            return $clean;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $targetsConfig
     * @param array<string,float> $targetsWeek
     * @param array<string,float> $targetsMonth
     * @param array<int,array<string,mixed>> $byCalList
     * @param array<string,mixed> $groupsById
     * @return array<string,mixed>
     */
    private function buildTargetSummary(
        string $range,
        array $targetsConfig,
        array $targetsWeek,
        array $targetsMonth,
        array $byCalList,
        array $groupsById,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $targetMap = $range === 'month' ? $targetsMonth : $targetsWeek;
        $totalTarget = max(0.0, (float)($targetsConfig['totalHours'] ?? 0.0));
        $totalActual = 0.0;
        foreach ($byCalList as $row) {
            $totalActual += max(0.0, (float)($row['total_hours'] ?? 0.0));
        }

        $totalPercent = $totalTarget > 0 ? ($totalActual / $totalTarget) * 100.0 : 0.0;
        $calendarPercent = $this->computeCalendarPercent($from, $to);
        $gap = $totalPercent - $calendarPercent;
        $thresholds = is_array($targetsConfig['pace']['thresholds'] ?? null)
            ? $targetsConfig['pace']['thresholds']
            : ['onTrack' => -2, 'atRisk' => -10];
        $totalStatus = $this->resolveTargetStatus($totalTarget, $totalPercent, $gap, $thresholds);

        $rows = [];
        $calendarRows = [];
        $byCalMap = [];
        foreach ($byCalList as $row) {
            $calId = (string)($row['id'] ?? '');
            if ($calId !== '') {
                $byCalMap[$calId] = $row;
            }
        }
        foreach ((array)($targetsConfig['categories'] ?? []) as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $catId = (string)($cat['id'] ?? '');
            if ($catId === '') {
                continue;
            }
            $groupIds = array_map('intval', is_array($cat['groupIds'] ?? null) ? $cat['groupIds'] : []);
            $actual = 0.0;
            foreach ($byCalList as $row) {
                $calId = (string)($row['id'] ?? '');
                $groupId = (int)($groupsById[$calId] ?? 0);
                if (!in_array($groupId, $groupIds, true)) {
                    continue;
                }
                $actual += max(0.0, (float)($row['total_hours'] ?? 0.0));
            }
            $target = max(0.0, (float)($cat['targetHours'] ?? 0.0));
            $percent = $target > 0 ? ($actual / $target) * 100.0 : 0.0;
            $status = $this->resolveTargetStatus($target, $percent, $percent - $calendarPercent, $thresholds);
            if ($target <= 0 && $actual <= 0) {
                continue;
            }
            $rows[] = [
                'label' => trim((string)($cat['label'] ?? $catId)),
                'actual' => round($actual, 2),
                'target' => round($target, 2),
                'remaining' => round(max(0.0, $target - $actual), 2),
                'percent' => round($percent, 1),
                'status' => $status,
            ];
        }

        foreach ($targetMap as $calId => $target) {
            $actual = max(0.0, (float)($byCalMap[$calId]['total_hours'] ?? 0.0));
            $target = max(0.0, (float)$target);
            if ($target <= 0 && $actual <= 0) {
                continue;
            }
            $percent = $target > 0 ? ($actual / $target) * 100.0 : 0.0;
            $status = $this->resolveTargetStatus($target, $percent, $percent - $calendarPercent, $thresholds);
            $calendarRows[] = [
                'id' => $calId,
                'label' => trim((string)($byCalMap[$calId]['calendar'] ?? $calId)),
                'actual' => round($actual, 2),
                'target' => round($target, 2),
                'remaining' => round(max(0.0, $target - $actual), 2),
                'percent' => round($percent, 1),
                'status' => $status,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $statusOrder = ['behind' => 0, 'at_risk' => 1, 'on_track' => 2, 'done' => 3, 'none' => 4];
            $aStatus = $statusOrder[$a['status']] ?? 99;
            $bStatus = $statusOrder[$b['status']] ?? 99;
            if ($aStatus !== $bStatus) {
                return $aStatus <=> $bStatus;
            }
            return strcmp((string)$a['label'], (string)$b['label']);
        });
        usort($calendarRows, static function (array $a, array $b): int {
            $statusOrder = ['behind' => 0, 'at_risk' => 1, 'on_track' => 2, 'done' => 3, 'none' => 4];
            $aStatus = $statusOrder[$a['status']] ?? 99;
            $bStatus = $statusOrder[$b['status']] ?? 99;
            if ($aStatus !== $bStatus) {
                return $aStatus <=> $bStatus;
            }
            return strcmp((string)$a['label'], (string)$b['label']);
        });

        return [
            'total' => [
                'actual' => round($totalActual, 2),
                'target' => round($totalTarget, 2),
                'remaining' => round(max(0.0, $totalTarget - $totalActual), 2),
                'percent' => round($totalPercent, 1),
                'calendarPercent' => round($calendarPercent, 1),
                'status' => $totalStatus,
            ],
            'calendars' => array_slice($calendarRows, 0, 5),
            'categories' => array_slice($rows, 0, 5),
        ];
    }

    /**
     * @param array<string,mixed> $onboardingState
     * @param array<string,mixed> $targetsConfig
     * @param array<string,mixed> $targetSummary
     */
    private function resolveReportVariant(array $onboardingState, array $targetsConfig, array $targetSummary): string {
        $strategy = (string)($onboardingState['strategy'] ?? '');
        if ($strategy === 'total_only') {
            return 'single_goal';
        }
        if ($strategy === 'total_plus_categories') {
            return 'calendar_goals';
        }
        if ($strategy === 'full_granular') {
            return 'category_and_calendar_goals';
        }

        $categories = is_array($targetsConfig['categories'] ?? null) ? $targetsConfig['categories'] : [];
        if ($categories !== []) {
            return 'category_and_calendar_goals';
        }

        $calendarRows = is_array($targetSummary['calendars'] ?? null) ? $targetSummary['calendars'] : [];
        if ($calendarRows !== []) {
            return 'calendar_goals';
        }

        return 'single_goal';
    }

    /**
     * @param array<string,mixed> $thresholds
     */
    private function resolveTargetStatus(float $target, float $percent, float $gap, array $thresholds): string {
        if ($target <= 0) {
            return 'none';
        }
        if ($percent >= 100.0) {
            return 'done';
        }
        $onTrack = (float)($thresholds['onTrack'] ?? -2);
        $atRisk = (float)($thresholds['atRisk'] ?? -10);
        if ($gap >= $onTrack) {
            return 'on_track';
        }
        if ($gap >= $atRisk) {
            return 'at_risk';
        }
        return 'behind';
    }

    /**
     * @param array<string,mixed> $agg
     * @param array<string,mixed> $categoryMeta
     * @return array<string,mixed>
     */
    private function buildChartData(array $agg, array $categoryMeta, \DateTimeImmutable $from, \DateTimeImmutable $to): array {
        // DOW averages: total per weekday divided by number of that weekday in the period
        $dowOrder = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $dowCounts = array_fill_keys($dowOrder, 0);
        $cursor = $from;
        while ($cursor <= $to) {
            $d = $cursor->format('D');
            if (isset($dowCounts[$d])) {
                $dowCounts[$d]++;
            }
            $cursor = $cursor->modify('+1 day');
        }
        $dowTotals = is_array($agg['dowTotals'] ?? null) ? $agg['dowTotals'] : [];
        $dowAvg = [];
        foreach ($dowOrder as $d) {
            $total = (float)($dowTotals[$d] ?? 0.0);
            $count = max(1, $dowCounts[$d]);
            $dowAvg[$d] = round($total / $count, 2);
        }

        // Calendar pie: top 7 by hours
        $byCalList = is_array($agg['byCalList'] ?? null) ? $agg['byCalList'] : [];
        $calPie = [];
        foreach (array_slice($byCalList, 0, 7) as $row) {
            $hours = round((float)($row['total_hours'] ?? 0.0), 2);
            if ($hours <= 0) continue;
            $calPie[] = ['label' => (string)($row['calendar'] ?? ''), 'hours' => $hours];
        }

        // Category pie: from categoryTotals + colors + labels
        $categoryTotals = is_array($agg['categoryTotals'] ?? null) ? $agg['categoryTotals'] : [];
        $categoryColors = is_array($agg['categoryColors'] ?? null) ? $agg['categoryColors'] : [];
        $catPie = [];
        foreach ($categoryTotals as $catId => $hours) {
            $hours = round((float)$hours, 2);
            if ($hours <= 0) continue;
            $label = (string)(($categoryMeta[$catId]['label'] ?? null) ?: $catId);
            $color = (string)($categoryColors[$catId] ?? '');
            $catPie[] = ['label' => $label, 'hours' => $hours, 'color' => $color !== '' ? $color : null];
        }
        usort($catPie, static fn ($a, $b) => $b['hours'] <=> $a['hours']);

        return [
            'dow_avg' => $dowAvg,
            'dow_order' => $dowOrder,
            'cal_pie' => $calPie,
            'cat_pie' => $catPie,
        ];
    }

    private function computeCalendarPercent(\DateTimeImmutable $from, \DateTimeImmutable $to): float {
        $start = $from->setTime(0, 0, 0);
        $end = $to->setTime(0, 0, 0);
        $today = (new \DateTimeImmutable('today', $from->getTimezone()))->setTime(0, 0, 0);
        if ($today < $start) {
            return 0.0;
        }
        if ($today > $end) {
            return 100.0;
        }
        $totalDays = max(1, (int)$start->diff($end)->format('%a') + 1);
        $elapsedDays = (int)$start->diff($today)->format('%a') + 1;
        return min(100.0, max(0.0, ($elapsedDays / $totalDays) * 100.0));
    }
}
