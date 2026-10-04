<?php

namespace Drupal\views_path_rotator\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

class NominalPathAccessSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onKernelRequest', 30]];
  }

  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    try {
      $this->blockNominalPath($event->getRequest());
    }
    catch (NotFoundHttpException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      // Fail open: a broken lookup must not take the page down with it.
      try {
        \Drupal::logger('views_path_rotator')->error('Nominal path check skipped: @class: @message', [
          '@class' => \get_class($e),
          '@message' => $e->getMessage(),
        ]);
      }
      catch (\Throwable) {
      }
    }
  }

  protected function blockNominalPath(Request $request): void {
    $routeName = $request->attributes->get('_route');
    if ($routeName === NULL) {
      return;
    }

    foreach ($this->targets() as $id => $target) {
      if ($target->getRouteName() !== $routeName) {
        continue;
      }

      $current = $this->state->get('views_path_rotator.current_path.' . $id);
      if (!is_string($current) || $current === '') {
        return;
      }

      // Suffix match: other inbound processors (language prefix) and the
      // router (trailing slash) accept variants of the rotated path too.
      if (!str_ends_with(rtrim($request->getPathInfo(), '/'), $current)) {
        throw new NotFoundHttpException();
      }

      return;
    }
  }

  protected function targets(): array {
    return $this->entityTypeManager->getStorage('views_path_rotator_target')->loadMultiple();
  }

}
