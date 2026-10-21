<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

use DateInterval;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Parsed rows carry `start`/`end` as wall-clock time plus `startTz`/`endTz` naming the zone of
 * that wall time. An empty zone means floating time (all-day dates, values without any zone),
 * which consumers interpret in the user's own timezone.
 *
 * Internally a date is a "moment": array{dt: \DateTimeImmutable, floating: bool}. Floating
 * moments keep their wall-clock time in a UTC DateTime.
 */
class CalendarParsingService {
    private const MAX_TASK_OCCURRENCES = 1000;

    /**
     * @param array<int,mixed> $raw
     * @return array<int,array<string,mixed>>
     */
    public function parseRows(array $raw, string $calendarName, ?string $calendarId = null): array {
        $out = [];
        $count = 0;
        $maxRows = 5000;
        $maxIcsBytes = 200000;
        $icsParsed = false;
        $icsSkipped = 0;

        foreach ($raw as $row) {
            if (++$count > $maxRows) {
                break;
            }
            $rowAllDay = is_array($row) && array_key_exists('allday', $row) ? (bool)$row['allday'] : false;
            if (is_object($row)) {
                if ($row instanceof \ArrayObject) {
                    $row = $row->getArrayCopy();
                } elseif ($row instanceof \stdClass) {
                    $row = (array)$row;
                } elseif ($row instanceof \JsonSerializable) {
                    $serialized = $row->jsonSerialize();
                    if (is_array($serialized)) {
                        $row = $serialized;
                    }
                } else {
                    $direct = $this->extractEventFromObject($row, $calendarName, $calendarId, $rowAllDay);
                    if ($direct !== null) {
                        $out[] = $direct;
                        continue;
                    }
                    if (method_exists($row, 'toArray')) {
                        $tmp = $row->toArray();
                        if (is_array($tmp)) {
                            $row = $tmp;
                        }
                    }
                }
            }
            if (is_array($row) && isset($row['objects'])) {
                // Tasks are parsed by parseTaskRows(), which needs the requested range.
                if (strtoupper($this->extractTextValue($row['type'] ?? null) ?? '') === 'VTODO') {
                    continue;
                }
                $objects = $row['objects'];
                if ($objects instanceof \ArrayObject) {
                    $objects = $objects->getArrayCopy();
                } elseif ($objects instanceof \stdClass) {
                    $objects = (array)$objects;
                }
                if (is_array($objects)) {
                    foreach ($objects as $payload) {
                        if ($payload instanceof \ArrayObject) {
                            $payload = $payload->getArrayCopy();
                        } elseif ($payload instanceof \stdClass) {
                            $payload = (array)$payload;
                        } elseif ($payload instanceof \JsonSerializable) {
                            $payload = $payload->jsonSerialize();
                        } elseif (is_object($payload) && method_exists($payload, 'toArray')) {
                            try {
                                $payload = $payload->toArray();
                            } catch (\Throwable) {
                                // ignore conversion failure
                            }
                        } elseif (is_object($payload) && method_exists($payload, 'serialize')) {
                            try {
                                $ics = $payload->serialize();
                                if (is_string($ics) && $ics !== '' && strlen($ics) < $maxIcsBytes) {
                                    try {
                                        $parsed = $this->parseIcsEvents($ics, $calendarName, $calendarId, $rowAllDay);
                                        if ($parsed !== []) {
                                            array_push($out, ...$parsed);
                                            $icsParsed = true;
                                        }
                                    } catch (\Throwable) {
                                        $icsSkipped++;
                                    }
                                    continue;
                                }
                            } catch (\Throwable) {
                                // ignore serialization failure
                            }
                        } elseif ($payload instanceof \Traversable) {
                            try {
                                $payload = iterator_to_array($payload);
                            } catch (\Throwable) {
                                // ignore conversion failure
                            }
                        } elseif (is_object($payload) && method_exists($payload, 'getIterator')) {
                            try {
                                $iter = $payload->getIterator();
                                if ($iter instanceof \Traversable) {
                                    $payload = iterator_to_array($iter);
                                }
                            } catch (\Throwable) {
                                // ignore conversion failure
                            }
                        }
                        if (!is_array($payload)) {
                            continue;
                        }
                        if ($this->isFree(
                            $payload['TRANSP'] ?? null,
                            $payload['X-MICROSOFT-CDO-BUSYSTATUS'] ?? null,
                        )) {
                            continue;
                        }
                        if (isset($payload['DTSTART'])) {
                            $start = $this->parseStructuredDate($payload['DTSTART']);
                            $end = isset($payload['DTEND'])
                                ? $this->parseStructuredDate($payload['DTEND'])
                                : null;
                            if (!$end && $start && isset($payload['DURATION'])) {
                                $end = $this->shiftByDuration($start, $this->extractTextValue($payload['DURATION']));
                            }
                            if ($start && $end) {
                                $summary = $this->extractTextValue($payload['SUMMARY'] ?? null) ?? '';
                                $out[] = $this->buildRow($calendarName, $calendarId, $summary, $rowAllDay, $start, $end);
                                continue;
                            }
                        }
                        if (isset($payload['calendardata'])) {
                            $ics = $this->extractTextValue($payload['calendardata']) ?? '';
                            if ($ics !== '' && strlen($ics) < $maxIcsBytes) {
                                try {
                                    $parsed = $this->parseIcsEvents($ics, $calendarName, $calendarId, $rowAllDay);
                                    if ($parsed !== []) {
                                        array_push($out, ...$parsed);
                                        $icsParsed = true;
                                    }
                                } catch (\Throwable) {
                                    $icsSkipped++;
                                }
                                continue;
                            }
                            $icsSkipped++;
                        }

                        $start = $this->extractDateValue($payload['start'] ?? null);
                        $end = $this->extractDateValue($payload['end'] ?? null);
                        if ($start && $end) {
                            $summary = $this->extractTextValue($payload['summary'] ?? null) ?? '';
                            $allDay = $rowAllDay || !empty($payload['allday']);
                            $out[] = $this->buildRow($calendarName, $calendarId, $summary, $allDay, $start, $end);
                        }
                    }
                }
                continue;
            }

            if (!is_array($row)) {
                continue;
            }
            if (array_key_exists('start', $row) && array_key_exists('end', $row)) {
                $start = $this->extractDateValue($row['start']);
                $end = $this->extractDateValue($row['end']);
                if ($start && $end) {
                    $summary = $this->extractTextValue($row['summary'] ?? null) ?? '';
                    $allDay = $rowAllDay || !empty($row['allday']);
                    $out[] = $this->buildRow($calendarName, $calendarId, $summary, $allDay, $start, $end);
                    continue;
                }
            }
            $payload = $row['object'] ?? $row['calendardata'] ?? null;
            if ($payload) {
                $ics = $this->extractTextValue($payload) ?? '';
                if ($ics !== '' && strlen($ics) < $maxIcsBytes) {
                    try {
                        $parsed = $this->parseIcsEvents($ics, $calendarName, $calendarId, $rowAllDay);
                        if ($parsed !== []) {
                            array_push($out, ...$parsed);
                            $icsParsed = true;
                        }
                    } catch (\Throwable) {
                        $icsSkipped++;
                    }
                    continue;
                }
                $icsSkipped++;
            }
        }

        if (!$icsParsed && $icsSkipped > 0) {
            // No parsable ICS rows, return empty set to avoid partial guesses.
            return [];
        }

        return $out;
    }

