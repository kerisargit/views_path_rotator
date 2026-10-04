<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;

/**
 * @group views_path_rotator
 */
class UninstallCleanupTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'views',
    'views_path_rotator',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    require_once $this->container->getParameter('app.root') . '/core/includes/install.inc';
    \Drupal::moduleHandler()->loadInclude('views_path_rotator', 'install');
  }

  protected function createTestView(string $id, string $path): void {
    $view = View::create([
      'id' => $id,
      'label' => $id,
      'base_table' => 'users_field_data',
      'display' => [
        'default' => ['display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Master', 'position' => 0, 'display_options' => []],
        'page_1' => ['display_plugin' => 'page', 'id' => 'page_1', 'display_title' => 'Page', 'position' => 1, 'display_options' => ['path' => $path]],
      ],
    ]);
    $view->save();
  }

  public function testDeletesStateForAllExistingTargets(): void {
    $this->createTestView('view_u1', 'uninstall-test-1');
    $this->createTestView('view_u2', 'uninstall-test-2');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $t1 = $storage->create(['id' => 'u1', 'label' => 'U1', 'view_id' => 'view_u1', 'display_id' => 'page_1']);
    $t1->save();
    $t2 = $storage->create(['id' => 'u2', 'label' => 'U2', 'view_id' => 'view_u2', 'display_id' => 'page_1']);
    $t2->save();

    $manager = $this->container->get('views_path_rotator.manager');
    $manager->rotate($t1, 'manual');
    $manager->rotate($t2, 'manual');

    $state = $this->container->get('state');
    $this->assertNotEmpty($state->get('views_path_rotator.current_path.u1'));
    $this->assertNotEmpty($state->get('views_path_rotator.current_path.u2'));

    views_path_rotator_uninstall();

    foreach (['u1', 'u2'] as $id) {
      $this->assertNull($state->get('views_path_rotator.current_path.' . $id));
      $this->assertNull($state->get('views_path_rotator.previous_path.' . $id));
      $this->assertNull($state->get('views_path_rotator.last_rotation.' . $id));
    }
  }

  public function testAlsoDeletesLegacyUnnamespacedKeys(): void {
    $state = $this->container->get('state');
    $state->setMultiple([
      'views_path_rotator.current_path' => '/legacy-path',
      'views_path_rotator.previous_path' => '/legacy-previous',
      'views_path_rotator.last_rotation' => 12345,
    ]);

    views_path_rotator_uninstall();

    $this->assertNull($state->get('views_path_rotator.current_path'));
    $this->assertNull($state->get('views_path_rotator.previous_path'));
    $this->assertNull($state->get('views_path_rotator.last_rotation'));
  }

  public function testDoesNotErrorWithNoTargets(): void {
    views_path_rotator_uninstall();
    $this->addToAssertionCount(1);
  }

}
