<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

final class OverviewStatsDeltaService {
    public function __construct(
        private CalendarAccessService $calendarAccess,
        private OverviewHistoryService $historyService,
        private OverviewEventsCollector $eventsCollector,
        private OverviewAggregationService $aggregationService,
    ) {}

    /**
     * @param array{
     *   range: string,
     *   offset: int,
     *   principal: string,
     *   calendars: array<int, object>,
     *   includeAll: bool,
     *   selectedIds: string[],
     *   mapCalToCategory: callable,
     *   userTz: \DateTimeZone,
     *   allDayHours: float,
     *   categoryMeta: array<string, array{id: string, label: string}>,
     *   maxPerCal: int,
     *   maxTotal: int,
     *   colorsById: array<string, string>,
     *   weekStart: int,
     *   currentTotalHours: float,
     *   currentAvgPerDay: float,
     *   currentAvgPerEvent: float,
     *   currentEventsCount: int,
     *   currentWeekendShare: float,
     *   currentEveningShare: float,
     *   calendarPriorities?: array<string, mixed>
     * } $context
     * @return array{
     *   delta: array<string, float|int>,
     *   categoryTotalsPrev: array<string, float>,
     *   prevTotal: float,
     *   prevEvents: int,
     *   prevDaysSeen: array<int, string>,
     *   prevWeekendShare: float,
     *   prevEveningShare: float
     * }
     */
    public function build(array $context): array {
        $range = (string)$context['range'];
        $offset = (int)$context['offset'];
        $principal = (string)$context['principal'];
        $cals = $context['calendars'];
        $includeAll = (bool)$context['includeAll'];
        $selectedIds = $context['selectedIds'];
        $mapCalToCategory = $context['mapCalToCategory'];
        $userTz = $context['userTz'];
        $allDayHours = (float)$context['allDayHours'];
        $categoryMeta = $context['categoryMeta'];
        $maxPerCal = (int)$context['maxPerCal'];
        $maxTotal = (int)$context['maxTotal'];
        $colorsById = $context['colorsById'];
        $weekStart = (int)($context['weekStart'] ?? 1);
        $calendarPriorities = is_array($context['calendarPriorities'] ?? null) ? $context['calendarPriorities'] : [];

        $currentTotalHours = (float)$context['currentTotalHours'];
        $currentAvgPerDay = (float)$context['currentAvgPerDay'];
        $currentAvgPerEvent = (float)$context['currentAvgPerEvent'];
        $currentEventsCount = (int)$context['currentEventsCount'];
        $currentWeekendShare = (float)$context['currentWeekendShare'];
        $currentEveningShare = (float)$context['currentEveningShare'];

        [$pf, $pt] = $this->calendarAccess->rangeBounds($range, $offset - 1, $userTz, $weekStart);
        $previousMetrics = $this->historyService->collectCategoryTotalsForRange(
            from: $pf,
            to: $pt,
            categoryMeta: $categoryMeta,
            calendars: $cals,
            includeAll: $includeAll,
            selectedIds: $selectedIds,
            principal: $principal,
            mapCalToCategory: $mapCalToCategory,
            userTz: $userTz,
            allDayHours: $allDayHours,
            captureDays: true,
            calendarPriorities: $calendarPriorities,
        );

        $categoryTotalsPrev = $previousMetrics['totals'];
        $prevTotal = (float)$previousMetrics['total'];
        $prevEvents = (int)$previousMetrics['events'];
        $prevDaysSeen = $previousMetrics['daysSeen'];
        $prevDaysCount = count($prevDaysSeen);
        $prevAvgPerDay = $prevDaysCount ? ($prevTotal / $prevDaysCount) : 0;
        $prevAvgPerEvent = $prevEvents ? ($prevTotal / $prevEvents) : 0;
        $prevWeekendShare = 0.0;
        $prevEveningShare = 0.0;

        if ($prevEvents > 0) {
            $prevCollect = $this->eventsCollector->collect(
                principal: $principal,
                cals: $cals,
                includeAll: $includeAll,
                selectedIds: $selectedIds,
                from: $pf,
                to: $pt,
                maxPerCal: $maxPerCal,
                maxTotal: $maxTotal,
                debug: false,
            );
            if (!empty($prevCollect['events'])) {
                $prevAgg = $this->aggregationService->aggregate(
                    events: $prevCollect['events'],
                    from: $pf,
                    to: $pt,
                    userTz: $userTz,
                    allDayHours: $allDayHours,
                    colorsById: $colorsById,
                    categoryMeta: $categoryMeta,
                    mapCalToCategory: $mapCalToCategory,
                    calendarPriorities: $calendarPriorities,
                );
                $prevTotalHours = (float)$prevAgg['totalHours'];
                $prevHod = $prevAgg['hod'];
                $prevDowOrder = $prevAgg['dowOrder'] ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                $prevWeekend = array_sum($prevHod['Sat'] ?? []) + array_sum($prevHod['Sun'] ?? []);
                $prevEvening = 0.0;
                for ($i = 18; $i < 24; $i++) {
                    foreach ($prevDowOrder as $d) {
                        $prevEvening += (float)($prevHod[$d][$i] ?? 0.0);
                    }
                }
                $prevWeekendShare = $prevTotalHours > 0 ? round(100 * $prevWeekend / $prevTotalHours, 1) : 0.0;
                $prevEveningShare = $prevTotalHours > 0 ? round(100 * $prevEvening / $prevTotalHours, 1) : 0.0;
            }
        }

        $delta = [
            'total_hours' => round($currentTotalHours - $prevTotal, 2),
            'avg_per_day' => round($currentAvgPerDay - $prevAvgPerDay, 2),
            'avg_per_event' => round($currentAvgPerEvent - $prevAvgPerEvent, 2),
            'events' => $currentEventsCount - $prevEvents,
            'weekend_share' => round($currentWeekendShare - $prevWeekendShare, 1),
            'evening_share' => round($currentEveningShare - $prevEveningShare, 1),
        ];

        return [
            'delta' => $delta,
            'categoryTotalsPrev' => $categoryTotalsPrev,
            'prevTotal' => $prevTotal,
            'prevEvents' => $prevEvents,
            'prevDaysSeen' => $prevDaysSeen,
            'prevWeekendShare' => $prevWeekendShare,
            'prevEveningShare' => $prevEveningShare,
        ];
    }
}
