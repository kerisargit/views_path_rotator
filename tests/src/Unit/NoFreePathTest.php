<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;
use Drupal\views_path_rotator\Service\PathRotatorManager;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * @group views_path_rotator
 */
class NoFreePathTest extends UnitTestCase {

  public function testAllCandidatesTakenReturnsPredictableError(): void {
    $target = $this->prophesize(ViewsPathRotatorTargetInterface::class);
    $target->id()->willReturn('t');
    $target->getViewId()->willReturn('vpr_view');
    $target->getDisplayId()->willReturn('page_1');
    $target->getPathPrefix()->willReturn('');
    $target->getTokenLength()->willReturn(16);

    $systemSiteConfig = $this->prophesize(ImmutableConfig::class);
    $systemSiteConfig->get('page.front')->willReturn('/some-other-front-page');
    $configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $configFactory->get('system.site')->willReturn($systemSiteConfig->reveal());

    $view = new class {
      public function getDisplay($display_id) {
        return ['display_options' => ['path' => 'old-search-path']];
      }
    };
    $viewStorage = $this->prophesize(EntityStorageInterface::class);
    $viewStorage->load('vpr_view')->willReturn($view);

    $targetStorage = $this->prophesize(EntityStorageInterface::class);
    $targetStorage->loadMultiple()->willReturn([$target->reveal()]);

    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $entityTypeManager->getStorage('view')->willReturn($viewStorage->reveal());
    $entityTypeManager->getStorage('views_path_rotator_target')->willReturn($targetStorage->reveal());

    $routeProvider = $this->prophesize(RouteProviderInterface::class);
    $takenCollection = new RouteCollection();
    $takenCollection->add('some.other.route', new Route('/whatever'));
    $routeProvider->getRoutesByPattern(Argument::type('string'))->willReturn($takenCollection);

    $lock = $this->prophesize(LockBackendInterface::class);
    $lock->acquire(Argument::any(), Argument::any())->willReturn(TRUE);
    $lock->release(Argument::any())->shouldBeCalled();

    $state = $this->prophesize(StateInterface::class);
    $state->get('views_path_rotator.current_path.t')->willReturn(NULL);

    $logger = $this->prophesize(LoggerInterface::class);
    $logger->error(Argument::any(), Argument::any())->shouldBeCalled();
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('views_path_rotator')->willReturn($logger->reveal());

    $time = $this->prophesize(TimeInterface::class);
    $time->getRequestTime()->willReturn(1000);

    $manager = new PathRotatorManager(
      $configFactory->reveal(),
      $state->reveal(),
      $lock->reveal(),
      $entityTypeManager->reveal(),
      $routeProvider->reveal(),
      $loggerFactory->reveal(),
      $time->reveal(),
    );

    $result = $manager->rotate($target->reveal(), 'manual');

    $this->assertSame('error', $result['status']);
    $this->assertSame('no free path found', $result['reason']);
  }

}
