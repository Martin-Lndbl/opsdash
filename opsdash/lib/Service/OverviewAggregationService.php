<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

final class OverviewAggregationService {
    /**
     * @param array<int, array<string, mixed>> $events Parsed calendar rows.
     * @param array<string, string> $colorsById
     * @param array<string, array{id:string,label:string}> $categoryMeta
     * @param callable(string): string $mapCalToCategory
     * @param array<string, mixed> $calendarPriorities Calendar id => priority; overlapping time is credited to the higher priority only.
     * @return array{
     *   totalHours: float,
     *   futureTotalHours: float,
     *   byCalMap: array<string, array{id:string,calendar:string,events_count:int,total_hours:float,future_hours:float}>,
     *   byCalList: array<int, array{id:string,calendar:string,events_count:int,total_hours:float,future_hours:float}>,
     *   byDay: array<string, array{date:string,events_count:int,total_hours:float,future_hours:float}>,
     *   perDayByCal: array<string, array<string, float>>,
     *   dowByCal: array<string, array<string, float>>,
     *   perDayByCat: array<string, array<string, float>>,
     *   dowByCatTotals: array<string, array<string, float>>,
     *   categoryTotals: array<string, float>,
     *   categoryColors: array<string, string|null>,
     *   rangeLabels: string[],
     *   eventsCount: int,
     *   daysCount: int,
     *   avgPerDay: float,
     *   avgPerEvent: float,
     *   daysSeen: array<string, bool>,
     *   overlapCount: int,
     *   earliestStartTs: int|null,
     *   latestEndTs: int|null,
     *   longestSessionHours: float,
     *   long: array<int, array{calendar:string,summary:string,duration_h:float,start:string,desc:string,allday:bool}>,
     *   dowOrder: string[],
     *   hod: array<string, array<int, float>>,
     *   dowTotals: array<string, float>,
     *   currentPeriodClipped: bool,
     *   currentCutoff: string|null,
     *   analysisTo: \DateTimeImmutable
     * }
     */
    public function aggregate(
        array $events,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $userTz,
        float $allDayHours,
        array $colorsById,
        array $categoryMeta,
        callable $mapCalToCategory,
        ?\DateTimeImmutable $now = null,
        array $calendarPriorities = [],
    ): array {
        $totalHours = 0.0;
        $futureTotalHours = 0.0;
        $byCalMap = [];
        $byDay = [];
        $long = [];
        $daysSeen = [];
        $perDayByCal = [];
        $dowByCal = [];
        $perDayByCat = [];
        $dowByCatTotals = [];

        $categoryTotals = [];
        foreach ($categoryMeta as $catId => $_meta) {
            $categoryTotals[$catId] = 0.0;
        }
        $categoryColors = array_fill_keys(array_keys($categoryMeta), null);

        $dayIntervals = [];
        $overlapCount = 0;
        $earliestStartTs = null;
        $latestEndTs = null;
        $longestSessionHours = 0.0;

        $dowOrder = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $hod = [];
        foreach ($dowOrder as $d) {
            $hod[$d] = array_fill(0, 24, 0.0);
        }

        $analysisNow = ($now ?? new \DateTimeImmutable('now', $userTz))->setTimezone($userTz);
        $currentPeriodClipped = $analysisNow >= $from && $analysisNow <= $to;
        $analysisTo = $currentPeriodClipped ? $analysisNow : (($analysisNow < $from) ? $from : $to);
        $actualWindowStart = $from;
        $actualWindowEnd = $analysisTo;
        $futureWindowStart = $analysisNow > $from ? $analysisNow : $from;
        $futureWindowEnd = $to;

        // First pass: resolve event bounds so calendar priorities can trim overlaps before aggregating.
        $bounds = [];
        $prioritySpans = [];
        foreach ($events as $idx => $r) {
            $isAllDayEvent = !empty($r['allday']);
            [$dtStartUser, $dtEndUser] = $this->eventBoundsInUserTz($r, $isAllDayEvent, $userTz);
            $bounds[$idx] = [$dtStartUser, $dtEndUser];
            if (!$isAllDayEvent && $dtStartUser && $dtEndUser && $dtEndUser > $dtStartUser) {
                $calId = (string)($r['calendar_id'] ?? ($r['calendar'] ?? ''));
                $prioritySpans[$idx] = [
                    'start' => $dtStartUser->getTimestamp(),
                    'end' => $dtEndUser->getTimestamp(),
                    'priority' => CalendarPriorityResolver::priorityFor($calendarPriorities, $calId),
                ];
            }
        }
        $effectiveSpans = CalendarPriorityResolver::resolve($prioritySpans);

        foreach ($events as $idx => $r) {
            $isAllDayEvent = !empty($r['allday']);
            $h = (float)($r['hours'] ?? 0.0);
            $calName = (string)($r['calendar'] ?? '');
            $calId = (string)($r['calendar_id'] ?? $calName);
            $byCalMap[$calId] = $byCalMap[$calId] ?? ['id' => $calId, 'calendar' => $calName, 'events_count' => 0, 'total_hours' => 0.0, 'future_hours' => 0.0];

            $catId = $mapCalToCategory($calId);
            if (!isset($categoryTotals[$catId])) {
                $categoryTotals[$catId] = 0.0;
            }
            if (($categoryColors[$catId] ?? null) === null && isset($colorsById[$calId])) {
                $categoryColors[$catId] = $colorsById[$calId];
            }

            [$dtStartUser, $dtEndUser] = $bounds[$idx];

            $daysSpanned = 1;
            if ($dtStartUser && $dtEndUser) {
                $eventDurSeconds = max(0, $dtEndUser->getTimestamp() - $dtStartUser->getTimestamp());
                if ($isAllDayEvent && $eventDurSeconds <= 0) {
                    $eventDurSeconds = 86400;
                }
                if ($isAllDayEvent) {
                    $daysSpanned = max(1, (int)ceil($eventDurSeconds / 86400));
                }
            }

            $eventHours = $isAllDayEvent ? ($allDayHours * $daysSpanned) : $h;
            if (!$isAllDayEvent && $eventHours <= 0 && $dtStartUser && $dtEndUser && $dtEndUser > $dtStartUser) {
                $eventHours = ($dtEndUser->getTimestamp() - $dtStartUser->getTimestamp()) / 3600.0;
            }
            $eventDurationSeconds = ($dtStartUser && $dtEndUser && $dtEndUser > $dtStartUser)
                ? max(1, $dtEndUser->getTimestamp() - $dtStartUser->getTimestamp())
                : 0;
            $hoursPerSecond = ($eventDurationSeconds > 0 && $eventHours > 0)
                ? ($eventHours / $eventDurationSeconds)
                : 0.0;

            // Pieces of the event left after higher-priority calendars claimed their overlap.
            $pieces = [];
            if ($dtStartUser && $dtEndUser) {
                if (isset($effectiveSpans[$idx])) {
                    foreach ($effectiveSpans[$idx] as [$pieceStartTs, $pieceEndTs]) {
                        $pieces[] = [$dtStartUser->setTimestamp($pieceStartTs), $dtStartUser->setTimestamp($pieceEndTs)];
                    }
                } else {
                    $pieces[] = [$dtStartUser, $dtEndUser];
                }
            }

            $actualHours = 0.0;
            $futureHours = 0.0;
            $actualPieces = [];
            foreach ($pieces as [$pieceStart, $pieceEnd]) {
                $actualStart = $pieceStart > $actualWindowStart ? $pieceStart : $actualWindowStart;
                $actualEnd = $pieceEnd < $actualWindowEnd ? $pieceEnd : $actualWindowEnd;
                if ($actualEnd > $actualStart && $hoursPerSecond > 0) {
                    $actualHours += ($actualEnd->getTimestamp() - $actualStart->getTimestamp()) * $hoursPerSecond;
                    $actualPieces[] = [$actualStart, $actualEnd];
                }

                $futureStart = $pieceStart > $futureWindowStart ? $pieceStart : $futureWindowStart;
                $futureEnd = $pieceEnd < $futureWindowEnd ? $pieceEnd : $futureWindowEnd;
                if ($futureEnd > $futureStart && $hoursPerSecond > 0) {
                    $futureHours += ($futureEnd->getTimestamp() - $futureStart->getTimestamp()) * $hoursPerSecond;
                    $this->addFutureByDay($byDay, $futureStart, $futureEnd, $hoursPerSecond);
                }
            }

            if ($futureHours > 0) {
                $futureTotalHours += $futureHours;
                $byCalMap[$calId]['future_hours'] += $futureHours;
            }

            if ($actualHours <= 0) {
                continue;
            }

            $totalHours += $actualHours;
            $byCalMap[$calId]['events_count']++;
            $byCalMap[$calId]['total_hours'] += $actualHours;
            $categoryTotals[$catId] = ($categoryTotals[$catId] ?? 0.0) + $actualHours;

            $ts = $actualPieces[0][0]->getTimestamp();
            if ($earliestStartTs === null || $ts < $earliestStartTs) {
                $earliestStartTs = $ts;
            }
            $te = $actualPieces[count($actualPieces) - 1][1]->getTimestamp();
            if ($latestEndTs === null || $te > $latestEndTs) {
                $latestEndTs = $te;
            }
            if ($actualHours > $longestSessionHours) {
                $longestSessionHours = $actualHours;
            }

            $dayStart = $actualPieces[0][0]->format('Y-m-d');
            $byDay[$dayStart] = $byDay[$dayStart] ?? ['date' => $dayStart, 'events_count' => 0, 'total_hours' => 0.0, 'future_hours' => 0.0];
            $byDay[$dayStart]['events_count']++;
            $daysSeen[$dayStart] = true;

            foreach ($actualPieces as [$actualStart, $actualEnd]) {
                $segmentStart = $actualStart;
                while ($segmentStart < $actualEnd) {
                    $dayKey = $segmentStart->format('Y-m-d');
                    $dayEndCandidate = $segmentStart->setTime(23, 59, 59)->modify('+1 second');
                    if ($dayEndCandidate > $actualEnd) {
                        $dayEndCandidate = $actualEnd;
                    }
                    $startTs = $segmentStart->getTimestamp();
                    $endTs = $dayEndCandidate->getTimestamp();
                    if ($endTs > $startTs) {
                        $dayIntervals[$dayKey] = $dayIntervals[$dayKey] ?? [];
                        foreach ($dayIntervals[$dayKey] as $interval) {
                            if ($startTs < $interval[1] && $endTs > $interval[0]) {
                                $overlapCount++;
                                break;
                            }
                        }
                        $dayIntervals[$dayKey][] = [$startTs, $endTs];
                    }
                    $segmentStart = $dayEndCandidate;
                }

                $this->distributeActualSegment(
                    byDay: $byDay,
                    perDayByCal: $perDayByCal,
                    dowByCal: $dowByCal,
                    perDayByCat: $perDayByCat,
                    dowByCatTotals: $dowByCatTotals,
                    hod: $hod,
                    daysSeen: $daysSeen,
                    segmentStart: $actualStart,
                    segmentEnd: $actualEnd,
                    hoursPerSecond: $hoursPerSecond,
                    calId: $calId,
                    catId: $catId,
                    userTz: $userTz,
                );
            }

            $long[] = [
                'calendar' => $calName,
                'summary' => (string)($r['title'] ?? ''),
                'duration_h' => $actualHours,
                'start' => (string)($r['start'] ?? ''),
                'desc' => (string)($r['desc'] ?? ''),
                'allday' => $isAllDayEvent,
            ];
        }

        $dowTotals = [];
        foreach ($dowOrder as $d) {
            $dowTotals[$d] = array_sum($hod[$d]);
        }

        usort($long, fn ($a, $b) => $b['duration_h'] <=> $a['duration_h']);

        $byCalList = array_values($byCalMap);
        usort($byCalList, fn ($a, $b) => $b['total_hours'] <=> $a['total_hours']);

        ksort($byDay);

        $rangeLabels = [];
        $cursor = clone $from;
        while ($cursor <= $to) {
            $dayKey = $cursor->format('Y-m-d');
            if (!isset($byDay[$dayKey])) {
                $byDay[$dayKey] = ['date' => $dayKey, 'events_count' => 0, 'total_hours' => 0.0, 'future_hours' => 0.0];
            }
            if (!isset($perDayByCal[$dayKey])) {
                $perDayByCal[$dayKey] = [];
            }
            if (!isset($perDayByCat[$dayKey])) {
                $perDayByCat[$dayKey] = [];
            }
            $rangeLabels[] = $dayKey;
            $cursor = $cursor->add(new \DateInterval('P1D'));
        }

        $eventsCount = array_sum(array_map(static fn (array $row): int => (int)($row['events_count'] ?? 0), $byCalList));
        $daysCount = count($daysSeen);
        $avgPerDay = $daysCount ? ($totalHours / $daysCount) : 0.0;
        $avgPerEvent = $eventsCount ? ($totalHours / $eventsCount) : 0.0;

        return [
            'totalHours' => $totalHours,
            'futureTotalHours' => $futureTotalHours,
            'byCalMap' => $byCalMap,
            'byCalList' => $byCalList,
            'byDay' => $byDay,
            'perDayByCal' => $perDayByCal,
            'dowByCal' => $dowByCal,
            'perDayByCat' => $perDayByCat,
            'dowByCatTotals' => $dowByCatTotals,
            'categoryTotals' => $categoryTotals,
            'categoryColors' => $categoryColors,
            'rangeLabels' => $rangeLabels,
            'eventsCount' => $eventsCount,
            'daysCount' => $daysCount,
            'avgPerDay' => $avgPerDay,
            'avgPerEvent' => $avgPerEvent,
            'daysSeen' => $daysSeen,
            'overlapCount' => $overlapCount,
            'earliestStartTs' => $earliestStartTs,
            'latestEndTs' => $latestEndTs,
            'longestSessionHours' => $longestSessionHours,
            'long' => $long,
            'dowOrder' => $dowOrder,
            'hod' => $hod,
            'dowTotals' => $dowTotals,
            'currentPeriodClipped' => $currentPeriodClipped,
            'currentCutoff' => $currentPeriodClipped ? $analysisNow->format(\DateTimeInterface::ATOM) : null,
            'analysisTo' => $analysisTo,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array{0: \DateTimeImmutable|null, 1: \DateTimeImmutable|null}
     */
    private function eventBoundsInUserTz(array $r, bool $isAllDayEvent, \DateTimeZone $userTz): array {
        $stStr = (string)($r['start'] ?? '');
        $enStr = (string)($r['end'] ?? '');
        $stTzName = (string)($r['startTz'] ?? '');
        $enTzName = (string)($r['endTz'] ?? '');
        try {
            $srcTzStart = $stTzName !== '' ? new \DateTimeZone($stTzName) : $userTz;
        } catch (\Throwable) {
            $srcTzStart = new \DateTimeZone('UTC');
        }
        try {
            $srcTzEnd = $enTzName !== '' ? new \DateTimeZone($enTzName) : $userTz;
        } catch (\Throwable) {
            $srcTzEnd = new \DateTimeZone('UTC');
        }
        $dtStart = $stStr !== '' ? new \DateTimeImmutable($stStr, $srcTzStart) : null;
        $dtEnd = $enStr !== '' ? new \DateTimeImmutable($enStr, $srcTzEnd) : null;
        $dtStartUser = $dtStart?->setTimezone($userTz);
        $dtEndUser = $dtEnd?->setTimezone($userTz);

        if ($isAllDayEvent && $dtStartUser) {
            $dtStartUser = $dtStartUser->setTime(0, 0, 0);
        }
        if ($isAllDayEvent) {
            if ($dtEndUser) {
                $dtEndUser = $dtEndUser->setTime(0, 0, 0);
            }
            if (!$dtEndUser && $dtStartUser) {
                $dtEndUser = $dtStartUser->modify('+1 day');
            }
        }

        return [$dtStartUser, $dtEndUser];
    }

    /**
     * @param array<string, array{date:string,events_count:int,total_hours:float,future_hours:float}> $byDay
     */
    private function addFutureByDay(
        array &$byDay,
        ?\DateTimeImmutable $segmentStart,
        ?\DateTimeImmutable $segmentEnd,
        float $hoursPerSecond,
    ): void {
        if ($segmentStart === null || $segmentEnd === null || $segmentEnd <= $segmentStart || $hoursPerSecond <= 0) {
            return;
        }

        $cursor = $segmentStart;
        while ($cursor < $segmentEnd) {
            $dayKey = $cursor->format('Y-m-d');
            $dayEnd = $cursor->setTime(23, 59, 59)->modify('+1 second');
            if ($dayEnd > $segmentEnd) {
                $dayEnd = $segmentEnd;
            }
            $seconds = max(0, $dayEnd->getTimestamp() - $cursor->getTimestamp());
            if ($seconds > 0) {
                $byDay[$dayKey] = $byDay[$dayKey] ?? ['date' => $dayKey, 'events_count' => 0, 'total_hours' => 0.0, 'future_hours' => 0.0];
                $byDay[$dayKey]['future_hours'] += $seconds * $hoursPerSecond;
            }
            $cursor = $dayEnd;
        }
    }

    /**
     * @param array<string, array{date:string,events_count:int,total_hours:float,future_hours:float}> $byDay
     * @param array<string, array<string, float>> $perDayByCal
     * @param array<string, array<string, float>> $dowByCal
     * @param array<string, array<string, float>> $perDayByCat
     * @param array<string, array<string, float>> $dowByCatTotals
     * @param array<string, array<int, float>> $hod
     * @param array<string, bool> $daysSeen
     */
    private function distributeActualSegment(
        array &$byDay,
        array &$perDayByCal,
        array &$dowByCal,
        array &$perDayByCat,
        array &$dowByCatTotals,
        array &$hod,
        array &$daysSeen,
        \DateTimeImmutable $segmentStart,
        \DateTimeImmutable $segmentEnd,
        float $hoursPerSecond,
        string $calId,
        string $catId,
        \DateTimeZone $userTz,
    ): void {
        $cursor = $segmentStart;
        while ($cursor < $segmentEnd) {
            $hourStart = \DateTimeImmutable::createFromFormat('Y-m-d H:00:00', $cursor->format('Y-m-d H:00:00'), $userTz) ?: $cursor;
            $slotEnd = $hourStart->modify('+1 hour');
            if ($slotEnd > $segmentEnd) {
                $slotEnd = $segmentEnd;
            }
            $seconds = max(0, $slotEnd->getTimestamp() - $cursor->getTimestamp());
            if ($seconds > 0) {
                $hours = $seconds * $hoursPerSecond;
                $dayKey = $cursor->format('Y-m-d');
                $dname = $cursor->format('D');
                $hour = (int)$cursor->format('G');

                $byDay[$dayKey] = $byDay[$dayKey] ?? ['date' => $dayKey, 'events_count' => 0, 'total_hours' => 0.0, 'future_hours' => 0.0];
                $byDay[$dayKey]['total_hours'] += $hours;
                $daysSeen[$dayKey] = true;

                $perDayByCal[$dayKey] = $perDayByCal[$dayKey] ?? [];
                $perDayByCal[$dayKey][$calId] = ($perDayByCal[$dayKey][$calId] ?? 0.0) + $hours;

                $dowByCal[$dname] = $dowByCal[$dname] ?? [];
                $dowByCal[$dname][$calId] = ($dowByCal[$dname][$calId] ?? 0.0) + $hours;

                $perDayByCat[$dayKey] = $perDayByCat[$dayKey] ?? [];
                $perDayByCat[$dayKey][$catId] = ($perDayByCat[$dayKey][$catId] ?? 0.0) + $hours;

                $dowByCatTotals[$dname] = $dowByCatTotals[$dname] ?? [];
                $dowByCatTotals[$dname][$catId] = ($dowByCatTotals[$dname][$catId] ?? 0.0) + $hours;

                if (isset($hod[$dname][$hour])) {
                    $hod[$dname][$hour] += $hours;
                }
            }
            $cursor = $slotEnd;
        }
    }
}
