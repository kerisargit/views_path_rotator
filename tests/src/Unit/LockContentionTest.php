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
use Prophecy\Argument;
use Psr\Log\LoggerInterface;

/**
 * @group views_path_rotator
 */
class LockContentionTest extends UnitTestCase {

  protected function createManager(LockBackendInterface $lock): PathRotatorManager {
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('views_path_rotator')->willReturn($this->prophesize(LoggerInterface::class)->reveal());

    return new PathRotatorManager(
      $this->prophesize(ConfigFactoryInterface::class)->reveal(),
      $this->prophesize(StateInterface::class)->reveal(),
      $lock,
      $this->prophesize(EntityTypeManagerInterface::class)->reveal(),
      $this->prophesize(RouteProviderInterface::class)->reveal(),
      $loggerFactory->reveal(),
      $this->prophesize(TimeInterface::class)->reveal(),
    );
  }

  public function testRotateReturnsSkippedWhenLockUnavailable(): void {
    $lock = $this->prophesize(LockBackendInterface::class);
    $lock->acquire(Argument::any(), Argument::any())->willReturn(FALSE);
    $lock->release(Argument::any())->shouldNotBeCalled();

    $manager = $this->createManager($lock->reveal());
    $target = $this->prophesize(ViewsPathRotatorTargetInterface::class);

    $result = $manager->rotate($target->reveal(), 'manual');

    $this->assertSame('skipped', $result['status']);
    $this->assertSame('locked', $result['reason']);
  }

  public function testRotateAllDueReturnsEmptyArrayWhenLockUnavailable(): void {
    $lock = $this->prophesize(LockBackendInterface::class);
    $lock->acquire(Argument::any(), Argument::any())->willReturn(FALSE);
    $lock->release(Argument::any())->shouldNotBeCalled();

    $manager = $this->createManager($lock->reveal());

    $this->assertSame([], $manager->rotateAllDue('cron'));
  }

}
