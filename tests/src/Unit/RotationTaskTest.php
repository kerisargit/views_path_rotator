<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\views_path_rotator\IntervalTask\RotationTask;
use Drupal\views_path_rotator\Service\PathRotatorManager;

/**
 * @coversDefaultClass \Drupal\views_path_rotator\IntervalTask\RotationTask
 * @group views_path_rotator
 */
class RotationTaskTest extends UnitTestCase {

  public function testRespondsToCronDelegatesToHasEnabledTargets(): void {
    $manager = $this->prophesize(PathRotatorManager::class);
    $manager->hasEnabledTargets()->willReturn(TRUE);
    $task = new RotationTask($manager->reveal());
    $this->assertTrue($task->respondsToTrigger('cron'));
  }

  public function testDoesNotRespondToCronWhenNoEnabledTargets(): void {
    $manager = $this->prophesize(PathRotatorManager::class);
    $manager->hasEnabledTargets()->willReturn(FALSE);
    $task = new RotationTask($manager->reveal());
    $this->assertFalse($task->respondsToTrigger('cron'));
  }

  public function testRespondsToRequestDelegatesToHasEnabledTargetsRespondingToRequest(): void {
    $manager = $this->prophesize(PathRotatorManager::class);
    $manager->hasEnabledTargetsRespondingToRequest()->willReturn(TRUE);
    $task = new RotationTask($manager->reveal());
    $this->assertTrue($task->respondsToTrigger('request'));
  }

  public function testDoesNotRespondToRequestWhenNoTargetOptedIn(): void {
    $manager = $this->prophesize(PathRotatorManager::class);
    $manager->hasEnabledTargetsRespondingToRequest()->willReturn(FALSE);
    $task = new RotationTask($manager->reveal());
    $this->assertFalse($task->respondsToTrigger('request'));
  }

  public function testUnknownTriggerNeverResponds(): void {
    $manager = $this->prophesize(PathRotatorManager::class);
    $task = new RotationTask($manager->reveal());
    $this->assertFalse($task->respondsToTrigger('bogus'));
  }

  public function testIsDueDelegatesToHasAnyDueTarget(): void {
    $manager = $this->prophesize(PathRotatorManager::class);
    $manager->hasAnyDueTarget()->willReturn(TRUE);
    $task = new RotationTask($manager->reveal());
    $this->assertTrue($task->isDue());

    $manager2 = $this->prophesize(PathRotatorManager::class);
    $manager2->hasAnyDueTarget()->willReturn(FALSE);
    $task2 = new RotationTask($manager2->reveal());
    $this->assertFalse($task2->isDue());
  }

  public function testRunDelegatesToRotateAllDueWithTrigger(): void {
    $manager = $this->prophesize(PathRotatorManager::class);
    $manager->rotateAllDue('request')->shouldBeCalled();

    $task = new RotationTask($manager->reveal());
    $task->run('request');
  }

}
