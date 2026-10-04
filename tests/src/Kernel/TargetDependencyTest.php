<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;

/**
 * @group views_path_rotator
 */
class TargetDependencyTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'views',
    'views_path_rotator',
  ];

  protected function createTestView(string $id): void {
    $view = View::create([
      'id' => $id,
      'label' => $id,
      'base_table' => 'users_field_data',
      'display' => [
        'default' => ['display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Master', 'position' => 0, 'display_options' => []],
        'page_1' => ['display_plugin' => 'page', 'id' => 'page_1', 'display_title' => 'Page', 'position' => 1, 'display_options' => ['path' => 'dep-test-path']],
      ],
    ]);
    $view->save();
  }

  public function testCalculateDependenciesIncludesTheView(): void {
    $this->createTestView('dep_view');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => 't', 'label' => 'T', 'view_id' => 'dep_view', 'display_id' => 'page_1']);

    $target->calculateDependencies();

    $this->assertContains('views.view.dep_view', $target->getDependencies()['config'] ?? []);
  }

  public function testTargetWithoutViewHasNoConfigDependency(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => 't', 'label' => 'T']);

    $target->calculateDependencies();

    $this->assertArrayNotHasKey('config', $target->getDependencies());
  }

  public function testDeletingTheViewIsReportedAsAffectingTheTarget(): void {
    $this->createTestView('dep_view_2');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => 't2', 'label' => 'T2', 'status' => TRUE, 'view_id' => 'dep_view_2', 'display_id' => 'page_1']);
    $target->save();

    $affected = $this->container->get('config.manager')->getConfigEntitiesToChangeOnDependencyRemoval('config', ['views.view.dep_view_2']);

    $update_ids = array_map(fn($entity) => $entity->id(), $affected['update']);
    $delete_ids = array_map(fn($entity) => $entity->id(), $affected['delete']);
    $this->assertContains('t2', $update_ids, 'The target is listed as affected when its View is deleted.');
    $this->assertNotContains('t2', $delete_ids, 'The target is not deleted with the View; it fixes itself in onDependencyRemoval().');
  }

  public function testOnDependencyRemovalDisablesAndClearsTargetWithoutDeletingIt(): void {
    $this->createTestView('dep_view_3');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
    $target = $storage->create([
      'id' => 't3',
      'label' => 'T3',
      'status' => TRUE,
      'view_id' => 'dep_view_3',
      'display_id' => 'page_1',
      'interval_count' => 45,
    ]);
    $target->save();

    $view = View::load('dep_view_3');
    $changed = $target->onDependencyRemoval(['config' => [$view]]);
    $this->assertTrue($changed);
    $target->save();

    $reloaded = $storage->load('t3');
    $this->assertFalse($reloaded->status(), 'Automatic rotation is disabled for a target whose View is gone.');
    $this->assertSame('', $reloaded->getViewId());
    $this->assertSame('', $reloaded->getDisplayId());
    $this->assertSame(45, $reloaded->getIntervalCount());
  }

  public function testOnDependencyRemovalIgnoresUnrelatedViews(): void {
    $this->createTestView('dep_view_unrelated');
    $this->createTestView('dep_view_4');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
    $target = $storage->create(['id' => 't4', 'label' => 'T4', 'status' => TRUE, 'view_id' => 'dep_view_4', 'display_id' => 'page_1']);
    $target->save();

    $unrelated_view = View::load('dep_view_unrelated');
    $changed = $target->onDependencyRemoval(['config' => [$unrelated_view]]);

    $this->assertFalse($changed);
    $this->assertSame('dep_view_4', $target->getViewId(), 'Deleting an unrelated View does not touch the target.');
  }

}
