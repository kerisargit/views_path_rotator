<?php

namespace Drupal\views_path_rotator;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Drupal\views_path_rotator\Service\PathRotatorManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ViewsPathRotatorTargetListBuilder extends ConfigEntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected PathRotatorManager $manager,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('views_path_rotator.manager'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Label');
    $header['target'] = $this->t('View : display');
    $header['status'] = $this->t('Automatic rotation');
    $header['current_path'] = $this->t('Current path');
    $header['next_due'] = $this->t('Next rotation');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $entity */
    $row['label'] = $entity->label();
    $row['target'] = $entity->getViewId() . ' : ' . $entity->getDisplayId();
    $row['status'] = $entity->status() ? $this->t('Enabled', [], ['context' => 'Rotation status']) : $this->t('Disabled', [], ['context' => 'Rotation status']);

    $status = $this->manager->getStatus($entity);
    $row['current_path'] = $status['current_path'] ?: $this->t('— not rotated yet —');

    if (!$entity->status()) {
      $row['next_due'] = $this->t('only manually');
    }
    elseif (empty($status['current_path'])) {
      $row['next_due'] = $this->t('on the next cron run or visit');
    }
    elseif (!empty($status['next_due']) && $status['next_due'] > $this->time->getRequestTime()) {
      $row['next_due'] = $this->dateFormatter->format($status['next_due'], 'short');
    }
    else {
      $row['next_due'] = $this->t('at the nearest cron run or visit');
    }

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $entity */
    $operations['rotate_now'] = [
      'title' => $this->t('Rotate now'),
      'weight' => 15,
      'url' => $this->ensureDestination(Url::fromRoute('views_path_rotator.target_rotate', [
        'views_path_rotator_target' => $entity->id(),
      ])),
    ];
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No rotation targets yet. Click "Add rotation target" to choose a View and page display.');

    $tags = $build['table']['#cache']['tags'] ?? [];
    foreach ($this->load() as $entity) {
      $tags[] = 'views_path_rotator:target:' . $entity->id();
    }
    $build['table']['#cache']['tags'] = Cache::mergeTags($tags, []);

    return $build;
  }

}
