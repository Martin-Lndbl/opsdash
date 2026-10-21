<?php

declare(strict_types=1);

namespace OCA\Opsdash\Tests\Service;

use OCA\Opsdash\Service\CalendarParsingService;
use PHPUnit\Framework\TestCase;

final class CalendarServiceTest extends TestCase {
    public function testParseRowsStructuredObjects(): void {
        $service = new CalendarParsingService();

        $raw = [
            [
                'objects' => [
                    [
                        'UID' => ['opsdash-work-w1-mon-standup', []],
                        'SUMMARY' => ['Team standup', []],
                        'DTSTART' => [
                            [
                                'date' => '2025-12-15 09:00:00.000000',
                                'timezone_type' => 3,
                                'timezone' => 'UTC',
                            ],
                            [],
                        ],
                        'DTEND' => [
                            [
                                'date' => '2025-12-15 09:30:00.000000',
                                'timezone_type' => 3,
                                'timezone' => 'UTC',
                            ],
                            [],
                        ],
                    ],
                ],
            ],
        ];

        $rows = $service->parseRows($raw, 'opsdash-work', 'opsdash-work');

        $this->assertCount(1, $rows);
        $this->assertSame('Team standup', $rows[0]['summary']);
        $this->assertSame('2025-12-15 09:00:00', $rows[0]['start']);
        $this->assertSame('2025-12-15 09:30:00', $rows[0]['end']);
        $this->assertFalse($rows[0]['allday']);
    }

    public function testParseRowsTopLevelStartEnd(): void {
        $service = new CalendarParsingService();

        $raw = [
            [
                'summary' => 'Focus block',
                'start' => '2026-01-08 16:00:00',
                'end' => '2026-01-08 17:00:00',
                'allday' => false,
            ],
        ];

        $rows = $service->parseRows($raw, 'personal', 'personal');

        $this->assertCount(1, $rows);
        $this->assertSame('Focus block', $rows[0]['summary']);
        $this->assertSame('2026-01-08 16:00:00', $rows[0]['start']);
        $this->assertSame('2026-01-08 17:00:00', $rows[0]['end']);
        $this->assertFalse($rows[0]['allday']);
    }

    public function testParseRowsStructuredDurationArray(): void {
        $service = new CalendarParsingService();

        $raw = [
            [
                'objects' => [
                    [
                        'SUMMARY' => ['Focus session', []],
                        'DTSTART' => [
                            [
                                'date' => '2025-12-15 09:00:00.000000',
                                'timezone_type' => 3,
                                'timezone' => 'UTC',
                            ],
                            [],
                        ],
                        'DURATION' => ['PT45M', []],
                    ],
                ],
            ],
        ];

        $rows = $service->parseRows($raw, 'opsdash-work', 'opsdash-work');

        $this->assertCount(1, $rows);
        $this->assertSame('Focus session', $rows[0]['summary']);
        $this->assertSame('2025-12-15 09:00:00', $rows[0]['start']);
        $this->assertSame('2025-12-15 09:45:00', $rows[0]['end']);
    }

    public function testParseRowsTopLevelSummaryArray(): void {
        $service = new CalendarParsingService();

        $raw = [
            [
                'summary' => ['Focus block', []],
                'start' => '2026-01-08 16:00:00',
                'end' => '2026-01-08 17:00:00',
                'allday' => false,
            ],
        ];

        $rows = $service->parseRows($raw, 'personal', 'personal');

        $this->assertCount(1, $rows);
        $this->assertSame('Focus block', $rows[0]['summary']);
        $this->assertSame('2026-01-08 16:00:00', $rows[0]['start']);
        $this->assertSame('2026-01-08 17:00:00', $rows[0]['end']);
    }

    public function testParseRowsSkipsFreeEvents(): void {
        $service = new CalendarParsingService();

        $event = fn (string $summary, array $extra): array => array_merge([
            'SUMMARY' => [$summary, []],
            'DTSTART' => [['date' => '2025-12-15 09:00:00.000000', 'timezone' => 'UTC'], []],
            'DTEND' => [['date' => '2025-12-15 10:00:00.000000', 'timezone' => 'UTC'], []],
        ], $extra);

        $raw = [
            ['type' => 'VEVENT', 'objects' => [$event('Busy', ['TRANSP' => ['OPAQUE', []]])]],
            ['type' => 'VEVENT', 'objects' => [$event('Free', ['TRANSP' => ['TRANSPARENT', []]])]],
            ['type' => 'VEVENT', 'objects' => [$event('Outlook free', ['X-MICROSOFT-CDO-BUSYSTATUS' => ['FREE', []]])]],
        ];

        $rows = $service->parseRows($raw, 'personal', 'personal');

        $this->assertCount(1, $rows);
        $this->assertSame('Busy', $rows[0]['summary']);
    }

