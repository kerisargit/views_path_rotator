<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;
use Symfony\Component\Routing\Route;

/**
 * @group views_path_rotator
 */
class CurrentTargetConditionTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'views',
    'views_path_rotator',
  ];

  protected function createTargetEntity(string $id, string $viewId = 'search_page', string $displayId = 'page_1'): ViewsPathRotatorTargetInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
    $target = $storage->create([
      'id' => $id,
      'label' => $id,
      'view_id' => $viewId,
      'display_id' => $displayId,
    ]);
    $target->save();
    return $target;
  }

  public function testEvaluateTrueOnConfiguredRoute(): void {
    $this->createTargetEntity('t1');

    $this->container->set('current_route_match', new RouteMatch('view.search_page.page_1', new Route('/dummy')));
    $condition = $this->container->get('plugin.manager.condition')->createInstance('views_path_rotator_current_target', ['target_id' => 't1']);

    $this->assertTrue($condition->evaluate());
  }

  public function testEvaluateFalseOnOtherRoute(): void {
    $this->createTargetEntity('t1');

    $this->container->set('current_route_match', new RouteMatch('some.other.route', new Route('/dummy')));
    $condition = $this->container->get('plugin.manager.condition')->createInstance('views_path_rotator_current_target', ['target_id' => 't1']);

    $this->assertFalse($condition->evaluate());
  }

  public function testEvaluateFalseWhenNoTargetConfigured(): void {
    $this->container->set('current_route_match', new RouteMatch('view.search_page.page_1', new Route('/dummy')));
    $condition = $this->container->get('plugin.manager.condition')->createInstance('views_path_rotator_current_target');

    $this->assertFalse($condition->evaluate(), 'Without a target the condition matches nothing.');
  }

  public function testEvaluateFalseWhenConfiguredTargetDoesNotExist(): void {
    $this->container->set('current_route_match', new RouteMatch('view.search_page.page_1', new Route('/dummy')));
    $condition = $this->container->get('plugin.manager.condition')->createInstance('views_path_rotator_current_target', ['target_id' => 'does_not_exist']);

    $this->assertFalse($condition->evaluate());
  }

  public function testNegateIsAppliedByConditionManager(): void {
    $this->createTargetEntity('t1');

    $this->container->set('current_route_match', new RouteMatch('view.search_page.page_1', new Route('/dummy')));
    $condition = $this->container->get('plugin.manager.condition')
      ->createInstance('views_path_rotator_current_target', ['target_id' => 't1', 'negate' => TRUE]);

    $this->assertTrue($condition->evaluate(), 'evaluate() itself does not negate.');
    $this->assertFalse($condition->execute(), 'execute() negates because negate=TRUE.');
  }

  public function testCalculateDependenciesIncludesTargetConfig(): void {
    $target = $this->createTargetEntity('t1');

    $condition = $this->container->get('plugin.manager.condition')->createInstance('views_path_rotator_current_target', ['target_id' => 't1']);
    $dependencies = $condition->calculateDependencies();

    $this->assertContains($target->getConfigDependencyName(), $dependencies['config']);
  }

}
