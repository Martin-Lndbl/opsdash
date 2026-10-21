<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

final class OverviewHistoryService {
    private const MAX_LOOKBACK = 6;

    public function __construct(
        private CalendarAccessService $calendarAccess,
        private OverviewEventsCollector $eventsCollector,
    ) {
    }

    /**
     * Build a balance trend history up to the configured lookback by fetching category totals for each
     * offset and turning them into share-based history slots.
     *
     * @param string $range
     * @param int $currentOffset
     * @param int $lookback
     * @param array<int, object> $calendars
     * @param bool $includeAll
     * @param string[] $selectedIds
     * @param string $principal
     * @param callable(string): string $mapCalToCategory
     * @param \DateTimeZone $userTz
     * @param float $allDayHours
     * @param array<string,array{id:string,label:string}> $categoryMeta
     * @param array<string,mixed> $targetsConfig
     * @param array<string,float|int|string> $targetsWeek
     * @param array<string,float|int|string> $targetsMonth
     * @param array<string,string> $idToName
     * @param int $weekStart
     * @return array<int, array{offset:int,label:string,categories:array<int,array{id:string,label:string,share:float}>,index:float}>
     */
    public function buildBalanceHistory(
        string $range,
        int $currentOffset,
        int $lookback,
        array $calendars,
        bool $includeAll,
        array $selectedIds,
        string $principal,
        callable $mapCalToCategory,
        \DateTimeZone $userTz,
        float $allDayHours,
        array $categoryMeta,
        array $targetsConfig,
        array $targetsWeek,
        array $targetsMonth,
        array $idToName,
        int $weekStart = 1,
    ): array {
        $history = [];
        $lookback = max(1, min(self::MAX_LOOKBACK, $lookback));

        for ($i = 1; $i <= $lookback; $i++) {
            $offset = $currentOffset - $i;
            [$from, $to] = $this->calendarAccess->rangeBounds($range, $offset, $userTz, $weekStart);
            $rangeTotals = $this->collectRangeCategoryTotals(
                from: $from,
                to: $to,
                categoryMeta: $categoryMeta,
                calendars: $calendars,
                includeAll: $includeAll,
                selectedIds: $selectedIds,
                principal: $principal,
                mapCalToCategory: $mapCalToCategory,
                userTz: $userTz,
                allDayHours: $allDayHours,
            );
            $history[] = $this->buildTrendHistoryEntry(
                range: $range,
                offsetStep: $i,
                categoryTotals: $rangeTotals['totals'],
                totalHours: $rangeTotals['total'],
                categoryMeta: $categoryMeta,
                targetsConfig: $targetsConfig,
                targetsWeek: $targetsWeek,
                targetsMonth: $targetsMonth,
                byCalTotals: $rangeTotals['byCal'],
                idToName: $idToName,
            );
        }

        return $history;
    }

    /**
     * @param array<string, array{total_hours: float}> $currentByDay
     * @param array<int, int> $precomputedDaysWorked
     * @param array<int, object> $calendars
     * @param string[] $selectedIds
     * @return array<int, array<string, mixed>>
     */
    public function buildDayOffTrend(
        string $range,
        int $offset,
        \DateTimeImmutable $currentFrom,
        \DateTimeImmutable $currentTo,
        \DateTimeImmutable $analysisTo,
        array $currentByDay,
        bool $includeAll,
        array $selectedIds,
        array $calendars,
        string $principal,
        \DateTimeZone $userTz,
        int $lookbackWeeks,
        int $weekStart = 1,
        array $precomputedDaysWorked = [],
    ): array {
        $maxLookback = max(1, min(self::MAX_LOOKBACK, $lookbackWeeks ?: 3));
        $trend = [];

        $dayMap = [];
        foreach ($currentByDay as $key => $payload) {
            if (!is_array($payload)) {
                continue;
            }
            $dateKey = (string)($payload['date'] ?? $key);
            $dateKey = trim($dateKey);
            if ($dateKey === '') {
                continue;
            }
            $dayMap[$dateKey] = $payload;
        }

        $trend[] = $this->summarizeCurrentDayOff($dayMap, $currentFrom, $currentTo, $analysisTo, $range);

        for ($step = 1; $step <= $maxLookback; $step++) {
            [$lookFrom, $lookTo] = $this->calendarAccess->rangeBounds($range, $offset - $step, $userTz, $weekStart);
            $workedDays = $precomputedDaysWorked[$step] ?? $this->countWorkedDaysForRange(
                calendars: $calendars,
                includeAll: $includeAll,
                selectedIds: $selectedIds,
                principal: $principal,
                from: $lookFrom,
                to: $lookTo,
                userTz: $userTz,
            );
            $trend[] = $this->summarizeDayOffWindow($lookFrom, $lookTo, $workedDays, $step, $range);
        }

        return $trend;
    }

