<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

/**
 * Resolves overlapping timed events by calendar priority: wherever an event overlaps an event
 * from a higher-priority calendar, that time is removed from the lower-priority event.
 * Events of equal priority keep counting in full, so without configured priorities nothing changes.
 */
final class CalendarPriorityResolver {
    public const MIN_PRIORITY = -10;
    public const MAX_PRIORITY = 10;

    /**
     * @param array<string, mixed> $calendarPriorities
     */
    public static function priorityFor(array $calendarPriorities, string $calId): int {
        $raw = $calendarPriorities[$calId] ?? 0;
        if (!is_numeric($raw)) {
            return 0;
        }
        return max(self::MIN_PRIORITY, min(self::MAX_PRIORITY, (int)$raw));
    }

    /**
     * @param array<int|string, array{start:int, end:int, priority:int}> $spans Timed events keyed by row index (unix seconds).
     * @return array<int|string, array<int, array{0:int, 1:int}>> Remaining [start, end) pieces per row index, ascending.
     */
    public static function resolve(array $spans): array {
        $result = [];
        $byPriority = [];
        foreach ($spans as $key => $span) {
            if ($span['end'] <= $span['start']) {
                $result[$key] = [];
                continue;
            }
            $byPriority[$span['priority']][$key] = $span;
        }
        if (count($byPriority) <= 1) {
            foreach ($byPriority as $level) {
                foreach ($level as $key => $span) {
                    $result[$key] = [[$span['start'], $span['end']]];
                }
            }
            return $result;
        }

        krsort($byPriority);
        $mask = [];
        foreach ($byPriority as $level) {
            foreach ($level as $key => $span) {
                $result[$key] = self::subtract($span['start'], $span['end'], $mask);
            }
            foreach ($level as $span) {
                $mask[] = [$span['start'], $span['end']];
            }
            $mask = self::merge($mask);
        }

        return $result;
    }

    /**
     * @param array<int, array{0:int, 1:int}> $intervals
     * @return array<int, array{0:int, 1:int}>
     */
    private static function merge(array $intervals): array {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as $interval) {
            $last = count($merged) - 1;
            if ($last >= 0 && $interval[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $interval[1]);
            } else {
                $merged[] = $interval;
            }
        }
        return $merged;
    }

    /**
     * @param array<int, array{0:int, 1:int}> $mask Sorted, non-overlapping intervals.
     * @return array<int, array{0:int, 1:int}>
     */
    private static function subtract(int $start, int $end, array $mask): array {
        // Binary search for the first mask interval that ends after $start.
        $lo = 0;
        $hi = count($mask);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($mask[$mid][1] <= $start) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        $pieces = [];
        $cursor = $start;
        for ($i = $lo, $n = count($mask); $i < $n && $mask[$i][0] < $end; $i++) {
            if ($mask[$i][0] > $cursor) {
                $pieces[] = [$cursor, $mask[$i][0]];
            }
            $cursor = max($cursor, $mask[$i][1]);
        }
        if ($cursor < $end) {
            $pieces[] = [$cursor, $end];
        }
        return $pieces;
    }
}
