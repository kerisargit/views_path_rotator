<?php

namespace Drupal\views_path_rotator\IntervalTask;

use Drupal\interval_trigger\IntervalTaskInterface;
use Drupal\views_path_rotator\Service\PathRotatorManager;

class RotationTask implements IntervalTaskInterface {

  public function __construct(
    protected PathRotatorManager $manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getTaskId(): string {
    return 'views_path_rotator.rotation';
  }

  /**
   * {@inheritdoc}
   */
  public function respondsToTrigger(string $trigger): bool {
    if ($trigger === 'cron') {
      return $this->manager->hasEnabledTargets();
    }
    if ($trigger === 'request') {
      return $this->manager->hasEnabledTargetsRespondingToRequest();
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isDue(): bool {
    return $this->manager->hasAnyDueTarget();
  }

  /**
   * {@inheritdoc}
   */
  public function run(string $trigger): void {
    $this->manager->rotateAllDue($trigger);
  }

}
