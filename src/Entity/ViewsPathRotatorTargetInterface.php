<?php

namespace Drupal\views_path_rotator\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

interface ViewsPathRotatorTargetInterface extends ConfigEntityInterface {

  public function getViewId(): string;

  public function getDisplayId(): string;

  public function getRouteName(): string;

  public function getPathPrefix(): string;

  public function getTokenLength(): int;

  public function getIntervalCount(): int;

  public function getIntervalUnit(): string;

  public function triggersOnRequest(): bool;

}