    public function testParseRowsKeepsSourceTimezone(): void {
        $service = new CalendarParsingService();
        $berlin = new \DateTimeZone('Europe/Berlin');

        $raw = [
            ['type' => 'VEVENT', 'objects' => [[
                'SUMMARY' => ['Standup', []],
                'DTSTART' => [new \DateTimeImmutable('2025-12-15 09:00:00', $berlin), ['TZID' => 'Europe/Berlin']],
                'DTEND' => [new \DateTimeImmutable('2025-12-15 09:30:00', $berlin), ['TZID' => 'Europe/Berlin']],
            ]]],
            ['type' => 'VEVENT', 'objects' => [[
                'SUMMARY' => ['Holiday', []],
                'DTSTART' => [new \DateTimeImmutable('2025-12-24 00:00:00', new \DateTimeZone('UTC')), ['VALUE' => 'DATE']],
                'DTEND' => [new \DateTimeImmutable('2025-12-25 00:00:00', new \DateTimeZone('UTC')), ['VALUE' => 'DATE']],
            ]]],
        ];

        $rows = $service->parseRows($raw, 'personal', 'personal');

        $this->assertCount(2, $rows);
        $this->assertSame('2025-12-15 09:00:00', $rows[0]['start']);
        $this->assertSame('Europe/Berlin', $rows[0]['startTz']);
        $this->assertSame('Europe/Berlin', $rows[0]['endTz']);
        // All-day dates are floating: no zone, read in the user's timezone downstream.
        $this->assertSame('2025-12-24 00:00:00', $rows[1]['start']);
        $this->assertSame('', $rows[1]['startTz']);
        $this->assertSame('', $rows[1]['endTz']);
    }

    public function testParseRowsLeavesTasksToParseTaskRows(): void {
        $service = new CalendarParsingService();

        $raw = [[
            'type' => 'VTODO',
            'objects' => [[
                'SUMMARY' => ['Write report', []],
                'DTSTART' => [['date' => '2025-12-15 09:00:00.000000', 'timezone' => 'UTC'], []],
                'DURATION' => ['PT1H', []],
            ]],
        ]];

        $this->assertSame([], $service->parseRows($raw, 'Tasks', 'tasks'));
    }

    public function testParseTaskRowsSpanStartToDue(): void {
        $service = new CalendarParsingService();
        [$from, $to] = $this->decemberRange('UTC');

        $raw = [
            [
                'type' => 'VTODO',
                'objects' => [[
                    'SUMMARY' => ['Write report', []],
                    'STATUS' => ['COMPLETED', []],
                    'DTSTART' => [['date' => '2025-12-15 09:00:00.000000', 'timezone' => 'UTC'], []],
                    'DUE' => [['date' => '2025-12-15 11:30:00.000000', 'timezone' => 'UTC'], []],
                ]],
            ],
            [
                'type' => 'VTODO',
                'objects' => [[
                    'SUMMARY' => ['Review', []],
                    'DUE' => [['date' => '2025-12-16 12:00:00.000000', 'timezone' => 'UTC'], []],
                    'DURATION' => ['PT45M', []],
                ]],
            ],
            [
                'type' => 'VTODO',
                'objects' => [[
                    'SUMMARY' => ['Last year', []],
                    'DTSTART' => [['date' => '2024-12-15 09:00:00.000000', 'timezone' => 'UTC'], []],
                    'DUE' => [['date' => '2024-12-15 10:00:00.000000', 'timezone' => 'UTC'], []],
                ]],
            ],
        ];

        $rows = $service->parseTaskRows($raw, 'Tasks', 'tasks', $from, $to);

        $this->assertCount(2, $rows);
        $this->assertSame('Write report', $rows[0]['summary']);
        $this->assertSame('tasks', $rows[0]['calendar_id']);
        $this->assertSame('2025-12-15 09:00:00', $rows[0]['start']);
        $this->assertSame('2025-12-15 11:30:00', $rows[0]['end']);
        $this->assertSame('UTC', $rows[0]['startTz']);
        $this->assertFalse($rows[0]['allday']);
        $this->assertSame('2025-12-16 11:15:00', $rows[1]['start']);
        $this->assertSame('2025-12-16 12:00:00', $rows[1]['end']);
    }

