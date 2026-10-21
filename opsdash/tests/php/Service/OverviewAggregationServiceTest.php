<?php

declare(strict_types=1);

namespace OCA\Opsdash\Tests\Service;

use OCA\Opsdash\Service\OverviewAggregationService;
use PHPUnit\Framework\TestCase;

final class OverviewAggregationServiceTest extends TestCase {
  public function testAggregateSplitsCurrentRangeIntoActualAndFutureHours(): void {
    $service = new OverviewAggregationService();
    $from = new \DateTimeImmutable('2025-01-06T00:00:00Z');
    $to = new \DateTimeImmutable('2025-01-12T23:59:59Z');
    $now = new \DateTimeImmutable('2025-01-08T12:00:00Z');

    $result = $service->aggregate(
      events: [
        [
          'calendar' => 'Work',
          'calendar_id' => 'cal-1',
          'title' => 'Past block',
          'hours' => 2.0,
          'allday' => false,
          'start' => '2025-01-06T09:00:00Z',
          'end' => '2025-01-06T11:00:00Z',
          'startTz' => 'UTC',
          'endTz' => 'UTC',
        ],
        [
          'calendar' => 'Work',
          'calendar_id' => 'cal-1',
          'title' => 'Current block',
          'hours' => 2.0,
          'allday' => false,
          'start' => '2025-01-08T11:00:00Z',
          'end' => '2025-01-08T13:00:00Z',
          'startTz' => 'UTC',
          'endTz' => 'UTC',
        ],
        [
          'calendar' => 'Work',
          'calendar_id' => 'cal-1',
          'title' => 'Future block',
          'hours' => 2.0,
          'allday' => false,
          'start' => '2025-01-09T09:00:00Z',
          'end' => '2025-01-09T11:00:00Z',
          'startTz' => 'UTC',
          'endTz' => 'UTC',
        ],
        [
          'calendar' => 'Work',
          'calendar_id' => 'cal-1',
          'title' => 'All day later',
          'hours' => 0.0,
          'allday' => true,
          'start' => '2025-01-10T00:00:00Z',
          'end' => '2025-01-11T00:00:00Z',
          'startTz' => 'UTC',
          'endTz' => 'UTC',
        ],
      ],
      from: $from,
      to: $to,
      userTz: new \DateTimeZone('UTC'),
      allDayHours: 8.0,
      colorsById: ['cal-1' => '#123456'],
      categoryMeta: ['work' => ['id' => 'work', 'label' => 'Work']],
      mapCalToCategory: static fn (string $calId): string => 'work',
      now: $now,
    );

    $this->assertTrue($result['currentPeriodClipped']);
    $this->assertSame('2025-01-08T12:00:00+00:00', $result['currentCutoff']);
    $this->assertSame(3.0, round($result['totalHours'], 2));
    $this->assertSame(11.0, round($result['futureTotalHours'], 2));
    $this->assertSame(2, $result['eventsCount']);
    $this->assertSame(3.0, round($result['byCalMap']['cal-1']['total_hours'], 2));
    $this->assertSame(11.0, round($result['byCalMap']['cal-1']['future_hours'], 2));
    $this->assertSame(2, $result['byCalMap']['cal-1']['events_count']);
    $this->assertSame(1.0, round($result['byDay']['2025-01-08']['total_hours'], 2));
    $this->assertSame(1.0, round($result['byDay']['2025-01-08']['future_hours'], 2));
    $this->assertSame(0.0, round($result['byDay']['2025-01-09']['total_hours'], 2));
    $this->assertSame(2.0, round($result['byDay']['2025-01-09']['future_hours'], 2));
    $this->assertSame(8.0, round($result['byDay']['2025-01-10']['future_hours'], 2));
    $this->assertSame(2.0, round($result['longestSessionHours'], 2));
  }

  public function testAggregateReadsRowsInTheirSourceTimezone(): void {
    $service = new OverviewAggregationService();
    $ny = new \DateTimeZone('America/New_York');

    $result = $service->aggregate(
      events: [
        [
          // 15:00 in Berlin is 09:00 in New York.
          'calendar' => 'Work',
          'calendar_id' => 'cal-1',
          'title' => 'Sync with Berlin',
          'allday' => false,
          'start' => '2025-12-15 15:00:00',
          'end' => '2025-12-15 16:00:00',
          'startTz' => 'Europe/Berlin',
          'endTz' => 'Europe/Berlin',
        ],
        [
          // Floating all-day date: stays on the 16th in the user's timezone.
          'calendar' => 'Work',
          'calendar_id' => 'cal-1',
          'title' => 'Workshop',
          'allday' => true,
          'start' => '2025-12-16 00:00:00',
          'end' => '2025-12-17 00:00:00',
          'startTz' => '',
          'endTz' => '',
        ],
      ],
      from: new \DateTimeImmutable('2025-12-15 00:00:00', $ny),
      to: new \DateTimeImmutable('2025-12-21 23:59:59', $ny),
      userTz: $ny,
      allDayHours: 8.0,
      colorsById: [],
      categoryMeta: ['work' => ['id' => 'work', 'label' => 'Work']],
      mapCalToCategory: static fn (string $calId): string => 'work',
      now: new \DateTimeImmutable('2026-01-01 00:00:00', $ny),
    );

    $this->assertSame(1.0, round($result['hod']['Mon'][9], 2));
    $this->assertSame(1.0, round($result['byDay']['2025-12-15']['total_hours'], 2));
    $this->assertSame(8.0, round($result['byDay']['2025-12-16']['total_hours'], 2));
    $this->assertSame(9.0, round($result['totalHours'], 2));
  }
}
