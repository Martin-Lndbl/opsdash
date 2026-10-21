<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

use DateTimeInterface;
use OCP\Calendar\IManager;
use Psr\Log\LoggerInterface;

final class OverviewEventsCollector {
    private const APP_NAME = 'opsdash';
    private const COLLECT_CACHE_LIMIT = 24;

    /**
     * Request-local cache for identical collect calls.
     *
     * @var array<string, array{events: array<int, array<string, mixed>>, truncated: bool, queryDbg: array<int, array<string, mixed>>}>
     */
    private array $collectCache = [];

    /** @var string[] */
    private array $collectCacheOrder = [];

    public function __construct(
        private IManager $calendarManager,
        private CalendarParsingService $calendarParsing,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Collect parsed calendar rows for a time window.
     *
     * @param string $principal
     * @param array<int, object> $cals Calendar-like objects with getUri()/getDisplayName()
     * @param bool $includeAll
     * @param string[] $selectedIds
     * @param DateTimeInterface $from
     * @param DateTimeInterface $to
     * @param int $maxPerCal
     * @param int $maxTotal
     * @param bool $debug
     * @return array{events: array<int, array<string, mixed>>, truncated: bool, queryDbg: array<int, array<string, mixed>>}
     */
    public function collect(
        string $principal,
        array $cals,
        bool $includeAll,
        array $selectedIds,
        DateTimeInterface $from,
        DateTimeInterface $to,
        int $maxPerCal,
        int $maxTotal,
        bool $debug,
    ): array {
        $cacheKey = $this->buildCollectCacheKey(
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
        if (isset($this->collectCache[$cacheKey])) {
            return $this->collectCache[$cacheKey];
        }

        $events = [];
        $queryDbg = [];
        $totalAdded = 0;
        $truncated = false;

        foreach ($cals as $cal) {
            $cid = '';
            try {
                $cid = (string)($cal->getUri() ?? '');
            } catch (\Throwable) {
                $cid = '';
            }
            if ($cid === '') {
                $cid = (string)spl_object_id($cal);
            }
            if (!$includeAll && !in_array($cid, $selectedIds, true)) {
                continue;
            }

            $calName = 'calendar';
            try {
                $calName = (string)($cal->getDisplayName() ?: ($cal->getUri() ?? 'calendar'));
            } catch (\Throwable) {
                $calName = $cid;
            }

            $rawRows = [];
            $used = 'none';
            try {
                if (method_exists($this->calendarManager, 'newQuery') && method_exists($this->calendarManager, 'searchForPrincipal')) {
                    $q = $this->buildQuery($principal);
                    $calendarKey = (string)($cal->getUri() ?? $cid);
                    if (method_exists($q, 'addSearchCalendar')) {
                        $q->addSearchCalendar($calendarKey);
                    } elseif (method_exists($q, 'addCalendar')) {
                        $q->addCalendar($calendarKey);
                    }
                    if (method_exists($q, 'setTimerangeStart')) {
                        $q->setTimerangeStart($from);
                    } elseif (method_exists($q, 'setTimeRangeStart')) {
                        $q->setTimeRangeStart($from);
                    }
                    if (method_exists($q, 'setTimerangeEnd')) {
                        $q->setTimerangeEnd($to);
                    } elseif (method_exists($q, 'setTimeRangeEnd')) {
                        $q->setTimeRangeEnd($to);
                    }
                    $res = $this->runSearchForPrincipal($principal, $q);
                    if (is_array($res)) {
                        $rawRows = $res;
                    }
                    $used = 'IManager::searchForPrincipal';
                }
            } catch (\Throwable $e) {
                $this->logger->error('calendar query failed: ' . $e->getMessage(), [
                    'app' => self::APP_NAME,
                ]);
            }

            $mode = 'empty';
            $first = $rawRows[0] ?? null;
            if (is_array($first) && isset($first['objects'])) {
                $mode = 'structured';
            } elseif (is_array($first) && (isset($first['calendardata']) || isset($first['object']))) {
                $mode = 'ics';
            }

            $rows = $this->calendarParsing->parseRows($rawRows, $calName, $cid);

            // Tasks (VTODO) are treated like calendar entries spanning start → due.
            $taskRawRows = $this->searchTasks($principal, (string)($cal->getUri() ?? $cid));
            $taskRows = [];
            if ($taskRawRows !== []) {
                $taskRows = $this->calendarParsing->parseTaskRows($taskRawRows, $calName, $cid, $from, $to);
                $rows = array_merge($rows, $taskRows);
            }

            if (count($rows) > $maxPerCal) {
                $rows = array_slice($rows, 0, $maxPerCal);
                $truncated = true;
            }

            $remaining = $maxTotal - $totalAdded;
            if ($remaining <= 0) {
                $truncated = true;
                break;
            }
            if (count($rows) > $remaining) {
                $rows = array_slice($rows, 0, $remaining);
                $truncated = true;
            }

            $events = array_merge($events, $rows);
            $totalAdded += count($rows);

            if ($debug) {
                $queryDbg[] = [
                    'calendar_id' => $cid,
                    'method' => $used,
                    'mode' => $mode,
                    'rows' => is_array($rawRows) ? count($rawRows) : 0,
                    'task_rows' => count($taskRawRows),
                    'task_rows_in_range' => count($taskRows),
                    'sample_raw' => isset($rawRows[0]) ? $rawRows[0] : null,
                    'sample_parsed' => isset($rows[0]) ? $rows[0] : null,
                    'sample_detect_allday' => (is_array($rawRows[0] ?? null)) ? self::detectAllDayFromRawRow($rawRows[0]) : null,
                ];
            }
        }

        $result = [
            'events' => $events,
            'truncated' => $truncated,
            'queryDbg' => $queryDbg,
        ];
        $this->writeCollectCache($cacheKey, $result);
        return $result;
    }

    /**
     * Best-effort extraction of an all-day signal for debug output.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function detectAllDayFromRawRow(array $row): array {
        $flag = isset($row['allday']) ? (bool)$row['allday'] : false;
        $reason = $flag ? 'row.allday' : null;

        $obj = null;
        if (isset($row['objects'])) {
            $objects = $row['objects'];
            if ($objects instanceof \ArrayObject) {
                $objects = $objects->getArrayCopy();
            }
            if (is_array($objects) && isset($objects[0])) {
                $obj = $objects[0];
                if ($obj instanceof \ArrayObject) {
                    $obj = $obj->getArrayCopy();
                }
                if ($obj instanceof \stdClass) {
                    $obj = (array)$obj;
                }
            }
        }

        $details = [
            'objects_type' => isset($row['objects']) ? gettype($row['objects']) : null,
        ];
        if (is_array($obj)) {
            if (!$flag && isset($obj['allday'])) {
                $raw = $obj['allday'];
                if ($raw instanceof \stdClass) {
                    $raw = (array)$raw;
                }
                $flag = is_array($raw) ? (bool)($raw[0] ?? false) : (bool)$raw;
                if ($flag) {
                    $reason = 'object.allday';
                }
            }
            if (!$flag && isset($obj['DTSTART'][1])) {
                $params = $obj['DTSTART'][1];
                $details['dtstart_type'] = gettype($params);
                if ($params instanceof \stdClass) {
                    $params = (array)$params;
                }
                $details['params'] = $params;
                $details['has_value_param'] = is_array($params) && array_key_exists('VALUE', $params);
                if (is_array($params) && array_key_exists('VALUE', $params)) {
                    $valueType = $params['VALUE'];
                    $details['value_type'] = $valueType;
                    if (is_array($valueType)) {
                        $valueType = reset($valueType);
                    }
                    if (is_object($valueType) && method_exists($valueType, '__toString')) {
                        $valueType = (string)$valueType;
                    }
                    $details['value_type_upper'] = is_string($valueType) ? strtoupper($valueType) : null;
                    if (is_string($valueType) && strtoupper($valueType) === 'DATE') {
                        $flag = true;
                        $reason = 'VALUE=DATE';
                    }
                }
            }
        }

        $details['allday'] = $flag;
        $details['reason'] = $reason;
        return $details;
    }

    /**
     * Fetch a calendar's tasks. The CalDAV backend only indexes occurrence bounds for VEVENTs,
     * so time-ranged searches never return VTODOs; query them unbounded and filter afterwards.
     *
     * @return array<int, mixed>
     */
    private function searchTasks(string $principal, string $calendarKey): array {
        try {
            if (!method_exists($this->calendarManager, 'newQuery') || !method_exists($this->calendarManager, 'searchForPrincipal')) {
                return [];
            }
            $q = $this->buildQuery($principal);
            if (!method_exists($q, 'addType')) {
                return [];
            }
            if (method_exists($q, 'addSearchCalendar')) {
                $q->addSearchCalendar($calendarKey);
            } elseif (method_exists($q, 'addCalendar')) {
                $q->addCalendar($calendarKey);
            } else {
                return [];
            }
            $q->addType('VTODO');
            return $this->runSearchForPrincipal($principal, $q);
        } catch (\Throwable $e) {
            $this->logger->error('task query failed: ' . $e->getMessage(), [
                'app' => self::APP_NAME,
            ]);
        }
        return [];
    }

    private function buildQuery(string $principal): object {
        $ref = new \ReflectionMethod($this->calendarManager, 'newQuery');
        if ($ref->getNumberOfParameters() >= 1) {
            return $this->calendarManager->newQuery($principal);
        }
        $q = $this->calendarManager->newQuery();
        if (method_exists($q, 'setPrincipal')) {
            $q->setPrincipal($principal);
        }
        if (method_exists($q, 'setUserId')) {
            $uid = self::principalToUserId($principal);
            if ($uid !== '') {
                $q->setUserId($uid);
            }
        }
        return $q;
    }

    private function runSearchForPrincipal(string $principal, object $query): array {
        $ref = new \ReflectionMethod($this->calendarManager, 'searchForPrincipal');
        $search = function(string $principalValue) use ($ref, $query): array {
            if ($ref->getNumberOfParameters() >= 2) {
                return $this->calendarManager->searchForPrincipal($principalValue, $query);
            }
            return $this->calendarManager->searchForPrincipal($query);
        };
        $result = $search($principal);
        if (!empty($result)) {
            return $result;
        }
        $alternate = str_ends_with($principal, '/')
            ? rtrim($principal, '/')
            : ($principal . '/');
        if ($alternate !== $principal) {
            $fallback = $search($alternate);
            if (!empty($fallback)) {
                return $fallback;
            }
        }
        return $result;
    }

    private static function principalToUserId(string $principal): string {
        $prefix = 'principals/users/';
        if (str_starts_with($principal, $prefix)) {
            return trim(substr($principal, strlen($prefix)), '/');
        }
        return trim($principal, '/');
    }

    /**
     * @param array<int, object> $cals
     * @param string[] $selectedIds
     */
    private function buildCollectCacheKey(
        string $principal,
        array $cals,
        bool $includeAll,
        array $selectedIds,
        DateTimeInterface $from,
        DateTimeInterface $to,
        int $maxPerCal,
        int $maxTotal,
        bool $debug,
    ): string {
        $calendarIds = [];
        foreach ($cals as $cal) {
            $cid = '';
            try {
                $cid = (string)($cal->getUri() ?? '');
            } catch (\Throwable) {
                $cid = '';
            }
            if ($cid === '') {
                $cid = (string)spl_object_id($cal);
            }
            $calendarIds[] = $cid;
        }
        sort($calendarIds, SORT_STRING);

        $selected = $selectedIds;
        sort($selected, SORT_STRING);

        return implode('|', [
            $principal,
            $includeAll ? '1' : '0',
            implode(',', $calendarIds),
            implode(',', $selected),
            (string)$from->getTimestamp(),
            (string)$to->getTimestamp(),
            (string)$maxPerCal,
            (string)$maxTotal,
            $debug ? '1' : '0',
        ]);
    }

    /**
     * @param array{events: array<int, array<string, mixed>>, truncated: bool, queryDbg: array<int, array<string, mixed>>} $result
     */
    private function writeCollectCache(string $cacheKey, array $result): void {
        if (isset($this->collectCache[$cacheKey])) {
            $this->collectCache[$cacheKey] = $result;
            return;
        }

        $this->collectCache[$cacheKey] = $result;
        $this->collectCacheOrder[] = $cacheKey;
        if (count($this->collectCacheOrder) <= self::COLLECT_CACHE_LIMIT) {
            return;
        }

        $evict = array_shift($this->collectCacheOrder);
        if ($evict !== null) {
            unset($this->collectCache[$evict]);
        }
    }
}
