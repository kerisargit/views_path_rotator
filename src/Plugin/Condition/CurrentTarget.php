<?php

namespace Drupal\views_path_rotator\Plugin\Condition;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Condition\Attribute\Condition;
use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Condition(
  id: "views_path_rotator_current_target",
  label: new TranslatableMarkup("Views Path Rotator: current rotating page"),
)]
class CurrentTarget extends ConditionPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['target_id' => ''] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $options = [];
    foreach ($this->targetStorage()->loadMultiple() as $target) {
      /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
      $options[$target->id()] = $target->label();
    }

    $form['target_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Rotation target'),
      '#options' => $options,
      '#default_value' => $this->configuration['target_id'],
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#description' => $this->t('Targets are configured on the <a href=":url">Views Path Rotator page</a>.', [
        ':url' => Url::fromRoute('entity.views_path_rotator_target.collection')->toString(),
      ]),
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['target_id'] = $form_state->getValue('target_id');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function summary() {
    $target = $this->getTarget();
    if (!$target) {
      return $this->t('Views Path Rotator is not configured (no rotation target selected)');
    }
    return !empty($this->configuration['negate'])
      ? $this->t('Not the current rotating page (@label)', ['@label' => $target->label()])
      : $this->t('Current rotating page (@label)', ['@label' => $target->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    $target = $this->getTarget();
    if (!$target) {
      return FALSE;
    }
    return $this->routeMatch->getRouteName() === $target->getRouteName();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    $contexts = parent::getCacheContexts();
    $contexts[] = 'route.name';
    return $contexts;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $target = $this->getTarget();
    return Cache::mergeTags(
      parent::getCacheTags(),
      $target ? $target->getCacheTags() : []
    );
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    $target = $this->getTarget();
    if ($target) {
      $dependencies['config'][] = $target->getConfigDependencyName();
    }
    return $dependencies;
  }

  protected function targetStorage() {
    return $this->entityTypeManager->getStorage('views_path_rotator_target');
  }

  protected function getTarget(): ?ViewsPathRotatorTargetInterface {
    $target_id = (string) ($this->configuration['target_id'] ?? '');
    if ($target_id === '') {
      return NULL;
    }
    // Evaluated on every page with such a block: a failed load counts as "no
    // target" (condition false) rather than an error on the page.
    try {
      return $this->targetStorage()->load($target_id);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
