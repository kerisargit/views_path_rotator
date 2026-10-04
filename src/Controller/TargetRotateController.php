<?php

namespace Drupal\views_path_rotator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;
use Drupal\views_path_rotator\Service\PathRotatorManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class TargetRotateController extends ControllerBase {

  public function __construct(
    protected PathRotatorManager $manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('views_path_rotator.manager'),
    );
  }

  public function rotate(ViewsPathRotatorTargetInterface $views_path_rotator_target): RedirectResponse {
    $result = $this->manager->rotate($views_path_rotator_target, 'manual');

    switch ($result['status']) {
      case 'success':
        $this->messenger()->addStatus($this->t('"@label": path rotated: @old → @new.', [
          '@label' => $views_path_rotator_target->label(),
          '@old' => $result['old_path'],
          '@new' => $result['new_path'],
        ]));
        break;

      case 'skipped':
        $this->messenger()->addWarning($this->t('"@label": rotation skipped: @reason.', [
          '@label' => $views_path_rotator_target->label(),
          '@reason' => $result['reason'],
        ]));
        break;

      default:
        $this->messenger()->addError($this->t('"@label": rotation failed: @reason.', [
          '@label' => $views_path_rotator_target->label(),
          '@reason' => $result['reason'] ?? 'unknown error',
        ]));
    }

    return new RedirectResponse($this->getRedirectDestination()->get());
  }

}
