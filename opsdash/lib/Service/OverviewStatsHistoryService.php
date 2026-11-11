<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

final class OverviewStatsHistoryService {
    public function __construct(
        private OverviewStatsDeltaService $deltaService,
        private OverviewStatsTrendService $trendService,
    ) {}

    /**
     * @param array{
     *   range: string,
     *   offset: int,
     *   from: \DateTimeImmutable,
     *   to: \DateTimeImmutable,
     *   principal: string,
     *   calendars: array<int, object>,
     *   includeAll: bool,
     *   selectedIds: string[],
     *   mapCalToCategory: callable,
     *   userTz: \DateTimeZone,
     *   allDayHours: float,
     *   categoryMeta: array<string, array{id: string, label: string}>,
     *   targetsConfig: array<string, mixed>,
     *   targetsWeek: array<string, mixed>,
     *   targetsMonth: array<string, mixed>,
     *   idToName: array<string, string>,
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
     *   currentByDay: array<string, array<string, mixed>>,
     *   trendLookback: int,
     *   analysisTo: \DateTimeImmutable
     * } $context
     * @return array<string,mixed>
     */
    public function build(array $context): array {
        $deltaInfo = $this->deltaService->build([
            'range' => $context['range'],
            'offset' => $context['offset'],
            'principal' => $context['principal'],
            'calendars' => $context['calendars'],
            'includeAll' => $context['includeAll'],
            'selectedIds' => $context['selectedIds'],
            'mapCalToCategory' => $context['mapCalToCategory'],
            'userTz' => $context['userTz'],
            'allDayHours' => $context['allDayHours'],
            'categoryMeta' => $context['categoryMeta'],
            'maxPerCal' => $context['maxPerCal'],
            'maxTotal' => $context['maxTotal'],
            'colorsById' => $context['colorsById'],
            'weekStart' => $context['weekStart'],
            'currentTotalHours' => $context['currentTotalHours'],
            'currentAvgPerDay' => $context['currentAvgPerDay'],
            'currentAvgPerEvent' => $context['currentAvgPerEvent'],
            'currentEventsCount' => $context['currentEventsCount'],
            'currentWeekendShare' => $context['currentWeekendShare'],
            'currentEveningShare' => $context['currentEveningShare'],
            'calendarPriorities' => is_array($context['targetsConfig']['calendarPriorities'] ?? null)
                ? $context['targetsConfig']['calendarPriorities']
                : [],
        ]);

        $precomputedDaysWorked = [];
        if (!empty($deltaInfo['prevDaysSeen'])) {
            $precomputedDaysWorked[1] = count($deltaInfo['prevDaysSeen']);
        }

        $trendInfo = $this->trendService->build([
            'range' => $context['range'],
            'offset' => $context['offset'],
            'from' => $context['from'],
            'to' => $context['to'],
            'currentByDay' => $context['currentByDay'],
            'includeAll' => $context['includeAll'],
            'selectedIds' => $context['selectedIds'],
            'calendars' => $context['calendars'],
            'principal' => $context['principal'],
            'userTz' => $context['userTz'],
            'trendLookback' => $context['trendLookback'],
            'weekStart' => $context['weekStart'],
            'precomputedDaysWorked' => $precomputedDaysWorked,
            'mapCalToCategory' => $context['mapCalToCategory'],
            'allDayHours' => $context['allDayHours'],
            'categoryMeta' => $context['categoryMeta'],
            'targetsConfig' => $context['targetsConfig'],
            'targetsWeek' => $context['targetsWeek'],
            'targetsMonth' => $context['targetsMonth'],
            'idToName' => $context['idToName'],
            'analysisTo' => $context['analysisTo'],
        ]);

        return [
            'delta' => $deltaInfo['delta'],
            'categoryTotalsPrev' => $deltaInfo['categoryTotalsPrev'],
            'prevTotal' => $deltaInfo['prevTotal'],
            'prevDaysSeen' => $deltaInfo['prevDaysSeen'],
            'dayOffTrend' => $trendInfo['dayOffTrend'],
            'balanceHistory' => $trendInfo['balanceHistory'],
        ];
    }
}