    public function testParseTaskRowsAllDaySameDateCountsOneDay(): void {
        $service = new CalendarParsingService();
        [$from, $to] = $this->decemberRange('UTC');

        $raw = [[
            'type' => 'VTODO',
            'objects' => [[
                'SUMMARY' => ['Offsite prep', []],
                'DTSTART' => [['date' => '2025-12-15 00:00:00.000000', 'timezone' => 'UTC'], ['VALUE' => 'DATE']],
                'DUE' => [['date' => '2025-12-15 00:00:00.000000', 'timezone' => 'UTC'], ['VALUE' => 'DATE']],
            ]],
        ]];

        $rows = $service->parseTaskRows($raw, 'Tasks', 'tasks', $from, $to);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['allday']);
        $this->assertSame('2025-12-15 00:00:00', $rows[0]['start']);
        $this->assertSame('2025-12-16 00:00:00', $rows[0]['end']);
        $this->assertSame('', $rows[0]['startTz']);
    }

    public function testParseTaskRowsSkipsTasksWithoutSpanOrCancelled(): void {
        $service = new CalendarParsingService();
        [$from, $to] = $this->decemberRange('UTC');

        $raw = [
            ['type' => 'VTODO', 'objects' => [[
                'SUMMARY' => ['Due only', []],
                'DUE' => [['date' => '2025-12-15 12:00:00.000000', 'timezone' => 'UTC'], []],
            ]]],
            ['type' => 'VTODO', 'objects' => [[
                'SUMMARY' => ['No dates', []],
            ]]],
            ['type' => 'VTODO', 'objects' => [[
                'SUMMARY' => ['Cancelled', []],
                'STATUS' => ['CANCELLED', []],
                'DTSTART' => [['date' => '2025-12-15 09:00:00.000000', 'timezone' => 'UTC'], []],
                'DUE' => [['date' => '2025-12-15 10:00:00.000000', 'timezone' => 'UTC'], []],
            ]]],
        ];

        $this->assertSame([], $service->parseTaskRows($raw, 'Tasks', 'tasks', $from, $to));
    }

    public function testParseTaskRowsExpandsRecurringTasks(): void {
        $service = new CalendarParsingService();
        $berlin = new \DateTimeZone('Europe/Berlin');
        [$from, $to] = $this->decemberRange('Europe/Berlin');
        $at = fn (string $local): \DateTimeImmutable => new \DateTimeImmutable($local, $berlin);
        $tzid = ['TZID' => 'Europe/Berlin'];

        $raw = [[
            'type' => 'VTODO',
            'objects' => [
                [
                    // Weekly on Mondays since January; only the December occurrences are in range.
                    'SUMMARY' => ['Weekly review', []],
                    'DTSTART' => [$at('2025-01-06 09:00:00'), $tzid],
                    'DUE' => [$at('2025-01-06 10:30:00'), $tzid],
                    'RRULE' => ['FREQ=WEEKLY;BYDAY=MO', []],
                    'EXDATE' => [[$at('2025-12-15 09:00:00'), $tzid]],
                ],
                [
                    // The 22 December occurrence was moved to Tuesday afternoon.
                    'SUMMARY' => ['Weekly review (moved)', []],
                    'RECURRENCE-ID' => [$at('2025-12-22 09:00:00'), $tzid],
                    'DTSTART' => [$at('2025-12-23 14:00:00'), $tzid],
                    'DUE' => [$at('2025-12-23 15:00:00'), $tzid],
                ],
            ],
        ]];

        $rows = $service->parseTaskRows($raw, 'Tasks', 'tasks', $from, $to);

        $this->assertSame(
            [
                ['Weekly review', '2025-12-01 09:00:00', '2025-12-01 10:30:00'],
                ['Weekly review', '2025-12-08 09:00:00', '2025-12-08 10:30:00'],
                ['Weekly review', '2025-12-29 09:00:00', '2025-12-29 10:30:00'],
                ['Weekly review (moved)', '2025-12-23 14:00:00', '2025-12-23 15:00:00'],
            ],
            array_map(fn (array $row): array => [$row['summary'], $row['start'], $row['end']], $rows),
        );
        $this->assertSame('Europe/Berlin', $rows[0]['startTz']);
    }

    public function testParseTaskRowsExpandsFloatingAllDayTasksInRangeTimezone(): void {
        $service = new CalendarParsingService();
        // Week of 1 December 2025 for a user in New York; all-day dates must not shift by the UTC offset.
        $ny = new \DateTimeZone('America/New_York');
        $from = new \DateTimeImmutable('2025-12-01 00:00:00', $ny);
        $to = new \DateTimeImmutable('2025-12-07 23:59:59', $ny);
        $utc = new \DateTimeZone('UTC');

        $raw = [[
            'type' => 'VTODO',
            'objects' => [[
                'SUMMARY' => ['Daily log', []],
                'DTSTART' => [new \DateTimeImmutable('2025-11-30 00:00:00', $utc), ['VALUE' => 'DATE']],
                'DUE' => [new \DateTimeImmutable('2025-12-01 00:00:00', $utc), ['VALUE' => 'DATE']],
                'RRULE' => ['FREQ=DAILY;COUNT=4', []],
            ]],
        ]];

        $rows = $service->parseTaskRows($raw, 'Tasks', 'tasks', $from, $to);

        $this->assertSame(
            ['2025-12-01 00:00:00', '2025-12-02 00:00:00', '2025-12-03 00:00:00'],
            array_column($rows, 'start'),
        );
        $this->assertTrue($rows[0]['allday']);
        $this->assertSame('', $rows[0]['startTz']);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function decemberRange(string $tz): array {
        $zone = new \DateTimeZone($tz);
        return [
            new \DateTimeImmutable('2025-12-01 00:00:00', $zone),
            new \DateTimeImmutable('2025-12-31 23:59:59', $zone),
        ];
    }
}