    /**
     * @param array<int, object> $calendars
     * @param array<string,array{id:string,label:string}> $categoryMeta
     * @param callable(string): string $mapCalToCategory
     * @return array{totals: array<string,float>, total: float, events: int, daysSeen: string[]}
     */
    public function collectCategoryTotalsForRange(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $categoryMeta,
        array $calendars,
        bool $includeAll,
        array $selectedIds,
        string $principal,
        callable $mapCalToCategory,
        \DateTimeZone $userTz,
        float $allDayHours,
        bool $captureDays = false,
    ): array {
        return $this->collectRangeCategoryTotals(
            from: $from,
            to: $to,
            categoryMeta: $categoryMeta,
            calendars: $calendars,
            includeAll: $includeAll,
            selectedIds: $selectedIds,
            principal: $principal,
            mapCalToCategory: $mapCalToCategory,
            userTz: $userTz,
            allDayHours: $allDayHours,
            captureDays: $captureDays,
        );
    }

    /**
     * @param array<string, array{total_hours: float}> $currentByDay
     */
    private function summarizeCurrentDayOff(
        array $currentByDay,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $analysisTo,
        string $range,
    ): array {
        $effectiveTo = $analysisTo->getTimestamp() < $from->getTimestamp()
            ? $from
            : ($analysisTo < $to ? $analysisTo : $to);
        $totalDays = $this->countDaysInclusive($from, $effectiveTo);
        $daysOff = 0;
        $daysWorked = 0;
        $cursor = $from;
        while ($cursor->getTimestamp() <= $effectiveTo->getTimestamp()) {
            $key = $cursor->format('Y-m-d');
            $hours = isset($currentByDay[$key]) ? (float)($currentByDay[$key]['total_hours'] ?? 0.0) : 0.0;
            if ($hours <= 0.01) {
                $daysOff++;
            } else {
                $daysWorked++;
            }
            $next = $cursor->modify('+1 day');
            if ($next->getTimestamp() === $cursor->getTimestamp()) {
                break;
            }
            $cursor = $next;
        }
        return [
            'offset' => 0,
            'label' => $this->formatDayOffLabel($range, 0),
            'from' => $from->format('Y-m-d'),
            'to' => $effectiveTo->format('Y-m-d'),
            'totalDays' => $totalDays,
            'daysOff' => $daysOff,
            'daysWorked' => $daysWorked,
        ];
    }

    private function summarizeDayOffWindow(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $daysWorked,
        int $offsetStep,
        string $range,
    ): array {
        $totalDays = $this->countDaysInclusive($from, $to);
        $clampedWorked = max(0, min($totalDays, $daysWorked));
        $daysOff = max(0, $totalDays - $clampedWorked);
        return [
            'offset' => $offsetStep,
            'label' => $this->formatDayOffLabel($range, $offsetStep),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'totalDays' => $totalDays,
            'daysOff' => $daysOff,
            'daysWorked' => $clampedWorked,
        ];
    }

    private function formatDayOffLabel(string $range, int $offsetStep): string {
        if ($offsetStep === 0) {
            return $range === 'month' ? 'This month' : 'This week';
        }
        $unit = $range === 'month' ? 'mo' : 'wk';
        return sprintf('-%d %s', $offsetStep, $unit);
    }

