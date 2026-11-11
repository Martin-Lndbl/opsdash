<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

final class OverviewDataService {
    public function __construct(
        private OverviewEventsCollector $eventsCollector,
        private OverviewAggregationService $aggregationService,
        private OverviewChartsBuilder $chartsBuilder,
        private OverviewStatsService $statsService,
        private CalendarAccessService $calendarAccess,
    ) {}

    /**
     * @param array{
     *   range: string,
     *   offset: int,
     *   from: \DateTimeImmutable,
     *   to: \DateTimeImmutable,
     *   principal: string,
     *   calendars: array<int, object>,
     *   calendarIds: string[],
     *   includeAll: bool,
     *   selectedIds: string[],
     *   idToName: array<string, string>,
     *   colorsById: array<string, string>,
     *   categoryMeta: array<string, array{id: string, label: string}>,
     *   groupToCategory: array<int, string>,
     *   groupsById: array<string, int>,
     *   targetsConfig: array<string, mixed>,
     *   targetsWeek: array<string, mixed>,
     *   targetsMonth: array<string, mixed>,
     *   userTz: \DateTimeZone,
     *   weekStart: int,
     *   includeCharts: bool,
     *   include: array{stats: bool, byCal: bool, byDay: bool, longest: bool, lookback: bool},
     *   debug: bool,
     *   maxPerCal: int,
     *   maxTotal: int,
     *   analysisNow?: \DateTimeImmutable
     * } $context
     * @return array{
     *   meta: array{truncated: bool, limits: array{maxPerCal: int, maxTotal: int, totalProcessed: int}, currentPeriodClipped: bool, currentCutoff: string|null},
     *   payload: array<string, mixed>,
     *   queryDbg: array<int, mixed>
     * }
     */
    public function build(array $context): array {
        $range = (string)$context['range'];
        $offset = (int)$context['offset'];
        $from = $context['from'];
        $to = $context['to'];
        $principal = (string)$context['principal'];
        $cals = $context['calendars'];
        $calendarIds = $context['calendarIds'];
        $includeAll = (bool)$context['includeAll'];
        $selectedIds = $context['selectedIds'];
        $idToName = $context['idToName'];
        $colorsById = $context['colorsById'];
        $categoryMeta = $context['categoryMeta'];
        $groupToCategory = $context['groupToCategory'];
        $groupsById = $context['groupsById'];
        $targetsConfig = $context['targetsConfig'];
        $targetsWeek = $context['targetsWeek'];
        $targetsMonth = $context['targetsMonth'];
        $userTz = $context['userTz'];
        $weekStart = (int)($context['weekStart'] ?? 1);
        $includeCharts = (bool)$context['includeCharts'];
        $include = $context['include'];
        $includeLookback = !empty($include['lookback']);
        $debug = (bool)$context['debug'];
        $maxPerCal = (int)$context['maxPerCal'];
        $maxTotal = (int)$context['maxTotal'];
        $analysisNow = ($context['analysisNow'] ?? null) instanceof \DateTimeImmutable
            ? $context['analysisNow']->setTimezone($userTz)
            : new \DateTimeImmutable('now', $userTz);

        $mapCalToCategory = function(string $calId) use ($groupsById, $groupToCategory): string {
            $group = isset($groupsById[$calId]) ? (int)$groupsById[$calId] : 0;
            return $groupToCategory[$group] ?? '__uncategorized__';
        };

        $allDayHours = isset($targetsConfig['allDayHours'])
            ? max(0.0, min(24.0, (float)$targetsConfig['allDayHours']))
            : 8.0;
        $calendarPriorities = is_array($targetsConfig['calendarPriorities'] ?? null) ? $targetsConfig['calendarPriorities'] : [];

        // --- Current period collect/aggregate ---
        $collect = $this->eventsCollector->collect(
            principal: $principal,
            cals: $cals,
            includeAll: $includeAll,
            selectedIds: $selectedIds,
            from: $from,
            to: $to,
            maxPerCal: $maxPerCal,
            maxTotal: $maxTotal,
            debug: $debug,
        );
        $events = $collect['events'];
        $truncated = $collect['truncated'];
        $queryDbg = $collect['queryDbg'];
        $totalAdded = count($events);

        $agg = $this->aggregationService->aggregate(
            events: $events,
            from: $from,
            to: $to,
            userTz: $userTz,
            allDayHours: $allDayHours,
            colorsById: $colorsById,
            categoryMeta: $categoryMeta,
            mapCalToCategory: $mapCalToCategory,
            now: $analysisNow,
            calendarPriorities: $calendarPriorities,
        );
        $totalHours = $agg['totalHours'];
        $futureTotalHours = $agg['futureTotalHours'];
        $byCalMap = $agg['byCalMap'];
        $byCalList = $agg['byCalList'];
        $byDay = $agg['byDay'];
        $perDayByCal = $agg['perDayByCal'];
        $dowByCal = $agg['dowByCal'];
        $perDayByCat = $agg['perDayByCat'];
        $dowByCatTotals = $agg['dowByCatTotals'];
        $categoryTotals = $agg['categoryTotals'];
        $categoryColors = $agg['categoryColors'];
        $rangeLabels = $agg['rangeLabels'];
        $eventsCount = $agg['eventsCount'];
        $daysCount = $agg['daysCount'];
        $avgPerDay = $agg['avgPerDay'];
        $avgPerEvent = $agg['avgPerEvent'];
        $daysSeen = $agg['daysSeen'];
        $overlapCount = $agg['overlapCount'];
        $earliestStartTs = $agg['earliestStartTs'];
        $latestEndTs = $agg['latestEndTs'];
        $longestSessionHours = $agg['longestSessionHours'];
        $long = $agg['long'];
        $dowOrder = $agg['dowOrder'];
        $hod = $agg['hod'];
        $dow = $agg['dowTotals'];
        $currentPeriodClipped = (bool)($agg['currentPeriodClipped'] ?? false);
        $currentCutoff = $agg['currentCutoff'] ?? null;
        $analysisTo = $agg['analysisTo'];
        $todayKey = $analysisNow->format('Y-m-d');
        $todayActualHours = isset($byDay[$todayKey]) ? (float)($byDay[$todayKey]['total_hours'] ?? 0.0) : 0.0;
        $todayFutureHours = isset($byDay[$todayKey]) ? (float)($byDay[$todayKey]['future_hours'] ?? 0.0) : 0.0;

        // Build effective calendar order: selected or all (on first run)
        $effectiveIds = $includeAll ? $calendarIds : $selectedIds;
        $seen = [];
        $effectiveIds = array_values(array_filter($effectiveIds, function($id) use (&$seen) {
            if (isset($seen[$id])) return false;
            $seen[$id] = true;
            return true;
        }));

        $chartsPayload = ['charts' => []];
        if ($includeCharts) {
            $chartsPayload = $this->chartsBuilder->build(
                effectiveIds: $effectiveIds,
                idToName: $idToName,
                colorsById: $colorsById,
                byCalMap: $byCalMap,
                byDay: $byDay,
                rangeLabels: $rangeLabels,
                dowOrder: $dowOrder,
                perDayByCal: $perDayByCal,
                dowByCal: $dowByCal,
                hod: $hod,
                dowTotals: $dow,
            );
        }
        $balanceConfig = $targetsConfig['balance'];
        $trendLookback = (int)($balanceConfig['trend']['lookbackWeeks'] ?? 3);
        $currentSummaryMetrics = [
            'totalHours' => $totalHours,
            'eventsCount' => $eventsCount,
            'overlapCount' => $overlapCount,
            'earliestStartTs' => $earliestStartTs,
            'latestEndTs' => $latestEndTs,
            'longestSessionHours' => $longestSessionHours,
        ];
        if ($includeCharts && $includeLookback) {
            $lookbackCharts = $this->buildChartLookback(
                range: $range,
                offset: $offset,
                lookbackPeriods: $trendLookback,
                effectiveIds: $effectiveIds,
                idToName: $idToName,
                colorsById: $colorsById,
                currentRangeLabels: $rangeLabels,
                currentPerDayByCal: $perDayByCal,
                currentHod: $hod,
                currentSummaryMetrics: $currentSummaryMetrics,
                calendars: $cals,
                includeAll: $includeAll,
                selectedIds: $selectedIds,
                principal: $principal,
                mapCalToCategory: $mapCalToCategory,
                userTz: $userTz,
                allDayHours: $allDayHours,
                categoryMeta: $categoryMeta,
                weekStart: $weekStart,
                maxPerCal: $maxPerCal,
                maxTotal: $maxTotal,
                calendarPriorities: $calendarPriorities,
            );
            if ($lookbackCharts !== null) {
                $chartsPayload['charts']['perDaySeriesLookback'] = $lookbackCharts['perDaySeries'] ?? null;
                $chartsPayload['charts']['perDaySeriesByOffset'] = $lookbackCharts['perDaySeriesByOffset'] ?? null;
                $chartsPayload['charts']['hodLookback'] = $lookbackCharts['hod'] ?? null;
                $chartsPayload['charts']['hodByOffset'] = $lookbackCharts['hodByOffset'] ?? null;
                $chartsPayload['charts']['summaryByOffset'] = $lookbackCharts['summaryByOffset'] ?? null;
            }
        }

        $payload = [];
        if ($include['stats']) {
            $payload['stats'] = $this->statsService->build([
                'range' => $range,
                'offset' => $offset,
                'from' => $from,
                'to' => $to,
                'principal' => $principal,
                'calendars' => $cals,
                'includeAll' => $includeAll,
                'selectedIds' => $selectedIds,
                'mapCalToCategory' => $mapCalToCategory,
                'userTz' => $userTz,
                'allDayHours' => $allDayHours,
                'categoryMeta' => $categoryMeta,
                'targetsConfig' => $targetsConfig,
                'targetsWeek' => $targetsWeek,
                'targetsMonth' => $targetsMonth,
                'byCalMap' => $byCalMap,
                'idToName' => $idToName,
                'categoryTotals' => $categoryTotals,
                'categoryColors' => $categoryColors,
                'perDayByCat' => $perDayByCat,
                'totalHours' => $totalHours,
                'futureTotalHours' => $futureTotalHours,
                'byCalList' => $byCalList,
                'byDay' => $byDay,
                'hod' => $hod,
                'dowOrder' => $dowOrder,
                'eventsCount' => $eventsCount,
                'daysCount' => $daysCount,
                'avgPerDay' => $avgPerDay,
                'avgPerEvent' => $avgPerEvent,
                'overlapCount' => $overlapCount,
                'earliestStartTs' => $earliestStartTs,
                'latestEndTs' => $latestEndTs,
                'longestSessionHours' => $longestSessionHours,
                'trendLookback' => $trendLookback,
                'maxPerCal' => $maxPerCal,
                'maxTotal' => $maxTotal,
                'colorsById' => $colorsById,
                'weekStart' => $weekStart,
                'analysisTo' => $analysisTo,
                'currentPeriodClipped' => $currentPeriodClipped,
                'currentCutoff' => $currentCutoff,
                'todayActualHours' => $todayActualHours,
                'todayFutureHours' => $todayFutureHours,
            ]);
        }
        if ($include['byCal']) {
            $payload['byCal'] = $byCalList;
        }
        if ($include['byDay']) {
            $payload['byDay'] = array_values($byDay);
        }
        if ($include['longest']) {
            $payload['longest'] = array_slice($long, 0, 50);
        }
        if ($includeCharts) {
            $payload['charts'] = $chartsPayload['charts'];
        }

        return [
            'meta' => [
                'truncated' => $truncated,
                'limits' => [
                    'maxPerCal' => $maxPerCal,
                    'maxTotal' => $maxTotal,
                    'totalProcessed' => $totalAdded,
                ],
                'currentPeriodClipped' => $currentPeriodClipped,
                'currentCutoff' => $currentCutoff,
            ],
            'payload' => $payload,
            'queryDbg' => $queryDbg,
        ];
    }

