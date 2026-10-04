<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group views_path_rotator
 */
class RotatedPathProcessorIntegrationTest extends KernelTestBase {

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

  public function testUrlFromRouteGeneratesCurrentRotatedPath(): void {
    $this->createTestView('rpp_view', 'rpp-nominal');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => 'rpp_t', 'label' => 'RPP', 'view_id' => 'rpp_view', 'display_id' => 'page_1']);
    $target->save();

    $this->assertSame('/rpp-nominal', Url::fromRoute('view.rpp_view.page_1')->toString());

    $result = $this->container->get('views_path_rotator.manager')->rotate($target, 'manual');
    $this->assertSame('success', $result['status']);

    $this->assertSame($result['new_path'], Url::fromRoute('view.rpp_view.page_1')->toString());
  }

  public function testPathValidatorAcceptsBothRotatedAndNominalPaths(): void {
    $this->createTestView('rpp_view2', 'rpp-nominal-2');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => 'rpp_t2', 'label' => 'RPP2', 'view_id' => 'rpp_view2', 'display_id' => 'page_1']);
    $target->save();
    $result = $this->container->get('views_path_rotator.manager')->rotate($target, 'manual');

    $validator = $this->container->get('path.validator');

    $rotatedUrl = $validator->getUrlIfValidWithoutAccessCheck($result['new_path']);
    $this->assertNotFalse($rotatedUrl);
    $this->assertSame('view.rpp_view2.page_1', $rotatedUrl->getRouteName());

    $nominalUrl = $validator->getUrlIfValidWithoutAccessCheck('/rpp-nominal-2');
    $this->assertNotFalse($nominalUrl);
    $this->assertSame('view.rpp_view2.page_1', $nominalUrl->getRouteName());
  }

  public function testHttpRequestBlocksDirectNominalPathButAllowsRotatedPath(): void {
    $this->createTestView('rpp_view3', 'rpp-nominal-3');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => 'rpp_t3', 'label' => 'RPP3', 'view_id' => 'rpp_view3', 'display_id' => 'page_1']);
    $target->save();

    $before = $this->container->get('http_kernel')->handle(Request::create('/rpp-nominal-3'));
    $this->assertNotSame(404, $before->getStatusCode(), 'Before rotation the nominal path is not blocked.');

    $result = $this->container->get('views_path_rotator.manager')->rotate($target, 'manual');
    $this->assertSame('success', $result['status']);

    $nominal = $this->container->get('http_kernel')->handle(Request::create('/rpp-nominal-3'));
    $this->assertSame(404, $nominal->getStatusCode(), 'After rotation the nominal path returns 404.');

    $rotated = $this->container->get('http_kernel')->handle(Request::create($result['new_path']));
    $this->assertNotSame(404, $rotated->getStatusCode(), 'The current rotated path keeps working.');
  }

  protected function renderCachedLink(string $route): string {
    $build = [
      '#type' => 'link',
      '#title' => 'link',
      '#url' => Url::fromRoute($route),
      '#cache' => ['keys' => ['vpr_test_link', $route]],
    ];
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  public function testCachedLinkRenderedBeforeFirstRotationUpdatesAfterRotation(): void {
    $this->createTestView('rc_view', 'rc-nominal');
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => 'rc_t', 'label' => 'RC', 'view_id' => 'rc_view', 'display_id' => 'page_1']);
    $target->save();

    $this->assertStringContainsString('href="/rc-nominal"', $this->renderCachedLink('view.rc_view.page_1'));

    $result = $this->container->get('views_path_rotator.manager')->rotate($target, 'manual');
    $this->assertStringContainsString('href="' . $result['new_path'] . '"', $this->renderCachedLink('view.rc_view.page_1'));
  }

  public function testCachedLinkRenderedBeforeTargetExistedUpdatesAfterRotation(): void {
    $this->createTestView('rc_view2', 'rc-nominal-2');
    $this->assertStringContainsString('href="/rc-nominal-2"', $this->renderCachedLink('view.rc_view2.page_1'));

    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    $target = $storage->create(['id' => 'rc_t2', 'label' => 'RC2', 'view_id' => 'rc_view2', 'display_id' => 'page_1']);
    $target->save();
    $result = $this->container->get('views_path_rotator.manager')->rotate($target, 'manual');

    $this->assertStringContainsString('href="' . $result['new_path'] . '"', $this->renderCachedLink('view.rc_view2.page_1'));
  }

}
