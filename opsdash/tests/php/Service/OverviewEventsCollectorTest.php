<?php

declare(strict_types=1);

namespace OCA\Opsdash\Tests\Service;

use DateTimeImmutable;
use OCA\Opsdash\Service\CalendarParsingService;
use OCA\Opsdash\Service\OverviewEventsCollector;
use OCP\Calendar\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OverviewEventsCollectorTest extends TestCase {
  public function testCollectSkipsUnselectedCalendars(): void {
    $manager = new class() implements IManager {
      public array $responses = [];
      public function newQuery(string $principal): object {
        return new class() {
          public string $calendar = '';
          public function addSearchCalendar(string $calendar): void { $this->calendar = $calendar; }
          public function setTimerangeStart($from): void {}
          public function setTimerangeEnd($to): void {}
        };
      }
      public function searchForPrincipal(object $q): array {
        return $this->responses[$q->calendar] ?? [];
      }
    };

    $manager->responses = [
      'cal-a' => [['id' => 1]],
      'cal-b' => [['id' => 2]],
    ];

    $calendarService = new class() extends CalendarParsingService {
      public function parseRows(array $raw, string $calendarName, ?string $calendarId = null): array {
        return array_map(
          fn ($row) => ['calendar' => $calendarName, 'calendar_id' => $calendarId, 'hours' => 1, 'allday' => false],
          $raw,
        );
      }
    };

    $logger = $this->createMock(LoggerInterface::class);
    $collector = new OverviewEventsCollector($manager, $calendarService, $logger);

    $calA = new class() {
      public function getUri(): string { return 'cal-a'; }
      public function getDisplayName(): string { return 'A'; }
    };
    $calB = new class() {
      public function getUri(): string { return 'cal-b'; }
      public function getDisplayName(): string { return 'B'; }
    };

    $res = $collector->collect(
      principal: 'principals/users/admin',
      cals: [$calA, $calB],
      includeAll: false,
      selectedIds: ['cal-a'],
      from: new DateTimeImmutable('2025-01-01T00:00:00Z'),
      to: new DateTimeImmutable('2025-01-08T00:00:00Z'),
      maxPerCal: 50,
      maxTotal: 50,
      debug: true,
    );

    $this->assertCount(1, $res['events']);
    $this->assertSame('cal-a', $res['events'][0]['calendar_id']);
    $this->assertFalse($res['truncated']);
    $this->assertCount(1, $res['queryDbg']);
    $this->assertSame('cal-a', $res['queryDbg'][0]['calendar_id']);
  }

  public function testCollectAppliesCapsAndReportsTruncation(): void {
    $manager = new class() implements IManager {
      public array $responses = [];
      public function newQuery(string $principal): object {
        return new class() {
          public string $calendar = '';
          public function addSearchCalendar(string $calendar): void { $this->calendar = $calendar; }
          public function setTimerangeStart($from): void {}
          public function setTimerangeEnd($to): void {}
        };
      }
      public function searchForPrincipal(object $q): array {
        return $this->responses[$q->calendar] ?? [];
      }
    };

    $manager->responses = [
      'cal-a' => array_fill(0, 10, ['id' => 1]),
      'cal-b' => array_fill(0, 10, ['id' => 2]),
    ];

    $calendarService = new class() extends CalendarParsingService {
      public function parseRows(array $raw, string $calendarName, ?string $calendarId = null): array {
        return array_map(
          fn ($row) => ['calendar' => $calendarName, 'calendar_id' => $calendarId, 'hours' => 1, 'allday' => false],
          $raw,
        );
      }
    };

    $logger = $this->createMock(LoggerInterface::class);
    $collector = new OverviewEventsCollector($manager, $calendarService, $logger);

    $calA = new class() {
      public function getUri(): string { return 'cal-a'; }
      public function getDisplayName(): string { return 'A'; }
    };
    $calB = new class() {
      public function getUri(): string { return 'cal-b'; }
      public function getDisplayName(): string { return 'B'; }
    };

    $res = $collector->collect(
      principal: 'principals/users/admin',
      cals: [$calA, $calB],
      includeAll: true,
      selectedIds: [],
      from: new DateTimeImmutable('2025-01-01T00:00:00Z'),
      to: new DateTimeImmutable('2025-01-08T00:00:00Z'),
      maxPerCal: 3,
      maxTotal: 5,
      debug: false,
    );

    $this->assertTrue($res['truncated']);
    $this->assertCount(5, $res['events']);
    $this->assertSame('cal-a', $res['events'][0]['calendar_id']);
    $this->assertSame('cal-b', $res['events'][4]['calendar_id']);
  }

  public function testCollectCachesIdenticalRequestsWithinInstance(): void {
    $manager = new class() implements IManager {
      public int $calls = 0;
      public array $responses = [];
      public function newQuery(string $principal): object {
        return new class() {
          public string $calendar = '';
          public function addSearchCalendar(string $calendar): void { $this->calendar = $calendar; }
          public function setTimerangeStart($from): void {}
          public function setTimerangeEnd($to): void {}
        };
      }
      public function searchForPrincipal(object $q): array {
        $this->calls++;
        return $this->responses[$q->calendar] ?? [];
      }
    };

    $manager->responses = [
      'cal-a' => [['id' => 1], ['id' => 2]],
      'cal-b' => [['id' => 3]],
    ];

    $calendarService = new class() extends CalendarParsingService {
      public function parseRows(array $raw, string $calendarName, ?string $calendarId = null): array {
        return array_map(
          fn ($row) => ['calendar' => $calendarName, 'calendar_id' => $calendarId, 'hours' => 1, 'allday' => false],
          $raw,
        );
      }
    };

    $logger = $this->createMock(LoggerInterface::class);
    $collector = new OverviewEventsCollector($manager, $calendarService, $logger);

    $calA = new class() {
      public function getUri(): string { return 'cal-a'; }
      public function getDisplayName(): string { return 'A'; }
    };
    $calB = new class() {
      public function getUri(): string { return 'cal-b'; }
      public function getDisplayName(): string { return 'B'; }
    };

    $from = new DateTimeImmutable('2025-01-01T00:00:00Z');
    $to = new DateTimeImmutable('2025-01-08T00:00:00Z');

    $first = $collector->collect(
      principal: 'principals/users/admin',
      cals: [$calA, $calB],
      includeAll: true,
      selectedIds: [],
      from: $from,
      to: $to,
      maxPerCal: 50,
      maxTotal: 50,
      debug: false,
    );
    $second = $collector->collect(
      principal: 'principals/users/admin',
      cals: [$calA, $calB],
      includeAll: true,
      selectedIds: [],
      from: $from,
      to: $to,
      maxPerCal: 50,
      maxTotal: 50,
      debug: false,
    );

    $this->assertCount(3, $first['events']);
    $this->assertCount(3, $second['events']);
    $this->assertSame(2, $manager->calls, 'Second identical collect should be served from cache.');

    // Debug mode keeps a separate cache entry.
    $collector->collect(
      principal: 'principals/users/admin',
      cals: [$calA, $calB],
      includeAll: true,
      selectedIds: [],
      from: $from,
      to: $to,
      maxPerCal: 50,
      maxTotal: 50,
      debug: true,
    );
    $this->assertSame(4, $manager->calls);
  }

  public function testCollectIncludesTasksOverlappingRange(): void {
    $manager = new class() implements IManager {
      public array $queries = [];
      public function newQuery(string $principal): object {
        return new class() {
          public string $calendar = '';
          public array $types = [];
          public bool $ranged = false;
          public function addSearchCalendar(string $calendar): void { $this->calendar = $calendar; }
          public function addType(string $type): void { $this->types[] = $type; }
          public function setTimerangeStart($from): void { $this->ranged = true; }
          public function setTimerangeEnd($to): void { $this->ranged = true; }
        };
      }
      public function searchForPrincipal(object $q): array {
        $this->queries[] = ['types' => $q->types, 'ranged' => $q->ranged];
        if ($q->types !== ['VTODO']) {
          return [];
        }
        $task = fn (string $summary, string $start, string $due): array => [
          'type' => 'VTODO',
          'objects' => [[
            'SUMMARY' => [$summary, []],
            'DTSTART' => [['date' => $start, 'timezone' => 'UTC'], []],
            'DUE' => [['date' => $due, 'timezone' => 'UTC'], []],
          ]],
        ];
        return [
          $task('In range', '2025-01-02 09:00:00', '2025-01-02 11:00:00'),
          $task('Before range', '2024-12-20 09:00:00', '2024-12-20 11:00:00'),
          $task('After range', '2025-01-09 09:00:00', '2025-01-09 11:00:00'),
        ];
      }
    };

    $logger = $this->createMock(LoggerInterface::class);
    $collector = new OverviewEventsCollector($manager, new CalendarParsingService(), $logger);

    $tasks = new class() {
      public function getUri(): string { return 'tasks'; }
      public function getDisplayName(): string { return 'Tasks'; }
    };

    $res = $collector->collect(
      principal: 'principals/users/admin',
      cals: [$tasks],
      includeAll: true,
      selectedIds: [],
      from: new DateTimeImmutable('2025-01-01T00:00:00Z'),
      to: new DateTimeImmutable('2025-01-08T00:00:00Z'),
      maxPerCal: 50,
      maxTotal: 50,
      debug: false,
    );

    $this->assertCount(1, $res['events']);
    $this->assertSame('In range', $res['events'][0]['summary']);
    $this->assertSame('tasks', $res['events'][0]['calendar_id']);
    $taskQueries = array_filter($manager->queries, fn (array $q): bool => $q['types'] === ['VTODO']);
    $this->assertCount(1, $taskQueries);
    $this->assertFalse(reset($taskQueries)['ranged']);
  }
}
