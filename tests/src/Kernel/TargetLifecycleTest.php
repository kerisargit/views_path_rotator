<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group views_path_rotator
 */
class TargetLifecycleTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'views',
    'views_path_rotator',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('user');
  }

  protected function createTestView(string $id, string $path): void {
    View::create([
      'id' => $id,
      'label' => $id,
      'base_table' => 'users_field_data',
      'display' => [
        'default' => ['display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Master', 'position' => 0, 'display_options' => []],
        'page_1' => ['display_plugin' => 'page', 'id' => 'page_1', 'display_title' => 'Page', 'position' => 1, 'display_options' => ['path' => $path]],
      ],
    ])->save();
    $this->container->get('router.builder')->rebuild();
  }

  protected function createRotatedTarget(string $id, string $viewId) {
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => $id, 'label' => $id, 'view_id' => $viewId, 'display_id' => 'page_1']);
    $target->save();
    $this->assertSame('success', $this->container->get('views_path_rotator.manager')->rotate($target, 'manual')['status']);
    return $target;
  }

  protected function pathResolves(string $path): bool {
    $response = $this->container->get('http_kernel')->handle(Request::create($path));
    return $response->getStatusCode() !== 404;
  }

  public function testDeletingTargetClearsItsState(): void {
    $this->createTestView('lc_view_1', 'lc-nominal-1');
    $target = $this->createRotatedTarget('lc1', 'lc_view_1');
    $state = $this->container->get('state');
    $this->assertNotEmpty($state->get('views_path_rotator.current_path.lc1'));

    $target->delete();

    foreach (['current_path', 'previous_path', 'last_rotation'] as $suffix) {
      $this->assertNull($state->get('views_path_rotator.' . $suffix . '.lc1'), "State-ключ $suffix не должен пережить цель.");
    }
  }

  public function testRecreatingDeletedTargetDoesNotResurrectOldPath(): void {
    $this->createTestView('lc_view_2', 'lc-nominal-2');
    $target = $this->createRotatedTarget('lc2', 'lc_view_2');
    $old = $this->container->get('state')->get('views_path_rotator.current_path.lc2');
    $target->delete();

    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $again = $storage->create(['id' => 'lc2', 'label' => 'lc2', 'view_id' => 'lc_view_2', 'display_id' => 'page_1']);
    $again->save();

    $manager = $this->container->get('views_path_rotator.manager');
    $status = $manager->getStatus($again);
    $this->assertEmpty($status['current_path'], "Новая цель с тем же id не должна унаследовать $old.");
    $this->assertTrue($manager->isDue($again), 'And must not count as just rotated.');
  }

  public function testDeletingTargetRevertsToNominalPath(): void {
    $this->createTestView('lc_view_3', 'lc-nominal-3');
    $target = $this->createRotatedTarget('lc3', 'lc_view_3');
    $this->assertFalse($this->pathResolves('/lc-nominal-3'));

    $target->delete();

    $this->assertTrue($this->pathResolves('/lc-nominal-3'), 'After deleting the target the nominal path resolves again.');
  }

  public function testChangingTargetViewResetsState(): void {
    $this->createTestView('lc_view_4a', 'lc-nominal-4a');
    $this->createTestView('lc_view_4b', 'lc-nominal-4b');
    $target = $this->createRotatedTarget('lc4', 'lc_view_4a');
    $state = $this->container->get('state');
    $this->assertNotEmpty($state->get('views_path_rotator.current_path.lc4'));
    $this->assertFalse($this->pathResolves('/lc-nominal-4a'), 'Before the switch the old View\'s nominal path returns 404.');

    $target->set('view_id', 'lc_view_4b');
    $target->save();

    $this->assertNull($state->get('views_path_rotator.current_path.lc4'), 'A path rotated for another View must not apply to the new one.');
    $this->assertNull($state->get('views_path_rotator.last_rotation.lc4'));

    $this->assertTrue($this->pathResolves('/lc-nominal-4a'), 'The target no longer points to it: nothing to block.');
    $this->assertTrue($this->pathResolves('/lc-nominal-4b'), 'The new View has not rotated yet and works as is.');
  }

  public function testEditingOtherFieldsKeepsState(): void {
    $this->createTestView('lc_view_5', 'lc-nominal-5');
    $target = $this->createRotatedTarget('lc5', 'lc_view_5');
    $state = $this->container->get('state');
    $before = $state->get('views_path_rotator.current_path.lc5');

    $target->set('interval_count', 99);
    $target->set('label', 'Renamed');
    $target->set('status', FALSE);
    $target->save();

    $this->assertSame($before, $state->get('views_path_rotator.current_path.lc5'), 'Editing interval/label/status must not reset the current path.');
    $this->assertNotEmpty($state->get('views_path_rotator.last_rotation.lc5'));
  }

}