    /**
     * Build a combined per-day series + hours-of-day matrix across the configured lookback.
     * Lookback periods include the current range plus (lookbackPeriods - 1) previous windows.
     *
     * @param string[] $effectiveIds
     * @param array<string, string> $idToName
     * @param array<string, string> $colorsById
     * @param string[] $currentRangeLabels
     * @param array<string, array<string, float>> $currentPerDayByCal
     * @param array<string, array<int, float>> $currentHod
     * @param array<string, mixed> $currentSummaryMetrics
     * @param array<int, object> $calendars
     * @param string[] $selectedIds
     * @param array<string, array{id: string, label: string}> $categoryMeta
     * @param int $weekStart
     * @return array{
     *   perDaySeries: array{labels: string[], series: array<int, array{id: string, name: string, color: string, data: float[]}>},
     *   hod: array{dows: string[], hours: int[], matrix: array<int, array<int, float>>},
     *   perDaySeriesByOffset: array<int, array{offset: int, from: string, to: string, labels: string[], series: array<int, array{id: string, name: string, color: string, data: float[]}>}>,
     *   hodByOffset: array<int, array{offset: int, from: string, to: string, dows: string[], hours: int[], matrix: array<int, array<int, float>>}>,
     *   summaryByOffset: array<int, array{offset: int, from: string, to: string, total_hours: float, events: int, overlap_events: int, longest_session: float, earliest_start: string|null, latest_end: string|null}>
     * }|null
     */
    private function buildChartLookback(
        string $range,
        int $offset,
        int $lookbackPeriods,
        array $effectiveIds,
        array $idToName,
        array $colorsById,
        array $currentRangeLabels,
        array $currentPerDayByCal,
        array $currentHod,
        array $currentSummaryMetrics,
        array $calendars,
        bool $includeAll,
        array $selectedIds,
        string $principal,
        callable $mapCalToCategory,
        \DateTimeZone $userTz,
        float $allDayHours,
        array $categoryMeta,
        int $weekStart,
        int $maxPerCal,
        int $maxTotal,
        array $calendarPriorities = [],
    ): ?array {
        $lookbackPeriods = max(1, min(6, $lookbackPeriods));
        if ($lookbackPeriods <= 1) {
            return null;
        }

        $dowOrder = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $hodSum = [];
        foreach ($dowOrder as $dow) {
            $hodSum[$dow] = array_fill(0, 24, 0.0);
        }

        $labels = [];
        $seriesData = [];
        foreach ($effectiveIds as $cid) {
            $seriesData[$cid] = [];
        }

        $appendPeriod = function(array $rangeLabels, array $perDayByCal) use (&$labels, &$seriesData, $effectiveIds): void {
            foreach ($rangeLabels as $dayKey) {
                $labels[] = $dayKey;
                foreach ($effectiveIds as $cid) {
                    $seriesData[$cid][] = round((float)($perDayByCal[$dayKey][$cid] ?? 0.0), 2);
                }
            }
        };

        $addHod = function(array $hod) use (&$hodSum, $dowOrder): void {
            foreach ($dowOrder as $dow) {
                $row = $hod[$dow] ?? [];
                for ($i = 0; $i < 24; $i++) {
                    $hodSum[$dow][$i] += (float)($row[$i] ?? 0.0);
                }
            }
        };

        $buildPerDaySeries = function(array $rangeLabels, array $perDayByCal) use ($effectiveIds, $idToName, $colorsById): array {
            $series = [];
            foreach ($effectiveIds as $cid) {
                $data = [];
                foreach ($rangeLabels as $dayKey) {
                    $data[] = round((float)($perDayByCal[$dayKey][$cid] ?? 0.0), 2);
                }
                $series[] = [
                    'id' => $cid,
                    'name' => $idToName[$cid] ?? $cid,
                    'color' => $colorsById[$cid] ?? '#60a5fa',
                    'data' => $data,
                ];
            }
            return ['labels' => $rangeLabels, 'series' => $series];
        };

        $buildHodMatrix = function(array $hod) use ($dowOrder): array {
            $matrix = [];
            foreach ($dowOrder as $dow) {
                $row = $hod[$dow] ?? [];
                $values = [];
                for ($i = 0; $i < 24; $i++) {
                    $values[] = round((float)($row[$i] ?? 0.0), 2);
                }
                $matrix[] = $values;
            }
            return $matrix;
        };

        $perDaySeriesByOffset = [];
        $hodByOffset = [];
        $summaryByOffset = [];
        $hodHours = range(0, 23);

        $formatTimestamp = function(?int $ts) use ($userTz): ?string {
            if (!$ts) return null;
            return (new \DateTimeImmutable('@' . $ts))->setTimezone($userTz)->format(\DateTimeInterface::ATOM);
        };
        $buildSummaryEntry = function(array $metrics, \DateTimeImmutable $from, \DateTimeImmutable $to, int $offsetStep) use ($formatTimestamp): array {
            return [
                'offset' => $offsetStep,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'total_hours' => round((float)($metrics['totalHours'] ?? 0.0), 2),
                'events' => (int)($metrics['eventsCount'] ?? 0),
                'overlap_events' => (int)($metrics['overlapCount'] ?? 0),
                'longest_session' => round((float)($metrics['longestSessionHours'] ?? 0.0), 2),
                'earliest_start' => $formatTimestamp($metrics['earliestStartTs'] ?? null),
                'latest_end' => $formatTimestamp($metrics['latestEndTs'] ?? null),
            ];
        };

        [$curFrom, $curTo] = $this->calendarAccess->rangeBounds($range, $offset, $userTz, $weekStart);
        $currentPerDaySeries = $buildPerDaySeries($currentRangeLabels, $currentPerDayByCal);
        $perDaySeriesByOffset[] = [
            'offset' => 0,
            'from' => $curFrom->format('Y-m-d'),
            'to' => $curTo->format('Y-m-d'),
            'labels' => $currentPerDaySeries['labels'],
            'series' => $currentPerDaySeries['series'],
        ];
        $hodByOffset[] = [
            'offset' => 0,
            'from' => $curFrom->format('Y-m-d'),
            'to' => $curTo->format('Y-m-d'),
            'dows' => $dowOrder,
            'hours' => $hodHours,
            'matrix' => $buildHodMatrix($currentHod),
        ];
        $summaryByOffset[] = $buildSummaryEntry($currentSummaryMetrics, $curFrom, $curTo, 0);
        $appendPeriod($currentRangeLabels, $currentPerDayByCal);
        $addHod($currentHod);

        // The Time Off Trend exposes `lookbackPeriods` historical entries in
        // addition to the current period. Include each of them in the
        // per-calendar series so local exclusions can rebuild the full trend.
        for ($step = 1; $step <= $lookbackPeriods; $step++) {
            [$lookFrom, $lookTo] = $this->calendarAccess->rangeBounds($range, $offset - $step, $userTz, $weekStart);
            $collect = $this->eventsCollector->collect(
                principal: $principal,
                cals: $calendars,
                includeAll: $includeAll,
                selectedIds: $selectedIds,
                from: $lookFrom,
                to: $lookTo,
                maxPerCal: $maxPerCal,
                maxTotal: $maxTotal,
                debug: false,
            );
            $events = $collect['events'] ?? [];
            $agg = $this->aggregationService->aggregate(
                events: $events,
                from: $lookFrom,
                to: $lookTo,
                userTz: $userTz,
                allDayHours: $allDayHours,
                colorsById: $colorsById,
                categoryMeta: $categoryMeta,
                mapCalToCategory: $mapCalToCategory,
                calendarPriorities: $calendarPriorities,
            );
            $rangeLabels = $agg['rangeLabels'] ?? [];
            $perDayByCal = $agg['perDayByCal'] ?? [];
            $hod = $agg['hod'] ?? [];
            $summaryByOffset[] = $buildSummaryEntry([
                'totalHours' => $agg['totalHours'] ?? 0.0,
                'eventsCount' => $agg['eventsCount'] ?? 0,
                'overlapCount' => $agg['overlapCount'] ?? 0,
                'earliestStartTs' => $agg['earliestStartTs'] ?? null,
                'latestEndTs' => $agg['latestEndTs'] ?? null,
                'longestSessionHours' => $agg['longestSessionHours'] ?? 0.0,
            ], $lookFrom, $lookTo, $step);
            $periodSeries = $buildPerDaySeries($rangeLabels, $perDayByCal);
            $perDaySeriesByOffset[] = [
                'offset' => $step,
                'from' => $lookFrom->format('Y-m-d'),
                'to' => $lookTo->format('Y-m-d'),
                'labels' => $periodSeries['labels'],
                'series' => $periodSeries['series'],
            ];
            $hodByOffset[] = [
                'offset' => $step,
                'from' => $lookFrom->format('Y-m-d'),
                'to' => $lookTo->format('Y-m-d'),
                'dows' => $dowOrder,
                'hours' => $hodHours,
                'matrix' => $buildHodMatrix($hod),
            ];
            $appendPeriod($rangeLabels, $perDayByCal);
            $addHod($hod);
        }

        $series = [];
        foreach ($effectiveIds as $cid) {
            $series[] = [
                'id' => $cid,
                'name' => $idToName[$cid] ?? $cid,
                'color' => $colorsById[$cid] ?? '#60a5fa',
                'data' => $seriesData[$cid] ?? [],
            ];
        }

        $hodMatrix = array_map(
            fn($d) => array_map(fn($v) => round((float)$v, 2), $hodSum[$d] ?? array_fill(0, 24, 0.0)),
            $dowOrder,
        );

        return [
            'perDaySeries' => ['labels' => $labels, 'series' => $series],
            'hod' => ['dows' => $dowOrder, 'hours' => $hodHours, 'matrix' => $hodMatrix],
            'perDaySeriesByOffset' => $perDaySeriesByOffset,
            'hodByOffset' => $hodByOffset,
            'summaryByOffset' => $summaryByOffset,
        ];
    }
}
