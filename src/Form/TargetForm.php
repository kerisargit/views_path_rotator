<?php

namespace Drupal\views_path_rotator\Form;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;
use Drupal\views_path_rotator\Service\PathRotatorManager;

class TargetForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
    $target = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $target->label(),
      '#required' => TRUE,
      '#description' => $this->t('For your own convenience, e.g. "Dictionary search". Not shown anywhere on the site.'),
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $target->id(),
      '#machine_name' => [
        'exists' => [$this, 'exists'],
        'source' => ['label'],
      ],
      '#disabled' => !$target->isNew(),
      '#description' => $this->t('Used as a namespace in State and in the tokens <code>[views_path_rotator:target_path:ID]</code>/<code>[views_path_rotator:target_url:ID]</code>. Cannot be changed after creation.'),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatic rotation enabled'),
      '#default_value' => $target->status(),
      '#description' => $this->t('While disabled, cron and regular visits leave this target alone. The "Rotate now" operation in the target list works regardless of this checkbox, so you can test the target before enabling automation.'),
    ];

    $display_options = $this->getPageDisplayOptions();
    $current_key = $target->getViewId() !== '' ? $target->getViewId() . '.' . $target->getDisplayId() : '';
    $form['target'] = [
      '#type' => 'select',
      '#title' => $this->t('View and page display to rotate'),
      '#options' => $display_options,
      '#default_value' => $current_key,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#description' => $this->t('Lists all Views with a page display that has a path.'),
    ];

    if ($current_key !== '') {
      $form['route_hint'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->withHelpLink($this->t(
          'The route name of this display, <code>@route</code>, never changes on rotation. Link menu items to <code>route:@route</code> instead of a literal path: a literal path returns 404 after the first rotation.',
          ['@route' => $target->getRouteName()]
        ), 'views_path_rotator.linking'),
        '#attributes' => ['class' => ['description']],
      ];
    }

    $form['path_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Random path format'),
      '#open' => TRUE,
    ];
    $form['path_settings']['path_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fixed prefix'),
      '#default_value' => $target->getPathPrefix(),
      '#size' => 30,
      '#description' => $this->t('Optional. For example, "search" gives a path like /search-3f9a2b7c1d4e58a0. If empty, the path is just /3f9a2b7c1d4e58a0.'),
    ];
    $form['path_settings']['token_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Random token length (hex characters)'),
      '#default_value' => $target->getTokenLength() ?: 16,
      '#min' => 4,
      '#max' => 64,
      '#description' => $this->t('16 characters (64 bits) is a sensible default. Fewer than 8 (32 bits) is not recommended: a short token can be brute-forced.'),
    ];

    $form['cron_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Rotation interval'),
      '#open' => TRUE,
    ];
    $form['cron_settings']['interval_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Rotate every'),
      '#default_value' => $target->getIntervalCount(),
      '#min' => 0,
      '#max' => 100000,
      '#description' => $this->t('0 = rotate on every cron run.'),
      '#attributes' => ['style' => 'width:100px'],
    ];
    $form['cron_settings']['interval_unit'] = [
      '#type' => 'select',
      '#title' => $this->t('Unit', [], ['context' => 'Rotation interval unit']),
      '#options' => [
        'minutes' => $this->t('minutes', [], ['context' => 'Rotation interval unit']),
        'hours' => $this->t('hours', [], ['context' => 'Rotation interval unit']),
        'days' => $this->t('days', [], ['context' => 'Rotation interval unit']),
      ],
      '#default_value' => $target->getIntervalUnit(),
    ];
    $form['cron_settings']['note'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->withHelpLink($this->t('Rotation only happens when Drupal cron actually runs. If cron runs rarely on this site, enable the option below.'), 'views_path_rotator.schedule'),
      '#attributes' => ['class' => ['description']],
    ];
    $form['cron_settings']['trigger_on_request'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also check on regular site requests'),
      '#default_value' => $target->triggersOnRequest(),
      '#description' => $this->withHelpLink($this->t('The check runs after the response has been sent, so the visitor is not delayed. Useful when cron runs less often than the rotation interval.'), 'views_path_rotator.schedule'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $target_value = (string) $form_state->getValue('target');
    if ($target_value === '') {
      $form_state->setErrorByName('target', $this->t('Select a View and display.'));
    }
    else {
      [$view_id, $display_id] = $this->parseTarget($target_value);

      $storage = $this->entityTypeManager->getStorage('views_path_rotator_target');
      foreach ($storage->loadMultiple() as $other) {
        /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $other */
        if ($other->id() === $this->entity->id()) {
          continue;
        }
        if ($other->getViewId() === $view_id && $other->getDisplayId() === $display_id) {
          $form_state->setErrorByName('target', $this->t('This View/display is already rotated by target "@label".', ['@label' => $other->label()]));
          break;
        }
      }

      $path = $this->getDisplayPath($view_id, $display_id);
      if ($path !== NULL && PathRotatorManager::pathHasArguments($path)) {
        $form_state->setErrorByName('target', $this->t(
          'This display has an argument in its path (@path). Rotation replaces the whole path with a flat random token, so the argument would be lost and the page would stop working. Choose a display without path arguments.',
          ['@path' => $path]
        ));
      }
      $front = ltrim((string) $this->config('system.site')->get('page.front'), '/');
      if ($path !== NULL && $front !== '' && ltrim($path, '/') === $front) {
        $form_state->setErrorByName('target', $this->t(
          'The nominal path of this display (as saved in the View config) is currently the site front page (/@front). The module substitutes the route path, while page.front points to the original path, so enabling rotation would leave the site without a working front page. Change the front page at /admin/config/system/site-information first, or choose another View/display.',
          ['@front' => $front]
        ));
      }
    }

    $count = (int) $form_state->getValue('interval_count');
    if ($count < 0 || $count > 100000) {
      $form_state->setErrorByName('interval_count', $this->t('The interval must be between 0 and 100000.'));
    }

    $length = (int) $form_state->getValue('token_length');
    if ($length < 4 || $length > 64) {
      $form_state->setErrorByName('token_length', $this->t('The token length must be between 4 and 64.'));
    }

    $prefix = (string) $form_state->getValue('path_prefix');
    if ($prefix !== '' && !preg_match('/^[a-z0-9_-]+$/i', $prefix)) {
      $form_state->setErrorByName('path_prefix', $this->t('The prefix may contain only Latin letters, digits, "-" and "_".'));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    assert($entity instanceof ViewsPathRotatorTargetInterface);
    $values = $form_state->getValues();
    unset($values['target']);
    foreach ($values as $key => $value) {
      $entity->set($key, $value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    [$view_id, $display_id] = $this->parseTarget((string) $form_state->getValue('target'));
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
    $target = $this->entity;
    $target->set('view_id', $view_id);
    $target->set('display_id', $display_id);

    $is_new = $target->isNew();
    $status = parent::save($form, $form_state);

    $this->messenger()->addStatus($is_new
      ? $this->t('Rotation target %label created.', ['%label' => $target->label()])
      : $this->t('Rotation target %label saved.', ['%label' => $target->label()])
    );
    $form_state->setRedirectUrl($target->toUrl('collection'));
    return $status;
  }

  protected function withHelpLink(TranslatableMarkup $text, string $topic_id): MarkupInterface {
    if (!$this->moduleHandler->moduleExists('help')) {
      return $text;
    }
    return $this->t('@text <a href=":url">More…</a>', [
      '@text' => $text,
      ':url' => Url::fromRoute('help.help_topic', ['id' => $topic_id])->toString(),
    ]);
  }

  public function exists(string $id): bool {
    return (bool) $this->entityTypeManager->getStorage('views_path_rotator_target')->load($id);
  }

  protected function getPageDisplayOptions(): array {
    $options = [];
    $storage = $this->entityTypeManager->getStorage('view');
    foreach ($storage->loadMultiple() as $view) {
      if (!$view->status()) {
        continue;
      }
      $displays = $view->get('display');
      foreach (\is_array($displays) ? $displays : [] as $display_id => $display) {
        if (!\is_array($display) || ($display['display_plugin'] ?? '') !== 'page') {
          continue;
        }
        $path = (string) ($display['display_options']['path'] ?? '');
        if ($path === '') {
          continue;
        }
        $key = $view->id() . '.' . $display_id;
        $options[$key] = $this->t('@view — @display (/@path)', [
          '@view' => $view->label(),
          '@display' => $display['display_title'] ?? $display_id,
          '@path' => $path,
        ]);
      }
    }
    return $options;
  }

  protected function parseTarget(string $target): array {
    return array_pad(explode('.', $target, 2), 2, '');
  }

  protected function getDisplayPath(string $viewId, string $displayId): ?string {
    if ($viewId === '' || $displayId === '') {
      return NULL;
    }
    $view = $this->entityTypeManager->getStorage('view')->load($viewId);
    if (!$view) {
      return NULL;
    }
    $displays = $view->get('display') ?: [];
    $path = $displays[$displayId]['display_options']['path'] ?? NULL;
    return $path !== NULL && $path !== '' ? '/' . ltrim($path, '/') : NULL;
  }

}
