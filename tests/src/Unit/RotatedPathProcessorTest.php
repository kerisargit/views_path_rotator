<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;
use Drupal\views_path_rotator\PathProcessor\RotatedPathProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Route;

/**
 * @group views_path_rotator
 */
class RotatedPathProcessorTest extends UnitTestCase {

  protected function target(string $id, string $routeName): ViewsPathRotatorTargetInterface {
    $target = $this->prophesize(ViewsPathRotatorTargetInterface::class);
    $target->id()->willReturn($id);
    $target->getRouteName()->willReturn($routeName);
    return $target->reveal();
  }

  protected function createProcessor(array $targets, array $nominalPaths, array $currentPaths): RotatedPathProcessor {
    $keyed = [];
    foreach ($targets as $target) {
      $keyed[$target->id()] = $target;
    }
    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->loadMultiple()->willReturn($keyed);
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $entityTypeManager->getStorage('views_path_rotator_target')->willReturn($storage->reveal());

    $routeProvider = $this->prophesize(RouteProviderInterface::class);
    foreach ($targets as $target) {
      $routeName = $target->getRouteName();
      $nominal = $nominalPaths[$target->id()] ?? NULL;
      if ($nominal === NULL) {
        $routeProvider->getRouteByName($routeName)->willThrow(new RouteNotFoundException());
      }
      else {
        $routeProvider->getRouteByName($routeName)->willReturn(new Route($nominal));
      }
    }

    $container = $this->prophesize(ContainerInterface::class);
    $container->get('router.route_provider')->willReturn($routeProvider->reveal());

    $state = $this->prophesize(StateInterface::class);
    foreach ($targets as $target) {
      $state->get('views_path_rotator.current_path.' . $target->id())->willReturn($currentPaths[$target->id()] ?? NULL);
    }

    return new RotatedPathProcessor($entityTypeManager->reveal(), $container->reveal(), $state->reveal());
  }

  public function testInboundLeavesUnrelatedPathAlone(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $processor = $this->createProcessor([$t], ['t1' => '/texts'], ['t1' => '/8cda2406ccb05308']);

    $this->assertSame('/something-else', $processor->processInbound('/something-else', Request::create('/something-else')));
  }

  public function testInboundRewritesCurrentRotatedPathToNominal(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $processor = $this->createProcessor([$t], ['t1' => '/texts'], ['t1' => '/8cda2406ccb05308']);

    $this->assertSame('/texts', $processor->processInbound('/8cda2406ccb05308', Request::create('/8cda2406ccb05308')));
  }

  public function testInboundLeavesNominalPathAloneEvenAfterRotation(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $processor = $this->createProcessor([$t], ['t1' => '/texts'], ['t1' => '/8cda2406ccb05308']);

    $this->assertSame('/texts', $processor->processInbound('/texts', Request::create('/texts')));
  }

  public function testInboundLeavesNominalPathAloneBeforeFirstRotation(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $processor = $this->createProcessor([$t], ['t1' => '/texts'], ['t1' => NULL]);

    $this->assertSame('/texts', $processor->processInbound('/texts', Request::create('/texts')));
  }

  public function testInboundSkipsTargetWithMissingRouteGracefully(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $processor = $this->createProcessor([$t], ['t1' => NULL], ['t1' => '/8cda2406ccb05308']);

    $this->assertSame('/anything', $processor->processInbound('/anything', Request::create('/anything')));
  }

  public function testInboundHandlesMultipleTargetsIndependently(): void {
    $t1 = $this->target('t1', 'view.t1.page_1');
    $t2 = $this->target('t2', 'view.t2.page_1');
    $processor = $this->createProcessor(
      [$t1, $t2],
      ['t1' => '/texts', 't2' => '/search'],
      ['t1' => '/aaaa', 't2' => '/bbbb'],
    );

    $this->assertSame('/texts', $processor->processInbound('/aaaa', Request::create('/aaaa')));
    $this->assertSame('/search', $processor->processInbound('/bbbb', Request::create('/bbbb')));
    $this->assertSame('/unrelated', $processor->processInbound('/unrelated', Request::create('/unrelated')));
  }

  public function testOutboundRewritesNominalPathToCurrentAndBubblesCacheTag(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $processor = $this->createProcessor([$t], ['t1' => '/texts'], ['t1' => '/8cda2406ccb05308']);

    $metadata = new BubbleableMetadata();
    $options = [];
    $result = $processor->processOutbound('/texts', $options, NULL, $metadata);

    $this->assertSame('/8cda2406ccb05308', $result);
    $this->assertContains('views_path_rotator:target:t1', $metadata->getCacheTags());
  }

  public function testOutboundLeavesNominalPathAloneBeforeFirstRotation(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $processor = $this->createProcessor([$t], ['t1' => '/texts'], ['t1' => NULL]);

    $metadata = new BubbleableMetadata();
    $options = [];
    $this->assertSame('/texts', $processor->processOutbound('/texts', $options, NULL, $metadata));
    $this->assertContains('views_path_rotator:target:t1', $metadata->getCacheTags());
  }

  /**
   * A broken storage (e.g. database down) must not break routing.
   */
  public function testStorageFailureLeavesPathsUntouched(): void {
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $entityTypeManager->getStorage('views_path_rotator_target')->willThrow(new \RuntimeException('db down'));
    $container = $this->prophesize(ContainerInterface::class);
    $container->get('logger.factory')->willThrow(new \RuntimeException('no logger either'));
    $processor = new RotatedPathProcessor($entityTypeManager->reveal(), $container->reveal(), $this->prophesize(StateInterface::class)->reveal());

    $options = [];
    $this->assertSame('/abc', $processor->processInbound('/abc', Request::create('/abc')));
    $this->assertSame('/texts', $processor->processOutbound('/texts', $options));
  }

  public function testOutboundLeavesUnrelatedPathAlone(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $processor = $this->createProcessor([$t], ['t1' => '/texts'], ['t1' => '/8cda2406ccb05308']);

    $options = [];
    $this->assertSame('/something-else', $processor->processOutbound('/something-else', $options));
  }

}
