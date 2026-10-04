<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;

/**
 * @group views_path_rotator
 */
class TargetFormTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'views',
    'views_path_rotator',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
  }

  protected function createTestView(string $id, string $displayId, string $path): void {
    $view = View::create([
      'id' => $id,
      'label' => $id,
      'base_table' => 'users_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Master',
          'position' => 0,
          'display_options' => [],
        ],
        $displayId => [
          'display_plugin' => 'page',
          'id' => $displayId,
          'display_title' => 'Page',
          'position' => 1,
          'display_options' => ['path' => $path],
        ],
      ],
    ]);
    $view->save();
  }

  protected function createFormObject(ViewsPathRotatorTargetInterface $entity) {
    $form_object = \Drupal::entityTypeManager()->getFormObject('views_path_rotator_target', $entity->isNew() ? 'add' : 'edit');
    $form_object->setEntity($entity);
    return $form_object;
  }

  protected function validate(ViewsPathRotatorTargetInterface $entity, array $values): FormState {
    $values += [
      'interval_count' => 30,
      'token_length' => 16,
      'path_prefix' => '',
    ];
    $form_object = $this->createFormObject($entity);
    $form = [];
    $form_state = new FormState();
    $form_state->setValues($values);
    $form_object->validateForm($form, $form_state);
    return $form_state;
  }

  public function testRejectsDuplicateViewDisplay(): void {
    $this->createTestView('view_a', 'page_1', 'path-a');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $storage->create(['id' => 'existing', 'label' => 'Existing target', 'view_id' => 'view_a', 'display_id' => 'page_1'])->save();

    $new = $storage->create([]);
    $form_state = $this->validate($new, ['target' => 'view_a.page_1']);

    $errors = $form_state->getErrors();
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('Existing target', (string) reset($errors));
  }

  public function testEditingSameTargetDoesNotFlagItselfAsDuplicate(): void {
    $this->createTestView('view_a', 'page_1', 'path-a');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => 'existing', 'label' => 'Existing target', 'view_id' => 'view_a', 'display_id' => 'page_1']);
    $target->save();

    $form_state = $this->validate($target, ['target' => 'view_a.page_1']);

    $this->assertEmpty($form_state->getErrors());
  }

  public function testDifferentDisplaysOfSameViewAreAllowed(): void {
    $view = View::create([
      'id' => 'view_multi',
      'label' => 'view_multi',
      'base_table' => 'users_field_data',
      'display' => [
        'default' => ['display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Master', 'position' => 0, 'display_options' => []],
        'page_1' => ['display_plugin' => 'page', 'id' => 'page_1', 'display_title' => 'Page 1', 'position' => 1, 'display_options' => ['path' => 'path-1']],
        'page_2' => ['display_plugin' => 'page', 'id' => 'page_2', 'display_title' => 'Page 2', 'position' => 2, 'display_options' => ['path' => 'path-2']],
      ],
    ]);
    $view->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $storage->create(['id' => 'first', 'label' => 'First', 'view_id' => 'view_multi', 'display_id' => 'page_1'])->save();

    $second = $storage->create([]);
    $form_state = $this->validate($second, ['target' => 'view_multi.page_2']);

    $this->assertEmpty($form_state->getErrors());
  }

  public function testRejectsFrontPage(): void {
    $this->createTestView('view_front', 'page_1', 'front-path');
    $this->config('system.site')->set('page.front', '/front-path')->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $new = $storage->create([]);
    $form_state = $this->validate($new, ['target' => 'view_front.page_1']);

    $this->assertNotEmpty($form_state->getErrors());
  }

  public function testRejectsDisplayWithPathArgument(): void {
    $this->createTestView('view_args', 'page_1', 'search-args/%');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $form_state = $this->validate($storage->create([]), ['target' => 'view_args.page_1']);

    $errors = $form_state->getErrors();
    $this->assertNotEmpty($errors, 'A display with a path argument cannot be rotated.');
  }

  public function testRejectsEmptyTarget(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $new = $storage->create([]);
    $form_state = $this->validate($new, ['target' => '']);

    $this->assertNotEmpty($form_state->getErrors());
  }

  /**
   * @dataProvider badPrefixProvider
   */
  public function testRejectsBadPathPrefix(string $prefix): void {
    $this->createTestView('view_prefix', 'page_1', 'path-prefix-test');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $new = $storage->create([]);
    $form_state = $this->validate($new, ['target' => 'view_prefix.page_1', 'path_prefix' => $prefix]);

    $this->assertNotEmpty($form_state->getErrors());
  }

  public static function badPrefixProvider(): array {
    return [
      'space' => ['bad prefix'],
      'slash' => ['bad/prefix'],
      'cyrillic' => ['префикс'],
      'special character' => ['prefix!'],
    ];
  }

  /**
   * @dataProvider intervalBoundsProvider
   */
  public function testRejectsIntervalOutOfBounds(int $count): void {
    $this->createTestView('view_interval', 'page_1', 'path-interval-test');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $new = $storage->create([]);
    $form_state = $this->validate($new, ['target' => 'view_interval.page_1', 'interval_count' => $count]);

    $this->assertNotEmpty($form_state->getErrors());
  }

  public static function intervalBoundsProvider(): array {
    return [
      'negative' => [-1],
      'too large' => [100001],
    ];
  }

  /**
   * @dataProvider tokenLengthBoundsProvider
   */
  public function testRejectsTokenLengthOutOfBounds(int $length): void {
    $this->createTestView('view_token', 'page_1', 'path-token-test');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $new = $storage->create([]);
    $form_state = $this->validate($new, ['target' => 'view_token.page_1', 'token_length' => $length]);

    $this->assertNotEmpty($form_state->getErrors());
  }

  public static function tokenLengthBoundsProvider(): array {
    return [
      'too short' => [3],
      'too long' => [65],
    ];
  }

  protected function submitTargetForm($form_object, FormState $form_state): void {
    $form = [];
    $form_object->validateForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }
    $form_object->submitForm($form, $form_state);
    $form_object->save($form, $form_state);
  }

  public function testFullAddSubmissionSavesAllFields(): void {
    $this->createTestView('view_full', 'page_1', 'path-full-submit-test');

    $form_object = \Drupal::entityTypeManager()->getFormObject('views_path_rotator_target', 'add');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $form_object->setEntity($storage->create([]));

    $form_state = new FormState();
    $form_state->setValues([
      'label' => 'Full Submit Target',
      'id' => 'full_submit',
      'status' => TRUE,
      'target' => 'view_full.page_1',
      'path_prefix' => 'search',
      'token_length' => 24,
      'interval_count' => 15,
      'interval_unit' => 'hours',
      'trigger_on_request' => TRUE,
    ]);
    $this->submitTargetForm($form_object, $form_state);

    $this->assertEmpty($form_state->getErrors());

    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $saved */
    $saved = $storage->load('full_submit');
    $this->assertNotNull($saved, 'validate + submit + save must have saved the entity.');
    $this->assertSame('Full Submit Target', $saved->label());
    $this->assertTrue($saved->status());
    $this->assertSame('view_full', $saved->getViewId());
    $this->assertSame('page_1', $saved->getDisplayId());
    $this->assertSame('search', $saved->getPathPrefix());
    $this->assertSame(24, $saved->getTokenLength());
    $this->assertSame(15, $saved->getIntervalCount());
    $this->assertSame('hours', $saved->getIntervalUnit());
    $this->assertTrue($saved->triggersOnRequest());
  }

  public function testFullEditSubmissionUpdatesFieldsAndKeepsId(): void {
    $this->createTestView('view_edit1', 'page_1', 'path-edit-1');
    $this->createTestView('view_edit2', 'page_1', 'path-edit-2');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
    $target = $storage->create([
      'id' => 'edit_me',
      'label' => 'Original label',
      'status' => FALSE,
      'view_id' => 'view_edit1',
      'display_id' => 'page_1',
      'interval_count' => 30,
    ]);
    $target->save();

    $form_object = \Drupal::entityTypeManager()->getFormObject('views_path_rotator_target', 'edit');
    $form_object->setEntity($storage->load('edit_me'));

    $form_state = new FormState();
    $form_state->setValues([
      'label' => 'Updated label',
      'id' => 'edit_me',
      'status' => TRUE,
      'target' => 'view_edit2.page_1',
      'path_prefix' => '',
      'token_length' => 16,
      'interval_count' => 60,
      'interval_unit' => 'minutes',
      'trigger_on_request' => FALSE,
    ]);
    $this->submitTargetForm($form_object, $form_state);

    $this->assertEmpty($form_state->getErrors());

    $storage->resetCache(['edit_me']);
    $reloaded = $storage->load('edit_me');
    $this->assertSame('edit_me', $reloaded->id(), 'The id must not change on edit.');
    $this->assertSame('Updated label', $reloaded->label());
    $this->assertTrue($reloaded->status());
    $this->assertSame('view_edit2', $reloaded->getViewId());
    $this->assertSame(60, $reloaded->getIntervalCount());
  }

  public function testAcceptsValidSubmission(): void {
    $this->createTestView('view_valid', 'page_1', 'path-valid-test');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $new = $storage->create([]);
    $form_state = $this->validate($new, [
      'target' => 'view_valid.page_1',
      'path_prefix' => 'search',
      'token_length' => 16,
      'interval_count' => 30,
    ]);

    $this->assertEmpty($form_state->getErrors());
  }

  public function testBuildsFormWithoutHelpModule(): void {
    $this->createTestView('view_build', 'page_1', 'path-build-test');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');

    $add = $this->container->get('entity.form_builder')->getForm($storage->create([]), 'add');
    $this->assertArrayHasKey('target', $add);
    $this->assertStringNotContainsString('/admin/help/topic/', (string) $add['cron_settings']['note']['#value']);

    $target = $storage->create(['id' => 'build_edit', 'label' => 'Build', 'view_id' => 'view_build', 'display_id' => 'page_1']);
    $target->save();
    $edit = $this->container->get('entity.form_builder')->getForm($target, 'edit');
    $this->assertStringContainsString('route:view.view_build.page_1', (string) $edit['route_hint']['#value']);
  }

  public function testBuildsFormWithHelpLinks(): void {
    $this->enableModules(['help']);
    $this->createTestView('view_help', 'page_1', 'path-help-test');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => 'help_edit', 'label' => 'Help', 'view_id' => 'view_help', 'display_id' => 'page_1']);
    $target->save();

    $form = $this->container->get('entity.form_builder')->getForm($target, 'edit');
    $this->assertStringContainsString('/admin/help/topic/views_path_rotator.linking', (string) $form['route_hint']['#value']);
    $this->assertStringContainsString('/admin/help/topic/views_path_rotator.schedule', (string) $form['cron_settings']['note']['#value']);
    $this->assertStringContainsString('/admin/help/topic/views_path_rotator.schedule', (string) $form['cron_settings']['trigger_on_request']['#description']);
  }

}
