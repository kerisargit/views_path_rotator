<?php

namespace Drupal\views_path_rotator\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * @ConfigEntityType(
 *   id = "views_path_rotator_target",
 *   label = @Translation("Views Path Rotator rotation target"),
 *   label_collection = @Translation("Views Path Rotator"),
 *   label_singular = @Translation("rotation target"),
 *   label_plural = @Translation("rotation targets"),
 *   label_count = @PluralTranslation(
 *     singular = "@count rotation target",
 *     plural = "@count rotation targets",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\views_path_rotator\ViewsPathRotatorTargetListBuilder",
 *     "form" = {
 *       "add" = "Drupal\views_path_rotator\Form\TargetForm",
 *       "edit" = "Drupal\views_path_rotator\Form\TargetForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   admin_permission = "administer views path rotator",
 *   config_prefix = "target",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status"
 *   },
 *   links = {
 *     "add-form" = "/admin/config/search/views-path-rotator/add",
 *     "edit-form" = "/admin/config/search/views-path-rotator/{views_path_rotator_target}",
 *     "delete-form" = "/admin/config/search/views-path-rotator/{views_path_rotator_target}/delete",
 *     "collection" = "/admin/config/search/views-path-rotator",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "status",
 *     "view_id",
 *     "display_id",
 *     "path_prefix",
 *     "token_length",
 *     "interval_count",
 *     "interval_unit",
 *     "trigger_on_request",
 *   }
 * )
 */
class ViewsPathRotatorTarget extends ConfigEntityBase implements ViewsPathRotatorTargetInterface {

  /**
   * @var string
   */
  protected $id;

  /**
   * @var string
   */
  protected $label;

  /**
   * @var string
   */
  protected $view_id = '';

  /**
   * @var string
   */
  protected $display_id = '';

  /**
   * @var string
   */
  protected $path_prefix = '';

  /**
   * @var int
   */
  protected $token_length = 16;

  /**
   * @var int
   */
  protected $interval_count = 30;

  /**
   * @var string
   */
  protected $interval_unit = 'minutes';

  /**
   * @var bool
   */
  protected $trigger_on_request = FALSE;

  /**
   * {@inheritdoc}
   */
  public function getViewId(): string {
    return $this->view_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getDisplayId(): string {
    return $this->display_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteName(): string {
    return 'view.' . $this->view_id . '.' . $this->display_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getPathPrefix(): string {
    return $this->path_prefix;
  }

  /**
   * {@inheritdoc}
   */
  public function getTokenLength(): int {
    return $this->token_length;
  }

  /**
   * {@inheritdoc}
   */
  public function getIntervalCount(): int {
    return $this->interval_count;
  }

  /**
   * {@inheritdoc}
   */
  public function getIntervalUnit(): string {
    return $this->interval_unit;
  }

  /**
   * {@inheritdoc}
   */
  public function triggersOnRequest(): bool {
    return $this->trigger_on_request;
  }

  protected function stateKeys(): array {
    $keys = [];
    foreach (['current_path', 'previous_path', 'last_rotation'] as $suffix) {
      $keys[] = 'views_path_rotator.' . $suffix . '.' . $this->id();
    }
    return $keys;
  }

  protected function resetRotation(): void {
    \Drupal::state()->deleteMultiple($this->stateKeys());
    Cache::invalidateTags(['route_match', 'views_path_rotator:target:' . $this->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);
    /** @var static $entity */
    foreach ($entities as $entity) {
      $entity->resetRotation();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);
    $display_changed = FALSE;
    // getOriginal() exists since Drupal 11.2, which deprecates ->original.
    $original = method_exists($this, 'getOriginal') ? $this->getOriginal() : ($this->original ?? NULL);
    if ($update && $original instanceof ViewsPathRotatorTargetInterface) {
      if ($original->getViewId() !== $this->view_id || $original->getDisplayId() !== $this->display_id) {
        $this->resetRotation();
        $display_changed = TRUE;
      }
    }

    if (!$update || $display_changed) {
      Cache::invalidateTags(['rendered']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    if ($this->view_id !== '') {
      $this->addDependency('config', 'views.view.' . $this->view_id);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function onDependencyRemoval(array $dependencies) {
    $changed = parent::onDependencyRemoval($dependencies);
    if ($this->view_id === '') {
      return $changed;
    }
    foreach ($dependencies['config'] ?? [] as $entity) {
      if ($entity->getConfigDependencyName() === 'views.view.' . $this->view_id) {
        $this->set('status', FALSE);
        $this->set('view_id', '');
        $this->set('display_id', '');
        return TRUE;
      }
    }
    return $changed;
  }

}
