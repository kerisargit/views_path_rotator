<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;
use Drupal\views_path_rotator\Service\PathRotatorManager;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\views_path_rotator\Service\PathRotatorManager
 * @group views_path_rotator
 */
class PathRotatorManagerTest extends UnitTestCase {

  protected function createTarget(int $intervalCount, string $intervalUnit): ViewsPathRotatorTargetInterface {
    $target = $this->prophesize(ViewsPathRotatorTargetInterface::class);
    $target->getIntervalCount()->willReturn($intervalCount);
    $target->getIntervalUnit()->willReturn($intervalUnit);
    $target->id()->willReturn('t');
    return $target->reveal();
  }

  protected function createManager(int $lastRotation, int $now): PathRotatorManager {
    $configFactory = $this->prophesize(ConfigFactoryInterface::class);

    $state = $this->prophesize(StateInterface::class);
    $state->get('views_path_rotator.last_rotation.t', 0)->willReturn($lastRotation);

    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('views_path_rotator')->willReturn($this->prophesize(LoggerInterface::class)->reveal());

    $time = $this->prophesize(TimeInterface::class);
    $time->getRequestTime()->willReturn($now);

    return new PathRotatorManager(
      $configFactory->reveal(),
      $state->reveal(),
      $this->prophesize(LockBackendInterface::class)->reveal(),
      $this->prophesize(EntityTypeManagerInterface::class)->reveal(),
      $this->prophesize(RouteProviderInterface::class)->reveal(),
      $loggerFactory->reveal(),
      $time->reveal(),
    );
  }

  /**
   * @covers ::getIntervalSeconds
   * @dataProvider intervalProvider
   */
  public function testGetIntervalSeconds(int $count, string $unit, int $expectedSeconds): void {
    $manager = $this->createManager(0, 1000);
    $this->assertSame($expectedSeconds, $manager->getIntervalSeconds($this->createTarget($count, $unit)));
  }

  public static function intervalProvider(): array {
    return [
      'zero minutes' => [0, 'minutes', 0],
      '30 minutes' => [30, 'minutes', 1800],
      '2 hours' => [2, 'hours', 7200],
      '1 day' => [1, 'days', 86400],
      'unknown unit counts as minutes' => [5, 'bogus', 300],
      'negative value gives 0 without an exception' => [-10, 'minutes', 0],
    ];
  }

  /**
   * @covers ::isDue
   */
  public function testIsDueTrueWhenNeverRotated(): void {
    $manager = $this->createManager(0, 1000);
    $this->assertTrue($manager->isDue($this->createTarget(30, 'minutes')), 'Never rotated: due.');
  }

  /**
   * @covers ::isDue
   */
  public function testIsDueFalseWithinInterval(): void {
    $manager = $this->createManager(900, 1000);
    $this->assertFalse($manager->isDue($this->createTarget(30, 'minutes')));
  }

  /**
   * @covers ::isDue
   */
  public function testIsDueTrueAfterIntervalElapsed(): void {
    $manager = $this->createManager(0, 1801);
    $this->assertTrue($manager->isDue($this->createTarget(30, 'minutes')));
  }

  /**
   * @covers ::isDue
   */
  public function testIsDueAlwaysTrueWhenIntervalIsZero(): void {
    $manager = $this->createManager(999, 1000);
    $this->assertTrue($manager->isDue($this->createTarget(0, 'minutes')));
  }

}
