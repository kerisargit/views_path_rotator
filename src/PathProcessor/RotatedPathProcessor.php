<?php

namespace Drupal\views_path_rotator\PathProcessor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\PathProcessor\OutboundPathProcessorInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

class RotatedPathProcessor implements InboundPathProcessorInterface, OutboundPathProcessorInterface {

  /**
   * @var array<string, string|null>
   */
  protected array $nominalPaths = [];

  protected ?RouteProviderInterface $routeProvider = NULL;

  protected bool $failureLogged = FALSE;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ContainerInterface $container,
    protected StateInterface $state,
  ) {}

  // Resolved lazily: the route provider depends on path_processor_manager,
  // which collects this service.
  protected function routeProvider(): RouteProviderInterface {
    return $this->routeProvider ??= $this->container->get('router.route_provider');
  }

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    try {
      return $this->rotatedToNominal($path);
    }
    catch (\Throwable $e) {
      $this->logFailure($e);
      return $path;
    }
  }

  protected function rotatedToNominal(string $path): string {
    foreach ($this->targets() as $id => $target) {
      $nominal = $this->nominalPath($id, $target->getRouteName());
      if ($nominal === NULL) {
        continue;
      }

      if ($path === $this->currentPath($id)) {
        return $nominal;
      }
    }

    return $path;
  }

  /**
   * {@inheritdoc}
   */
  public function processOutbound($path, &$options = [], ?Request $request = NULL, ?BubbleableMetadata $bubbleable_metadata = NULL) {
    try {
      return $this->nominalToRotated($path, $bubbleable_metadata);
    }
    catch (\Throwable $e) {
      $this->logFailure($e);
      return $path;
    }
  }

  protected function nominalToRotated(string $path, ?BubbleableMetadata $bubbleable_metadata): string {
    foreach ($this->targets() as $id => $target) {
      $nominal = $this->nominalPath($id, $target->getRouteName());
      if ($nominal === NULL || $path !== $nominal) {
        continue;
      }

      $bubbleable_metadata?->addCacheTags(['views_path_rotator:target:' . $id]);

      $current = $this->currentPath($id);
      if ($current === NULL) {
        return $path;
      }
      return $current;
    }

    return $path;
  }

  /**
   * Runs on every request and every generated link, so a failure (database
   * hiccup, broken entity) must leave paths untouched instead of breaking the
   * whole site; logged once per request.
   */
  protected function logFailure(\Throwable $e): void {
    if ($this->failureLogged) {
      return;
    }
    $this->failureLogged = TRUE;
    try {
      $this->container->get('logger.factory')->get('views_path_rotator')
        ->error('Path processing skipped: @class: @message', ['@class' => \get_class($e), '@message' => $e->getMessage()]);
    }
    catch (\Throwable) {
    }
  }

  protected function targets(): array {
    return $this->entityTypeManager->getStorage('views_path_rotator_target')->loadMultiple();
  }

  protected function nominalPath(string $id, string $routeName): ?string {
    if (!array_key_exists($id, $this->nominalPaths)) {
      try {
        $this->nominalPaths[$id] = $this->routeProvider()->getRouteByName($routeName)->getPath();
      }
      catch (RouteNotFoundException) {
        $this->nominalPaths[$id] = NULL;
      }
    }
    return $this->nominalPaths[$id];
  }

  protected function currentPath(string $id): ?string {
    $path = $this->state->get('views_path_rotator.current_path.' . $id);
    return is_string($path) && $path !== '' ? $path : NULL;
  }

}
