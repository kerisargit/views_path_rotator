<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;
use Drupal\views_path_rotator\Service\PathRotatorManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group views_path_rotator
 */
class PathRotatorManagerTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'views',
    'views_path_rotator',
  ];

  protected const TEST_VIEW_ID = 'vpr_test_view';
  protected const TEST_DISPLAY_ID = 'page_1';
  protected const TEST_PATH = 'vpr-test-search';
  protected const TARGET_ID = 'test_target';

  protected PathRotatorManager $manager;
  protected ViewsPathRotatorTargetInterface $target;

  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system']);

    $this->createTestView(self::TEST_VIEW_ID, self::TEST_DISPLAY_ID, self::TEST_PATH);

    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
    $target = $storage->create([
      'id' => self::TARGET_ID,
      'label' => 'Test target',
      'status' => TRUE,
      'view_id' => self::TEST_VIEW_ID,
      'display_id' => self::TEST_DISPLAY_ID,
      'path_prefix' => '',
      'token_length' => 16,
      'interval_count' => 30,
      'interval_unit' => 'minutes',
    ]);
    $target->save();
    $this->target = $target;

    $this->manager = $this->container->get('views_path_rotator.manager');
  }

  protected function createTestView(string $id, string $displayId, string $path): View {
    $view = View::create([
      'id' => $id,
      'label' => 'VPR test view',
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
          'display_options' => [
            'path' => $path,
          ],
        ],
      ],
    ]);
    $view->save();
    $this->container->get('router.builder')->rebuild();
    return $view;
  }

  protected function reloadView(string $id): View {
    $this->container->get('entity_type.manager')->getStorage('view')->resetCache([$id]);
    return View::load($id);
  }

  public function testRotateChangesLiveRoutingButNeverSavesView(): void {
    $route_provider = $this->container->get('router.route_provider');
    $collection = $route_provider->getRouteCollectionForRequest(Request::create('/' . self::TEST_PATH));
    $this->assertCount(1, $collection);

    $result = $this->manager->rotate($this->target, 'manual');

    $this->assertSame('success', $result['status']);
    $this->assertSame('/' . self::TEST_PATH, $result['old_path']);
    $this->assertNotSame($result['old_path'], $result['new_path']);
    $this->assertMatchesRegularExpression('#^/[0-9a-f]{16}$#', $result['new_path']);

    $view = $this->reloadView(self::TEST_VIEW_ID);
    $this->assertSame(self::TEST_PATH, $view->getDisplay(self::TEST_DISPLAY_ID)['display_options']['path'], 'The nominal path in the View config stays untouched.');

    $this->assertSame('/' . self::TEST_PATH, $route_provider->getRouteByName('view.' . self::TEST_VIEW_ID . '.' . self::TEST_DISPLAY_ID)->getPath());

    $this->assertCount(1, $route_provider->getRouteCollectionForRequest(Request::create('/' . self::TEST_PATH)), 'The nominal path still resolves at the routing level.');

    $matched = $route_provider->getRouteCollectionForRequest(Request::create($result['new_path']));
    $this->assertCount(1, $matched, 'The rotated path resolves to exactly one route.');
    $this->assertSame('view.' . self::TEST_VIEW_ID . '.' . self::TEST_DISPLAY_ID, array_key_first($matched->all()), 'It is the same route, reached through another path.');

    $status = $this->manager->getStatus($this->target);
    $this->assertSame($result['new_path'], $status['current_path']);
    $this->assertSame($result['old_path'], $status['previous_path']);
  }

  public function testDisplayWithPathArgumentIsRefused(): void {
    $this->createTestView('vpr_args_view', 'page_1', 'vpr-args/%');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => 'args_t', 'label' => 'Args', 'view_id' => 'vpr_args_view', 'display_id' => 'page_1']);
    $target->save();

    $result = $this->manager->rotate($target, 'manual');

    $this->assertSame('error', $result['status']);
    $this->assertSame('display path has arguments', $result['reason']);
    $this->assertEmpty($this->manager->getStatus($target)['current_path'], 'State untouched on refusal.');
  }

  public function testFrontPageIsNeverRotated(): void {
    $this->config('system.site')->set('page.front', '/' . self::TEST_PATH)->save();

    $result = $this->manager->rotate($this->target, 'manual');

    $this->assertSame('error', $result['status']);
    $this->assertSame('target is the site front page', $result['reason']);

    $status = $this->manager->getStatus($this->target);
    $this->assertEmpty($status['current_path'], 'State untouched on refusal.');
  }

  public function testIsDueRespectsInterval(): void {
    $this->assertTrue($this->manager->isDue($this->target), 'Never rotated: due.');

    $this->manager->rotate($this->target, 'manual');
    $this->assertFalse($this->manager->isDue($this->target), 'The 30 minute interval has not passed.');

    $this->container->get('state')->set(
      'views_path_rotator.last_rotation.' . self::TARGET_ID,
      $this->container->get('datetime.time')->getRequestTime() - 1801
    );
    $this->assertTrue($this->manager->isDue($this->target), 'The interval has passed.');
  }

  public function testTwoTargetsRotatedInSameBatchNeverCollide(): void {
    $this->createTestView('vpr_test_view_2', 'page_1', 'vpr-test-search-2');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target2 */
    $target2 = $storage->create([
      'id' => 'test_target_2',
      'label' => 'Test target 2',
      'status' => TRUE,
      'view_id' => 'vpr_test_view_2',
      'display_id' => 'page_1',
      'token_length' => 16,
      'interval_count' => 30,
      'interval_unit' => 'minutes',
    ]);
    $target2->save();

    $results = $this->manager->rotateAllDue('cron');

    $this->assertSame('success', $results[self::TARGET_ID]['status']);
    $this->assertSame('success', $results['test_target_2']['status']);
    $this->assertNotSame($results[self::TARGET_ID]['new_path'], $results['test_target_2']['new_path'], 'Two targets in one batch must not get the same path.');
  }

  public function testRequirementsWarnsWhenTargetMissing(): void {
    require_once $this->container->getParameter('app.root') . '/core/includes/install.inc';
    \Drupal::moduleHandler()->loadInclude('views_path_rotator', 'install');

    $ok = views_path_rotator_requirements('runtime');
    $this->assertSame(REQUIREMENT_INFO, $ok['views_path_rotator_target']['severity']);
    $this->assertStringContainsString('only clears Drupal', (string) $ok['views_path_rotator_external_cache']['value']);

    $this->target->set('view_id', 'does_not_exist')->save();
    $missing = views_path_rotator_requirements('runtime');
    $this->assertSame(REQUIREMENT_WARNING, $missing['views_path_rotator_target']['severity']);
  }

  public function testRequirementsSilentWhenNoEnabledTargets(): void {
    require_once $this->container->getParameter('app.root') . '/core/includes/install.inc';
    \Drupal::moduleHandler()->loadInclude('views_path_rotator', 'install');

    $this->target->disable()->save();
    $this->assertSame([], views_path_rotator_requirements('runtime'));
  }

}
