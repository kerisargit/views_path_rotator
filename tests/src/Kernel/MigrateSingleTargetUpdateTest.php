<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * @group views_path_rotator
 */
class MigrateSingleTargetUpdateTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'views',
    'views_path_rotator',
  ];

  protected function setUp(): void {
    parent::setUp();
    require_once $this->container->getParameter('app.root') . '/core/includes/install.inc';
    \Drupal::moduleHandler()->loadInclude('views_path_rotator', 'install');
  }

  protected function writeLegacyConfig(array $values): void {
    $this->container->get('config.storage')->write('views_path_rotator.settings', $values);
  }

  public function testMigratesConfiguredTargetWithState(): void {
    $this->writeLegacyConfig([
      'enabled' => FALSE,
      'view_id' => 'texts',
      'display_id' => 'page_1',
      'path_prefix' => '',
      'token_length' => 16,
      'interval_count' => 30,
      'interval_unit' => 'minutes',
      'trigger_on_request' => TRUE,
    ]);
    $state = $this->container->get('state');
    $state->setMultiple([
      'views_path_rotator.current_path' => '/8cda2406ccb05308',
      'views_path_rotator.previous_path' => '/texts',
      'views_path_rotator.last_rotation' => 1789963911,
    ]);

    views_path_rotator_update_10001();

    $target = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target')->load('texts_page_1');
    $this->assertNotNull($target, 'Entity created with the id "<view_id>_<display_id>".');
    $this->assertFalse($target->status());
    $this->assertSame('texts', $target->getViewId());
    $this->assertSame('page_1', $target->getDisplayId());
    $this->assertTrue($target->triggersOnRequest());
    $this->assertSame(30, $target->getIntervalCount());

    $this->assertSame('/8cda2406ccb05308', $state->get('views_path_rotator.current_path.texts_page_1'));
    $this->assertSame('/texts', $state->get('views_path_rotator.previous_path.texts_page_1'));
    $this->assertSame(1789963911, $state->get('views_path_rotator.last_rotation.texts_page_1'));

    $this->assertNull($state->get('views_path_rotator.current_path'));
    $this->assertNull($state->get('views_path_rotator.previous_path'));
    $this->assertNull($state->get('views_path_rotator.last_rotation'));

    $this->assertTrue($this->container->get('config.factory')->get('views_path_rotator.settings')->isNew());
  }

  public function testDoesNothingWhenLegacyConfigMissing(): void {
    views_path_rotator_update_10001();

    $targets = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target')->loadMultiple();
    $this->assertEmpty($targets);
  }

  public function testDeletesEmptyLegacyConfigWithoutCreatingTarget(): void {
    $this->writeLegacyConfig(['enabled' => FALSE, 'view_id' => '', 'display_id' => '']);

    views_path_rotator_update_10001();

    $targets = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target')->loadMultiple();
    $this->assertEmpty($targets, 'An empty legacy config creates no target.');
    $this->assertTrue($this->container->get('config.factory')->get('views_path_rotator.settings')->isNew());
  }

  public function testRunningTwiceDoesNotDuplicateOrError(): void {
    $this->writeLegacyConfig(['enabled' => TRUE, 'view_id' => 'texts', 'display_id' => 'page_1']);

    views_path_rotator_update_10001();
    views_path_rotator_update_10001();

    $targets = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target')->loadMultiple();
    $this->assertCount(1, $targets);
  }

}
