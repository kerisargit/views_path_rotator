<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\Core\Render\RenderContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views_path_rotator\Controller\TargetRotateController;

/**
 * @group views_path_rotator
 */
class AdminUiTest extends KernelTestBase {

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
    $this->container->get('router.builder')->rebuild();
  }

  protected function renderListBuilder(): string {
    $renderer = $this->container->get('renderer');
    $context = new RenderContext();
    $build = $renderer->executeInRenderContext($context, function () {
      return $this->container->get('entity_type.manager')->getListBuilder('views_path_rotator_target')->render();
    });
    return (string) $renderer->renderRoot($build);
  }

  public function testListBuilderRenderArrayCarriesPerTargetStateCacheTag(): void {
    $this->createTestView('view_cache_tag', 'page_1', 'cache-tag-test-path');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create([
      'id' => 't_cache',
      'label' => 'Target Cache',
      'status' => TRUE,
      'view_id' => 'view_cache_tag',
      'display_id' => 'page_1',
    ]);
    $target->save();

    $build = $this->container->get('entity_type.manager')->getListBuilder('views_path_rotator_target')->render();

    $this->assertContains('views_path_rotator:target:t_cache', $build['table']['#cache']['tags']);
  }

  public function testRotateNowOperationLinkIncludesDestination(): void {
    $this->createTestView('view_dest', 'page_1', 'dest-test-path');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create([
      'id' => 't_dest',
      'label' => 'Target Dest',
      'status' => TRUE,
      'view_id' => 'view_dest',
      'display_id' => 'page_1',
    ]);
    $target->save();

    $list_builder = $this->container->get('entity_type.manager')->getListBuilder('views_path_rotator_target');
    $operations = $list_builder->getOperations($target);

    $this->assertArrayHasKey('rotate_now', $operations);
    $query = $operations['rotate_now']['url']->getOption('query');
    $this->assertArrayHasKey('destination', $query ?? [], 'The operation link must carry ?destination=, otherwise the redirect lands on the rotate route.');
  }

  public function testListBuilderRendersLabelAndStatus(): void {
    $this->createTestView('view_list', 'page_1', 'list-test-path');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $storage->create([
      'id' => 't1',
      'label' => 'Target One',
      'status' => TRUE,
      'view_id' => 'view_list',
      'display_id' => 'page_1',
    ])->save();

    $html = $this->renderListBuilder();

    $this->assertStringContainsString('Target One', $html);
    $this->assertStringContainsString('view_list', $html);
  }

  public function testListBuilderShowsCurrentPathAfterRotation(): void {
    $this->createTestView('view_list2', 'page_1', 'list-test-path-2');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
    $target = $storage->create([
      'id' => 't2',
      'label' => 'Target Two',
      'status' => TRUE,
      'view_id' => 'view_list2',
      'display_id' => 'page_1',
    ]);
    $target->save();

    $result = $this->container->get('views_path_rotator.manager')->rotate($target, 'manual');
    $this->assertSame('success', $result['status']);

    $html = $this->renderListBuilder();
    $this->assertStringContainsString($result['new_path'], $html);
  }

  public function testListBuilderIsEmptyWhenNoTargets(): void {
    $html = $this->renderListBuilder();
    $this->assertStringContainsString('No rotation targets yet', $html);
  }

  public function testRotateControllerReportsSuccess(): void {
    $this->createTestView('view_ctrl', 'page_1', 'ctrl-test-path');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
    $target = $storage->create([
      'id' => 'ctrl_ok',
      'label' => 'Ctrl OK',
      'status' => FALSE,
      'view_id' => 'view_ctrl',
      'display_id' => 'page_1',
    ]);
    $target->save();

    $controller = TargetRotateController::create($this->container);
    $controller->rotate($target);

    $messages = $this->container->get('messenger')->all();
    $this->assertArrayHasKey('status', $messages, 'A successful rotation shows a status message.');
    $this->assertStringContainsString('Ctrl OK', (string) reset($messages['status']));
  }

  public function testRotateControllerReportsErrorForBrokenTarget(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
    $target = $storage->create([
      'id' => 'ctrl_broken',
      'label' => 'Ctrl Broken',
      'status' => FALSE,
      'view_id' => 'does_not_exist',
      'display_id' => 'page_1',
    ]);
    $target->save();

    $controller = TargetRotateController::create($this->container);
    $controller->rotate($target);

    $messages = $this->container->get('messenger')->all();
    $this->assertArrayHasKey('error', $messages);
    $this->assertStringContainsString('Ctrl Broken', (string) reset($messages['error']));
  }

}