    private function countDaysInclusive(\DateTimeImmutable $from, \DateTimeImmutable $to): int {
        $start = $from->setTime(0, 0, 0);
        $end = $to->setTime(0, 0, 0);
        if ($end->getTimestamp() < $start->getTimestamp()) {
            return 0;
        }
        $diffDays = (int)$end->diff($start)->format('%a');
        return $diffDays + 1;
    }

    private function countWorkedDaysForRange(
        array $calendars,
        bool $includeAll,
        array $selectedIds,
        string $principal,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $userTz,
    ): int {
        if ($to->getTimestamp() < $from->getTimestamp()) {
            return 0;
        }

        $daysSeen = [];
        $collect = $this->eventsCollector->collect(
            principal: $principal,
            cals: $calendars,
            includeAll: $includeAll,
            selectedIds: $selectedIds,
            from: $from,
            to: $to,
            maxPerCal: 2000,
            maxTotal: 5000,
            debug: false,
        );
        foreach ($collect['events'] as $event) {
            if (!is_array($event)) {
                continue;
            }
            $this->markWorkedDays($event, $userTz, $from, $to, $daysSeen);
        }

        return count($daysSeen);
    }

    /**
     * @param array<int, object> $calendars
     * @param array<string,array{id:string,label:string}> $categoryMeta
     * @param callable(string): string $mapCalToCategory
     * @return array{totals: array<string,float>, total: float, events: int, daysSeen: string[], byCal: array<string,float>}
     */
    private function collectRangeCategoryTotals(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $categoryMeta,
        array $calendars,
        bool $includeAll,
        array $selectedIds,
        string $principal,
        callable $mapCalToCategory,
        \DateTimeZone $userTz,
        float $allDayHours,
        bool $captureDays = false,
    ): array {
        $categoryTotals = [];
        foreach ($categoryMeta as $catId => $_meta) {
            $categoryTotals[$catId] = 0.0;
        }
        $totalHours = 0.0;
        $eventCount = 0;
        $daysSeen = [];
        $byCalTotals = [];

        $collect = $this->eventsCollector->collect(
            principal: $principal,
            cals: $calendars,
            includeAll: $includeAll,
            selectedIds: $selectedIds,
            from: $from,
            to: $to,
            maxPerCal: 2000,
            maxTotal: 5000,
            debug: false,
        );

        foreach ($collect['events'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $isAllDay = !empty($row['allday']);
            $eventHours = (float)($row['hours'] ?? 0.0);
            if ($eventHours < 0) {
                $eventHours = 0.0;
            }
            if ($isAllDay) {
                $daysRef = $captureDays ? $daysSeen : null;
                $eventHours = $this->normalizeAllDayEventHours($row, $userTz, $allDayHours, $daysRef);
                if ($captureDays && $daysRef !== null) {
                    $daysSeen = $daysRef;
                }
            } elseif ($eventHours <= 0) {
                // Fallback for providers that omit parsed `hours` on timed events.
                $eventHours = $this->normalizeTimedEventHours($row, $userTz);
            } elseif ($captureDays) {
                $startStr = (string)($row['start'] ?? '');
                $dayKey = substr($startStr, 0, 10);
                if ($dayKey !== '') {
                    $daysSeen[$dayKey] = true;
                }
            }

            $calId = (string)($row['calendar_id'] ?? ($row['calendar'] ?? ''));
            $catId = $mapCalToCategory($calId);
            $categoryTotals[$catId] = ($categoryTotals[$catId] ?? 0.0) + $eventHours;
            $byCalTotals[$calId] = ($byCalTotals[$calId] ?? 0.0) + $eventHours;
            $totalHours += $eventHours;
            $eventCount++;
        }

        return [
            'totals' => $categoryTotals,
            'total' => $totalHours,
            'events' => $eventCount,
            'daysSeen' => $captureDays ? array_keys($daysSeen) : [],
            'byCal' => $byCalTotals,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,bool>|null $daysSeen
     */
    private function normalizeAllDayEventHours(
        array $row,
        \DateTimeZone $userTz,
        float $allDayHours,
        ?array &$daysSeen,
    ): float {
        $startStr = (string)($row['start'] ?? '');
        $endStr   = (string)($row['end'] ?? '');
        $startTzName = (string)($row['startTz'] ?? '');
        $endTzName   = (string)($row['endTz'] ?? '');

        try {
            $srcTzStart = $startTzName !== '' ? new \DateTimeZone($startTzName) : $userTz;
        } catch (\Throwable) {
            $srcTzStart = new \DateTimeZone('UTC');
        }
        try {
            $srcTzEnd = $endTzName !== '' ? new \DateTimeZone($endTzName) : $userTz;
        } catch (\Throwable) {
            $srcTzEnd = new \DateTimeZone('UTC');
        }
        $dtStart = $startStr !== '' ? new \DateTimeImmutable($startStr, $srcTzStart) : null;
        $dtEnd   = $endStr !== '' ? new \DateTimeImmutable($endStr, $srcTzEnd)   : null;
        $dtStartUser = $dtStart?->setTimezone($userTz);
        $dtEndUser   = $dtEnd?->setTimezone($userTz);
        if ($dtStartUser) {
            $dtStartUser = $dtStartUser->setTime(0, 0, 0);
        }
        if ($dtEndUser) {
            $dtEndUser = $dtEndUser->setTime(0, 0, 0);
        }
        if (!$dtEndUser && $dtStartUser) {
            $dtEndUser = $dtStartUser->modify('+1 day');
        }
        $daysSpanned = 1;
        if ($dtStartUser && $dtEndUser) {
            $eventDurSeconds = max(0, $dtEndUser->getTimestamp() - $dtStartUser->getTimestamp());
            if ($eventDurSeconds <= 0) {
                $eventDurSeconds = 86400;
            }
            $daysSpanned = max(1, (int)ceil($eventDurSeconds / 86400));
        }
        if ($dtStartUser && $daysSeen !== null) {
            $currentDay = $dtStartUser;
            for ($i = 0; $i < $daysSpanned; $i++) {
                $daysSeen[$currentDay->format('Y-m-d')] = true;
                $currentDay = $currentDay->modify('+1 day');
            }
        }
        return $allDayHours * $daysSpanned;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function normalizeTimedEventHours(array $row, \DateTimeZone $userTz): float {
        $startStr = (string)($row['start'] ?? '');
        $endStr   = (string)($row['end'] ?? '');
        if ($startStr === '' || $endStr === '') {
            return 0.0;
        }

        $startTzName = (string)($row['startTz'] ?? '');
        $endTzName   = (string)($row['endTz'] ?? '');
        try {
            $srcTzStart = $startTzName !== '' ? new \DateTimeZone($startTzName) : $userTz;
        } catch (\Throwable) {
            $srcTzStart = new \DateTimeZone('UTC');
        }
        try {
            $srcTzEnd = $endTzName !== '' ? new \DateTimeZone($endTzName) : $userTz;
        } catch (\Throwable) {
            $srcTzEnd = new \DateTimeZone('UTC');
        }

        try {
            $dtStart = new \DateTimeImmutable($startStr, $srcTzStart);
            $dtEnd = new \DateTimeImmutable($endStr, $srcTzEnd);
        } catch (\Throwable) {
            return 0.0;
        }

        $dtStartUser = $dtStart->setTimezone($userTz);
        $dtEndUser = $dtEnd->setTimezone($userTz);
        if ($dtEndUser <= $dtStartUser) {
            return 0.0;
        }
        return ($dtEndUser->getTimestamp() - $dtStartUser->getTimestamp()) / 3600.0;
    }

    /**
     * @param array<string,float> $categoryTotals
     * @param array<string,array{id:string,label:string}> $categoryMeta
     * @param array<string,mixed> $targetsConfig
     * @param array<string,float|int|string> $targetsWeek
     * @param array<string,float|int|string> $targetsMonth
     * @param array<string,float> $byCalTotals
     * @param array<string,string> $idToName
     */
    private function buildTrendHistoryEntry(
        string $range,
        int $offsetStep,
        array $categoryTotals,
        float $totalHours,
        array $categoryMeta,
        array $targetsConfig,
        array $targetsWeek,
        array $targetsMonth,
        array $byCalTotals,
        array $idToName,
    ): array {
        $categories = [];
        foreach ($categoryMeta as $catId => $meta) {
            $share = $totalHours > 0 ? round((($categoryTotals[$catId] ?? 0.0) / $totalHours) * 100, 1) : 0.0;
            $categories[] = [
                'id' => $catId,
                'label' => $meta['label'],
                'share' => $share,
            ];
        }
        return [
            'offset' => $offsetStep,
            'label' => $this->formatTrendHistoryLabel($range, $offsetStep),
            'categories' => $categories,
            'index' => round($this->computeBalanceIndexForHistory(
                range: $range,
                totalHours: $totalHours,
                categoryTotals: $categoryTotals,
                byCalTotals: $byCalTotals,
                targetsConfig: $targetsConfig,
                targetsWeek: $targetsWeek,
                targetsMonth: $targetsMonth,
                categoryMeta: $categoryMeta,
                idToName: $idToName,
            ), 3),
        ];
    }

    /**
     * @param array<string,float> $categoryTotals
     * @param array<string,float> $byCalTotals
     * @param array<string,mixed> $targetsConfig
     * @param array<string,float|int|string> $targetsWeek
     * @param array<string,float|int|string> $targetsMonth
     * @param array<string,array{id:string,label:string}> $categoryMeta
     * @param array<string,string> $idToName
     */
    private function computeBalanceIndexForHistory(
        string $range,
        float $totalHours,
        array $categoryTotals,
        array $byCalTotals,
        array $targetsConfig,
        array $targetsWeek,
        array $targetsMonth,
        array $categoryMeta,
        array $idToName,
    ): float {
        if ($totalHours <= 0) {
            return 0.0;
        }

        $balanceConfig = is_array($targetsConfig['balance'] ?? null) ? $targetsConfig['balance'] : [];
        $basis = (string)(($balanceConfig['index'] ?? [])['basis'] ?? 'category');
        if ($basis === 'off') {
            return 0.0;
        }

        $expectedShares = [];
        $buckets = [];
        $useCategories = ($basis === 'category' || $basis === 'both');
        $useCalendars = ($basis === 'calendar' || $basis === 'both');

        if ($useCategories) {
            $catTargets = is_array($targetsConfig['categories'] ?? null) ? $targetsConfig['categories'] : [];
            $targetSum = 0.0;
            foreach ($catTargets as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $id = (string)($cat['id'] ?? '');
                $tgt = (float)($cat['targetHours'] ?? 0.0);
                if ($id === '' || $tgt <= 0) {
                    continue;
                }
                $expectedShares['cat:' . $id] = $tgt;
                $targetSum += $tgt;
            }
            if ($targetSum > 0) {
                foreach ($expectedShares as $key => $value) {
                    if (str_starts_with($key, 'cat:')) {
                        $expectedShares[$key] = $value / $targetSum;
                    }
                }
            }
            foreach ($categoryMeta as $catId => $meta) {
                $buckets[] = [
                    'id' => 'cat:' . $catId,
                    'label' => $meta['label'],
                    'share' => ($categoryTotals[$catId] ?? 0.0) / $totalHours,
                ];
                $expectedShares['cat:' . $catId] = (float)($expectedShares['cat:' . $catId] ?? 0.0);
            }
        }

        if ($useCalendars) {
            $targetMap = $range === 'month' ? $targetsMonth : $targetsWeek;
            $targetSum = 0.0;
            foreach ($targetMap as $calId => $targetVal) {
                $t = (float)$targetVal;
                if ($t <= 0) {
                    continue;
                }
                $expectedShares['cal:' . (string)$calId] = $t;
                $targetSum += $t;
            }
            if ($targetSum > 0) {
                foreach ($expectedShares as $key => $value) {
                    if (str_starts_with($key, 'cal:')) {
                        $expectedShares[$key] = $value / $targetSum;
                    }
                }
            }
            foreach ($byCalTotals as $calId => $hours) {
                $buckets[] = [
                    'id' => 'cal:' . $calId,
                    'label' => $idToName[$calId] ?? $calId,
                    'share' => $hours / $totalHours,
                ];
                $expectedShares['cal:' . $calId] = (float)($expectedShares['cal:' . $calId] ?? 0.0);
            }
        }

        if ($buckets === []) {
            return 0.0;
        }

        $maxDeviationAbs = 0.0;
        foreach ($buckets as $bucket) {
            $actual = (float)$bucket['share'];
            $expected = (float)($expectedShares[$bucket['id']] ?? 0.0);
            $maxDeviationAbs = max($maxDeviationAbs, abs($actual - $expected));
        }

        return max(0.0, min(1.0, 1.0 - $maxDeviationAbs));
    }

    private function formatTrendHistoryLabel(string $range, int $offsetStep): string {
        if ($offsetStep === 1) {
            return $range === 'month' ? 'Last month' : 'Last week';
        }
        $unit = $range === 'month' ? 'mo' : 'wk';
        return sprintf('-%d %s', $offsetStep, $unit);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, bool> $daysSeen
     */
    private function markWorkedDays(
        array $event,
        \DateTimeZone $userTz,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        array &$daysSeen,
    ): void {
        $isAllDay = !empty($event['allday']);
        $startStr = (string)($event['start'] ?? '');
        $endStr = (string)($event['end'] ?? '');
        $startTzName = (string)($event['startTz'] ?? '');
        $endTzName = (string)($event['endTz'] ?? '');
        try {
            $srcTzStart = $startTzName !== '' ? new \DateTimeZone($startTzName) : $userTz;
        } catch (\Throwable) {
            $srcTzStart = new \DateTimeZone('UTC');
        }
        try {
            $srcTzEnd = $endTzName !== '' ? new \DateTimeZone($endTzName) : $userTz;
        } catch (\Throwable) {
            $srcTzEnd = new \DateTimeZone('UTC');
        }
        $dtStart = $startStr !== '' ? new \DateTimeImmutable($startStr, $srcTzStart) : null;
        $dtEnd = $endStr !== '' ? new \DateTimeImmutable($endStr, $srcTzEnd) : null;
        $dtStartUser = $dtStart?->setTimezone($userTz);
        $dtEndUser = $dtEnd?->setTimezone($userTz);
        if ($isAllDay && $dtStartUser) {
            $dtStartUser = $dtStartUser->setTime(0, 0, 0);
        }
        if ($isAllDay) {
            if ($dtEndUser) {
                $dtEndUser = $dtEndUser->setTime(0, 0, 0);
            }
            if (!$dtEndUser && $dtStartUser) {
                $dtEndUser = $dtStartUser->modify('+1 day');
            }
        }
        $spanStart = $dtStartUser ?? $dtEndUser;
        $spanEnd = $dtEndUser ?? $dtStartUser;
        if ($spanStart === null) {
            return;
        }
        if ($spanEnd === null) {
            $spanEnd = $spanStart;
        }
        if ($spanEnd->getTimestamp() < $spanStart->getTimestamp()) {
            $spanEnd = $spanStart;
        }
        $current = $spanStart->setTime(0, 0, 0);
        $endDay = $spanEnd->setTime(0, 0, 0);
        $rangeStartDay = $rangeStart->setTime(0, 0, 0);
        $rangeEndDay = $rangeEnd->setTime(0, 0, 0);
        while ($current->getTimestamp() <= $endDay->getTimestamp()) {
            if (
                $current->getTimestamp() >= $rangeStartDay->getTimestamp() &&
                $current->getTimestamp() <= $rangeEndDay->getTimestamp()
            ) {
                $daysSeen[$current->format('Y-m-d')] = true;
            }
            $nextDay = $current->modify('+1 day');
            if ($nextDay->getTimestamp() === $current->getTimestamp()) {
                break;
            }
            $current = $nextDay;
        }
    }
}
