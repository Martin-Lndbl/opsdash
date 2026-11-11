<?php

declare(strict_types=1);

namespace OCA\Opsdash\Tests\Service;

use OCA\Opsdash\Service\CalendarPriorityResolver;
use PHPUnit\Framework\TestCase;

final class CalendarPriorityResolverTest extends TestCase {
  public function testEqualPrioritiesKeepEveryEventIntact(): void {
    $result = CalendarPriorityResolver::resolve([
      'a' => ['start' => 0, 'end' => 100, 'priority' => 0],
      'b' => ['start' => 50, 'end' => 150, 'priority' => 0],
    ]);

    $this->assertSame([[0, 100]], $result['a']);
    $this->assertSame([[50, 150]], $result['b']);
  }

  public function testHigherPriorityClaimsTheOverlap(): void {
    $result = CalendarPriorityResolver::resolve([
      'lecture' => ['start' => 0, 'end' => 240, 'priority' => 0],
      'lunch' => ['start' => 120, 'end' => 180, 'priority' => 5],
    ]);

    $this->assertSame([[120, 180]], $result['lunch']);
    $this->assertSame([[0, 120], [180, 240]], $result['lecture']);
  }

  public function testFullyCoveredEventIsLeftEmpty(): void {
    $result = CalendarPriorityResolver::resolve([
      'low' => ['start' => 10, 'end' => 20, 'priority' => -1],
      'high' => ['start' => 0, 'end' => 30, 'priority' => 1],
    ]);

    $this->assertSame([], $result['low']);
    $this->assertSame([[0, 30]], $result['high']);
  }

  public function testMaskAccumulatesAcrossSeveralHigherLevels(): void {
    $result = CalendarPriorityResolver::resolve([
      'top' => ['start' => 0, 'end' => 10, 'priority' => 2],
      'mid' => ['start' => 5, 'end' => 20, 'priority' => 1],
      'low' => ['start' => 0, 'end' => 30, 'priority' => 0],
    ]);

    $this->assertSame([[0, 10]], $result['top']);
    $this->assertSame([[10, 20]], $result['mid']);
    $this->assertSame([[20, 30]], $result['low']);
  }

  public function testPriorityForClampsAndIgnoresGarbage(): void {
    $priorities = ['a' => 99, 'b' => -99, 'c' => 'x', 'd' => '3'];

    $this->assertSame(10, CalendarPriorityResolver::priorityFor($priorities, 'a'));
    $this->assertSame(-10, CalendarPriorityResolver::priorityFor($priorities, 'b'));
    $this->assertSame(0, CalendarPriorityResolver::priorityFor($priorities, 'c'));
    $this->assertSame(3, CalendarPriorityResolver::priorityFor($priorities, 'd'));
    $this->assertSame(0, CalendarPriorityResolver::priorityFor($priorities, 'missing'));
  }
}