    /**
     * Parse VTODO search results into calendar-like rows overlapping [$from, $to). A task spans
     * DTSTART → DUE (or DURATION); recurring tasks yield one row per occurrence in the range.
     * Floating task times are read in $from's timezone.
     *
     * @param array<int,mixed> $raw
     * @return array<int,array<string,mixed>>
     */
    public function parseTaskRows(
        array $raw,
        string $calendarName,
        ?string $calendarId,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): array {
        $floatingTz = $from->getTimezone();
        $out = [];
        foreach ($raw as $row) {
            $row = $this->toPlainArray($row);
            if (!is_array($row) || !isset($row['objects'])) {
                continue;
            }
            $objects = $this->toPlainArray($row['objects']);
            if (!is_array($objects)) {
                continue;
            }
            $payloads = array_values(array_filter(
                array_map(fn (mixed $payload): mixed => $this->toPlainArray($payload), $objects),
                'is_array',
            ));

            // Occurrences that were edited individually come as separate objects with a RECURRENCE-ID.
            $overridden = [];
            foreach ($payloads as $payload) {
                $recurrenceId = isset($payload['RECURRENCE-ID'])
                    ? $this->parseStructuredDate($payload['RECURRENCE-ID'])
                    : null;
                if ($recurrenceId !== null) {
                    $overridden[] = $recurrenceId;
                }
            }

            foreach ($payloads as $payload) {
                $task = $this->parseStructuredTask($payload);
                if ($task === null) {
                    continue;
                }
                [$start, $end, $allDay] = $task;
                $occurrences = isset($payload['RRULE']) && !isset($payload['RECURRENCE-ID'])
                    ? $this->expandTask($payload, $start, $end, $overridden, $from, $to, $floatingTz)
                    : [[$start, $end]];
                $summary = $this->extractTextValue($payload['SUMMARY'] ?? null) ?? '';
                foreach ($occurrences as [$occStart, $occEnd]) {
                    if ($this->overlaps($occStart, $occEnd, $from, $to, $floatingTz)) {
                        $out[] = $this->buildRow($calendarName, $calendarId, $summary, $allDay, $occStart, $occEnd);
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0: array{dt: \DateTimeImmutable, floating: bool}, 1: array{dt: \DateTimeImmutable, floating: bool}, 2: bool}|null
     */
    private function parseStructuredTask(array $payload): ?array {
        $status = strtoupper($this->extractTextValue($payload['STATUS'] ?? null) ?? '');
        if ($status === 'CANCELLED') {
            return null;
        }
        $start = isset($payload['DTSTART']) ? $this->parseStructuredDate($payload['DTSTART']) : null;
        $end = isset($payload['DUE']) ? $this->parseStructuredDate($payload['DUE']) : null;
        $duration = isset($payload['DURATION']) ? $this->extractTextValue($payload['DURATION']) : null;
        if ($duration !== null) {
            if ($start && !$end) {
                $end = $this->shiftByDuration($start, $duration);
            } elseif ($end && !$start) {
                $start = $this->shiftByDuration($end, $duration, true);
            }
        }
        if (!$start || !$end || $end['dt'] < $start['dt']) {
            return null;
        }
        $allDay = $this->isDateOnly($payload['DTSTART'] ?? $payload['DUE'] ?? null);
        if ($end['dt'] == $start['dt']) {
            if (!$allDay) {
                return null;
            }
            $end = ['dt' => $start['dt']->modify('+1 day'), 'floating' => $start['floating']];
        }
        return [$start, $end, $allDay];
    }

    /**
     * Expand a recurring task's RRULE into the occurrences that can touch [$from, $to),
     * skipping EXDATEs and occurrences overridden by a RECURRENCE-ID object.
     *
     * @param array<string,mixed> $payload
     * @param array{dt: \DateTimeImmutable, floating: bool} $start
     * @param array{dt: \DateTimeImmutable, floating: bool} $end
     * @param array<int, array{dt: \DateTimeImmutable, floating: bool}> $overridden
     * @return array<int, array{0: array{dt: \DateTimeImmutable, floating: bool}, 1: array{dt: \DateTimeImmutable, floating: bool}}>
     */
    private function expandTask(
        array $payload,
        array $start,
        array $end,
        array $overridden,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        \DateTimeZone $floatingTz,
    ): array {
        $rrule = $this->extractTextValue($payload['RRULE']);
        if ($rrule === null || !class_exists(RRuleIterator::class)) {
            return [[$start, $end]];
        }
        $floating = $start['floating'];
        $span = $start['dt']->diff($end['dt']);
        $windowStart = $this->inFrame($from, $floating, $floatingTz);
        $windowEnd = $this->inFrame($to, $floating, $floatingTz);

        $skip = [];
        foreach ([...$this->parseStructuredDates($payload['EXDATE'] ?? null), ...$overridden] as $excluded) {
            $skip[$this->occurrenceKey($excluded['dt'], $floating)] = true;
        }

        try {
            $iterator = new RRuleIterator($rrule, $start['dt']);
            // Step back a day beyond the span so occurrences that started earlier but run into the range are kept.
            $iterator->fastForward($windowStart->sub($span)->modify('-1 day'));
            $occurrences = [];
            while ($iterator->valid() && count($occurrences) < self::MAX_TASK_OCCURRENCES) {
                $occStart = \DateTimeImmutable::createFromInterface($iterator->current());
                if ($occStart >= $windowEnd) {
                    break;
                }
                if (!isset($skip[$this->occurrenceKey($occStart, $floating)])) {
                    $occurrences[] = [
                        ['dt' => $occStart, 'floating' => $floating],
                        ['dt' => $occStart->add($span), 'floating' => $end['floating']],
                    ];
                }
                $iterator->next();
            }
            return $occurrences;
        } catch (\Throwable) {
            return [[$start, $end]];
        }
    }

    private function occurrenceKey(\DateTimeImmutable $dt, bool $floating): string {
        return $floating ? $dt->format('Y-m-d H:i:s') : (string)$dt->getTimestamp();
    }

    /**
     * Express a range bound in the same frame as a moment: absolute for zoned, wall time for floating.
     */
    private function inFrame(\DateTimeInterface $bound, bool $floating, \DateTimeZone $floatingTz): \DateTimeImmutable {
        $bound = \DateTimeImmutable::createFromInterface($bound);
        if (!$floating) {
            return $bound;
        }
        return new \DateTimeImmutable($bound->setTimezone($floatingTz)->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    /**
     * @param array{dt: \DateTimeImmutable, floating: bool} $start
     * @param array{dt: \DateTimeImmutable, floating: bool} $end
     */
    private function overlaps(array $start, array $end, \DateTimeInterface $from, \DateTimeInterface $to, \DateTimeZone $floatingTz): bool {
        return $this->instant($start, $floatingTz) < $to->getTimestamp()
            && $this->instant($end, $floatingTz) > $from->getTimestamp();
    }

    /**
     * @param array{dt: \DateTimeImmutable, floating: bool} $moment
     */
    private function instant(array $moment, \DateTimeZone $floatingTz): int {
        if (!$moment['floating']) {
            return $moment['dt']->getTimestamp();
        }
        return (new \DateTimeImmutable($moment['dt']->format('Y-m-d H:i:s'), $floatingTz))->getTimestamp();
    }

    /**
     * @param array{dt: \DateTimeImmutable, floating: bool} $start
     * @param array{dt: \DateTimeImmutable, floating: bool} $end
     * @return array<string,mixed>
     */
    private function buildRow(string $calendarName, ?string $calendarId, string $summary, bool $allDay, array $start, array $end): array {
        return [
            'calendar' => $calendarName,
            'calendar_id' => $calendarId,
            'summary' => $summary,
            'title' => $summary,
            'allday' => $allDay,
            'start' => $start['dt']->format('Y-m-d H:i:s'),
            'end' => $end['dt']->format('Y-m-d H:i:s'),
            'startTz' => $start['floating'] ? '' : $start['dt']->getTimezone()->getName(),
            'endTz' => $end['floating'] ? '' : $end['dt']->getTimezone()->getName(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseIcsEvents(string $ics, string $calendarName, ?string $calendarId, bool $rowAllDay): array {
        $rows = [];
        $vobj = Reader::read($ics);
        if (!$vobj || !isset($vobj->VEVENT)) {
            return $rows;
        }
        foreach ($vobj->select('VEVENT') as $vevent) {
            if ($this->isVEventFree($vevent)) {
                continue;
            }
            $start = isset($vevent->DTSTART) ? $this->propertyMoment($vevent->DTSTART) : null;
            $end = $this->resolveVEventEnd($vevent, $start);
            if ($start && $end) {
                $summary = $this->extractVEventSummary($vevent);
                $rows[] = $this->buildRow($calendarName, $calendarId, $summary, $rowAllDay, $start, $end);
            }
        }
        return $rows;
    }

    /**
     * @return array{dt: \DateTimeImmutable, floating: bool}|null
     */
    private function parseStructuredDate(mixed $value): ?array {
        $moment = $this->dateMoment($value);
        if ($moment !== null && $this->isDateOnly($value)) {
            $moment = $this->floating($moment['dt']);
        }
        return $moment;
    }

    /**
     * Multi-valued date properties (EXDATE) arrive as a list of [value, params] pairs.
     *
     * @return array<int, array{dt: \DateTimeImmutable, floating: bool}>
     */
    private function parseStructuredDates(mixed $value): array {
        if (!is_array($value) || $value === []) {
            return [];
        }
        $first = $value[0] ?? null;
        $entries = is_array($first) && array_is_list($first) ? $value : [$value];
        $moments = [];
        foreach ($entries as $entry) {
            $moment = $this->parseStructuredDate($entry);
            if ($moment !== null) {
                $moments[] = $moment;
            }
        }
        return $moments;
    }

    /**
     * @return array{dt: \DateTimeImmutable, floating: bool}|null
     */
    private function dateMoment(mixed $value, int $depth = 0): ?array {
        if ($value instanceof \DateTimeInterface) {
            return $this->zoned($value);
        }
        if (is_string($value)) {
            return $value !== '' ? $this->normalizeDateString($value, null) : null;
        }
        if (is_object($value)) {
            if (method_exists($value, 'getDateTime')) {
                try {
                    $moment = $this->propertyMoment($value);
                    if ($moment !== null) {
                        return $moment;
                    }
                } catch (\Throwable) {}
            }
            if (method_exists($value, 'getValue')) {
                try {
                    $raw = $value->getValue();
                    if (is_string($raw) && $raw !== '') {
                        return $this->normalizeDateString($raw, null);
                    }
                } catch (\Throwable) {}
            }
            if (method_exists($value, '__toString')) {
                try {
                    $raw = (string)$value;
                    if ($raw !== '') {
                        return $this->normalizeDateString($raw, null);
                    }
                } catch (\Throwable) {}
            }
            if (!$value instanceof \JsonSerializable) {
                return null;
            }
            try {
                $value = $value->jsonSerialize();
            } catch (\Throwable) {
                return null;
            }
        }
        if (!is_array($value)) {
            return null;
        }
        if (isset($value['date']) || isset($value['datetime'])) {
            $tzName = isset($value['timezone']) ? (string)$value['timezone'] : '';
            return $this->normalizeDateString((string)($value['date'] ?? $value['datetime']), $tzName !== '' ? $tzName : null);
        }
        if ($depth === 0 && array_key_exists(0, $value)) {
            return $this->dateMoment($value[0], 1);
        }
        return null;
    }

    /**
     * Read a Sabre date property, keeping whether it is floating (date-only or no TZID).
     *
     * @return array{dt: \DateTimeImmutable, floating: bool}|null
     */
    private function propertyMoment(object $property): ?array {
        $dt = $property->getDateTime();
        if (!$dt instanceof \DateTimeInterface) {
            return null;
        }
        $floating = method_exists($property, 'isFloating') && $property->isFloating();
        return $floating ? $this->floating($dt) : $this->zoned($dt);
    }

    /**
     * @return array{dt: \DateTimeImmutable, floating: bool}
     */
    private function zoned(\DateTimeInterface $dt): array {
        return ['dt' => \DateTimeImmutable::createFromInterface($dt), 'floating' => false];
    }

    /**
     * @return array{dt: \DateTimeImmutable, floating: bool}
     */
    private function floating(\DateTimeInterface $dt): array {
        return [
            'dt' => new \DateTimeImmutable($dt->format('Y-m-d H:i:s'), new \DateTimeZone('UTC')),
            'floating' => true,
        ];
    }

    /**
     * @param array{dt: \DateTimeImmutable, floating: bool} $moment
     * @return array{dt: \DateTimeImmutable, floating: bool}|null
     */
    private function shiftByDuration(array $moment, ?string $duration, bool $backwards = false): ?array {
        if ($duration === null) {
            return null;
        }
        try {
            $interval = new DateInterval($duration);
        } catch (\Throwable) {
            return null;
        }
        $dt = $backwards ? $moment['dt']->sub($interval) : $moment['dt']->add($interval);
        return ['dt' => $dt, 'floating' => $moment['floating']];
    }

    private function isDateOnly(mixed $property): bool {
        $params = is_array($property) ? ($property[1] ?? null) : null;
        if (!is_array($params) || !isset($params['VALUE'])) {
            return false;
        }
        return strtoupper($this->extractTextValue($params['VALUE']) ?? '') === 'DATE';
    }

    private function toPlainArray(mixed $value): mixed {
        if ($value instanceof \ArrayObject) {
            return $value->getArrayCopy();
        }
        if ($value instanceof \stdClass) {
            return (array)$value;
        }
        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }
        return $value;
    }

    private function extractEventFromObject(object $row, string $calendarName, ?string $calendarId, bool $rowAllDay): ?array {
        $start = $this->extractDateValue($this->readObjectValue($row, [
            'getStart', 'getStartDate', 'getStartDateTime', 'getDtStart', 'getDTStart',
        ], [
            'start', 'startDate', 'start_date', 'dtstart', 'dtStart',
        ]));
        $end = $this->extractDateValue($this->readObjectValue($row, [
            'getEnd', 'getEndDate', 'getEndDateTime', 'getDtEnd', 'getDTEnd',
        ], [
            'end', 'endDate', 'end_date', 'dtend', 'dtEnd',
        ]));
        if (!$start || !$end) {
            return null;
        }
        $summary = $this->extractTextValue($this->readObjectValue($row, [
            'getSummary', 'getTitle', 'getName',
        ], [
            'summary', 'title', 'name',
        ]) ?? '') ?? '';
        $allDay = $rowAllDay;
        $allDayRaw = $this->readObjectValue($row, [
            'isAllDay', 'isAllDayEvent', 'getAllDay', 'getIsAllDay',
        ], [
            'allday', 'allDay', 'all_day',
        ]);
        if ($allDayRaw !== null) {
            $allDay = (bool)$allDayRaw;
        }
        return $this->buildRow($calendarName, $calendarId, $summary, $allDay, $start, $end);
    }

    private function readObjectValue(object $row, array $methods, array $props): mixed {
        foreach ($methods as $method) {
            if (method_exists($row, $method)) {
                try {
                    return $row->{$method}();
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
        foreach ($props as $prop) {
            if (isset($row->{$prop})) {
                return $row->{$prop};
            }
        }
        return null;
    }

    /**
     * @param array{dt: \DateTimeImmutable, floating: bool}|null $start
     * @return array{dt: \DateTimeImmutable, floating: bool}|null
     */
    private function resolveVEventEnd(object $vevent, ?array $start): ?array {
        $end = isset($vevent->DTEND) ? $this->propertyMoment($vevent->DTEND) : null;
        if ($end || !$start) {
            return $end;
        }

        if (isset($vevent->DURATION)) {
            $end = $this->shiftByDuration($start, $this->extractTextValue($vevent->DURATION->getValue()));
            if ($end !== null) {
                return $end;
            }
        }

        return ['dt' => $start['dt']->modify('+1 hour'), 'floating' => $start['floating']];
    }

    private function extractVEventSummary(object $vevent): string {
        if (!isset($vevent->SUMMARY)) {
            return '';
        }

        return $this->extractTextValue($vevent->SUMMARY->getValue()) ?? '';
    }

    private function isVEventFree(object $vevent): bool {
        return $this->isFree($vevent->TRANSP ?? null, $vevent->{'X-MICROSOFT-CDO-BUSYSTATUS'} ?? null);
    }

    /**
     * Events marked "free" (TRANSP:TRANSPARENT, or Outlook's busy status) don't block time.
     */
    private function isFree(mixed $transp, mixed $busyStatus): bool {
        if (strtoupper(trim($this->extractTextValue($transp) ?? '')) === 'TRANSPARENT') {
            return true;
        }
        return strtoupper(trim($this->extractTextValue($busyStatus) ?? '')) === 'FREE';
    }

    /**
     * @return array{dt: \DateTimeImmutable, floating: bool}|null
     */
    private function extractDateValue(mixed $value): ?array {
        if ($value instanceof \DateTimeInterface) {
            return $this->zoned($value);
        }
        if (is_string($value) && $value !== '') {
            return $this->normalizeDateString($value, null);
        }
        if (is_numeric($value)) {
            try {
                return $this->zoned(new \DateTimeImmutable('@' . (int)$value));
            } catch (\Throwable) {
                return null;
            }
        }
        if (is_array($value)) {
            if (isset($value['date'])) {
                $tzName = isset($value['timezone']) ? (string)$value['timezone'] : null;
                return $this->normalizeDateString((string)$value['date'], $tzName ?: null);
            }
            if (isset($value['datetime'])) {
                $tzName = isset($value['timezone']) ? (string)$value['timezone'] : null;
                return $this->normalizeDateString((string)$value['datetime'], $tzName ?: null);
            }
        }
        return null;
    }

    private function extractTextValue(mixed $value): ?string {
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        if (is_array($value)) {
            if (array_key_exists(0, $value)) {
                return $this->extractTextValue($value[0]);
            }
            if (array_key_exists('value', $value)) {
                return $this->extractTextValue($value['value']);
            }
            return null;
        }
        if (is_object($value)) {
            if (method_exists($value, 'getValue')) {
                try {
                    return $this->extractTextValue($value->getValue());
                } catch (\Throwable) {
                    return null;
                }
            }
            if ($value instanceof \JsonSerializable) {
                try {
                    return $this->extractTextValue($value->jsonSerialize());
                } catch (\Throwable) {
                    return null;
                }
            }
            if (method_exists($value, '__toString')) {
                try {
                    $stringValue = (string)$value;
                    return $stringValue !== '' ? $stringValue : null;
                } catch (\Throwable) {
                    return null;
                }
            }
        }
        return null;
    }

    /**
     * A string without zone information is floating (local) time.
     *
     * @return array{dt: \DateTimeImmutable, floating: bool}|null
     */
    private function normalizeDateString(string $raw, ?string $tzName): ?array {
        try {
            if ($tzName) {
                return $this->zoned(new \DateTimeImmutable($raw, new \DateTimeZone($tzName)));
            }
            $parsed = date_parse($raw);
            if (!empty($parsed['is_localtime'])) {
                return $this->zoned(new \DateTimeImmutable($raw));
            }
            return ['dt' => new \DateTimeImmutable($raw, new \DateTimeZone('UTC')), 'floating' => true];
        } catch (\Throwable) {
            return null;
        }
    }
}
