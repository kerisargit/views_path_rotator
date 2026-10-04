<?php

namespace Drupal\views_path_rotator\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;
use Psr\Log\LoggerInterface;

class PathRotatorManager {

  protected const LOCK_NAME = 'views_path_rotator_rotate';

  protected LoggerInterface $logger;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected LockBackendInterface $lock,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RouteProviderInterface $routeProvider,
    LoggerChannelFactoryInterface $loggerFactory,
    protected TimeInterface $time,
  ) {
    $this->logger = $loggerFactory->get('views_path_rotator');
  }

  public function getIntervalSeconds(ViewsPathRotatorTargetInterface $target): int {
    $multipliers = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];
    return max(0, $target->getIntervalCount()) * ($multipliers[$target->getIntervalUnit()] ?? 60);
  }

  public function isDue(ViewsPathRotatorTargetInterface $target): bool {
    $interval = $this->getIntervalSeconds($target);
    if ($interval <= 0) {
      return TRUE;
    }
    $last = (int) $this->state->get($this->stateKey($target, 'last_rotation'), 0);
    return $last === 0 || ($this->time->getRequestTime() - $last) >= $interval;
  }

  public function getStatus(ViewsPathRotatorTargetInterface $target): array {
    $last = (int) $this->state->get($this->stateKey($target, 'last_rotation'), 0);
    $interval = $this->getIntervalSeconds($target);
    return [
      'current_path' => $this->state->get($this->stateKey($target, 'current_path')),
      'previous_path' => $this->state->get($this->stateKey($target, 'previous_path')),
      'last_rotation' => $last,
      'next_due' => $last === 0 ? NULL : $last + $interval,
    ];
  }

  public function getEnabledTargets(): array {
    return $this->entityTypeManager->getStorage('views_path_rotator_target')->loadByProperties(['status' => TRUE]);
  }

  public function hasEnabledTargets(): bool {
    return $this->getEnabledTargets() !== [];
  }

  public function hasEnabledTargetsRespondingToRequest(): bool {
    foreach ($this->getEnabledTargets() as $target) {
      if ($target->triggersOnRequest()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function hasAnyDueTarget(): bool {
    foreach ($this->getEnabledTargets() as $target) {
      if ($this->isDue($target)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function rotate(ViewsPathRotatorTargetInterface $target, string $trigger = 'manual'): array {
    try {
      $acquired = $this->lock->acquire(self::LOCK_NAME, 60);
    }
    catch (\Throwable $e) {
      return ['status' => 'error', 'reason' => 'lock backend failed: ' . $e->getMessage()];
    }
    if (!$acquired) {
      return ['status' => 'skipped', 'reason' => 'locked'];
    }

    try {
      $reservedPaths = $this->activeRotatedPaths();
      return $this->doRotate($target, $trigger, $reservedPaths);
    }
    catch (\Throwable $e) {
      $this->logger->error('Rotation of target @target failed with an exception. @class: @message', [
        '@target' => $target->id(),
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
      ]);
      return ['status' => 'error', 'reason' => 'exception: ' . $e->getMessage()];
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }
  }

  public function rotateAllDue(string $trigger): array {
    if (!$this->lock->acquire(self::LOCK_NAME, 60)) {
      return [];
    }

    $results = [];
    try {
      $reservedPaths = $this->activeRotatedPaths();
      foreach ($this->getEnabledTargets() as $target) {
        if ($trigger === 'request' && !$target->triggersOnRequest()) {
          continue;
        }
        if (!$this->isDue($target)) {
          continue;
        }
        try {
          $results[$target->id()] = $this->doRotate($target, $trigger, $reservedPaths);
        }
        catch (\Throwable $e) {
          $this->logger->error('Rotation of target @target failed with an exception. @class: @message', [
            '@target' => $target->id(),
            '@class' => get_class($e),
            '@message' => $e->getMessage(),
          ]);
          $results[$target->id()] = ['status' => 'error', 'reason' => 'exception: ' . $e->getMessage()];
        }
      }
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }

    return $results;
  }

  protected function activeRotatedPaths(): array {
    $reserved = [];
    foreach ($this->entityTypeManager->getStorage('views_path_rotator_target')->loadMultiple() as $other) {
      /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $other */
      $path = $this->state->get($this->stateKey($other, 'current_path'));
      if (is_string($path) && $path !== '') {
        $reserved[$path] = TRUE;
      }
    }
    return $reserved;
  }

  protected function doRotate(ViewsPathRotatorTargetInterface $target, string $trigger, array &$reservedPaths): array {
    $view_id = $target->getViewId();
    $display_id = $target->getDisplayId();

    if ($view_id === '' || $display_id === '') {
      return ['status' => 'skipped', 'reason' => 'not configured'];
    }

    /** @var \Drupal\views\Entity\View|null $view */
    $view = $this->entityTypeManager->getStorage('view')->load($view_id);
    if (!$view) {
      $this->logger->error('View @id not found (target @target).', ['@id' => $view_id, '@target' => $target->id()]);
      return ['status' => 'error', 'reason' => 'view not found'];
    }

    $display = $view->getDisplay($display_id);
    if (empty($display['display_options']['path'])) {
      $this->logger->error('Display @view:@display not found or has no path (target @target).', [
        '@view' => $view_id,
        '@display' => $display_id,
        '@target' => $target->id(),
      ]);
      return ['status' => 'error', 'reason' => 'display has no path'];
    }

    if (static::pathHasArguments($display['display_options']['path'])) {
      $this->logger->error('Display @view:@display has an argument in its path (@path); rotation would replace the whole path and break the argument (target @target).', [
        '@view' => $view_id,
        '@display' => $display_id,
        '@path' => $display['display_options']['path'],
        '@target' => $target->id(),
      ]);
      return ['status' => 'error', 'reason' => 'display path has arguments'];
    }

    $nominal_path = '/' . ltrim($display['display_options']['path'], '/');

    $front = ltrim((string) $this->configFactory->get('system.site')->get('page.front'), '/');
    if ($front !== '' && ltrim($nominal_path, '/') === $front) {
      $this->logger->error('Rotation of target @target cancelled: the nominal display path (@path) is the site front page (system.site:page.front). This module substitutes the route path while page.front points to the original one, so rotating this display would leave the site without a working front page.', [
        '@target' => $target->id(),
        '@path' => $nominal_path,
      ]);
      return ['status' => 'error', 'reason' => 'target is the site front page'];
    }

    $old_path = (string) ($this->state->get($this->stateKey($target, 'current_path')) ?: $nominal_path);

    $new_path = $this->generateUniquePath($target, $old_path, $reservedPaths);
    if ($new_path === NULL) {
      $this->logger->error('Could not find a free path within the allowed number of attempts (target @target, view @view:@display).', [
        '@target' => $target->id(),
        '@view' => $view_id,
        '@display' => $display_id,
      ]);
      return ['status' => 'error', 'reason' => 'no free path found'];
    }

    $this->state->setMultiple([
      $this->stateKey($target, 'previous_path') => $old_path,
      $this->stateKey($target, 'current_path') => $new_path,
      $this->stateKey($target, 'last_rotation') => $this->time->getRequestTime(),
    ]);

    Cache::invalidateTags(['route_match', 'views_path_rotator:target:' . $target->id()]);

    $this->logger->info('Path rotated: @old → @new (target @target, view @view:@display, trigger: @trigger).', [
      '@old' => $old_path,
      '@new' => $new_path,
      '@target' => $target->id(),
      '@view' => $view_id,
      '@display' => $display_id,
      '@trigger' => $trigger,
    ]);

    return ['status' => 'success', 'old_path' => $old_path, 'new_path' => $new_path];
  }

  protected function generateUniquePath(ViewsPathRotatorTargetInterface $target, string $excludePath, array &$reservedPaths): ?string {
    $prefix = trim($target->getPathPrefix(), '/');
    $length = max(4, min(64, $target->getTokenLength() ?: 16));

    for ($attempt = 0; $attempt < 10; $attempt++) {
      $token = $this->generateToken($length);
      $candidate = $prefix !== '' ? '/' . $prefix . '-' . $token : '/' . $token;
      if ($candidate === $excludePath) {
        continue;
      }
      if (isset($reservedPaths[$candidate])) {
        continue;
      }
      if ($this->pathIsTaken($candidate)) {
        continue;
      }
      $reservedPaths[$candidate] = TRUE;
      return $candidate;
    }
    return NULL;
  }

  public static function pathHasArguments(string $path): bool {
    return str_contains($path, '%') || str_contains($path, '{');
  }

  protected function generateToken(int $length): string {
    return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
  }

  protected function pathIsTaken(string $path): bool {
    return count($this->routeProvider->getRoutesByPattern($path)) > 0;
  }

  protected function stateKey(ViewsPathRotatorTargetInterface $target, string $suffix): string {
    return 'views_path_rotator.' . $suffix . '.' . $target->id();
  }

}
