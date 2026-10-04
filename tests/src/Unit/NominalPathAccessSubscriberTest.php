<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;
use Drupal\views_path_rotator\EventSubscriber\NominalPathAccessSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @group views_path_rotator
 */
class NominalPathAccessSubscriberTest extends UnitTestCase {

  protected function target(string $id, string $routeName): ViewsPathRotatorTargetInterface {
    $target = $this->prophesize(ViewsPathRotatorTargetInterface::class);
    $target->id()->willReturn($id);
    $target->getRouteName()->willReturn($routeName);
    return $target->reveal();
  }

  protected function createSubscriber(array $targets, array $currentPaths): NominalPathAccessSubscriber {
    $keyed = [];
    foreach ($targets as $target) {
      $keyed[$target->id()] = $target;
    }
    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->loadMultiple()->willReturn($keyed);
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $entityTypeManager->getStorage('views_path_rotator_target')->willReturn($storage->reveal());

    $state = $this->prophesize(StateInterface::class);
    foreach ($targets as $target) {
      $state->get('views_path_rotator.current_path.' . $target->id())->willReturn($currentPaths[$target->id()] ?? NULL);
    }

    return new NominalPathAccessSubscriber($entityTypeManager->reveal(), $state->reveal());
  }

  protected function requestEvent(string $path, ?string $routeName, bool $isMain = TRUE): RequestEvent {
    $request = Request::create($path);
    if ($routeName !== NULL) {
      $request->attributes->set('_route', $routeName);
    }
    $kernel = $this->prophesize(HttpKernelInterface::class)->reveal();
    $type = $isMain ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST;
    return new RequestEvent($kernel, $request, $type);
  }

  public function testBlocksDirectNominalPathAccessAfterRotation(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $subscriber = $this->createSubscriber([$t], ['t1' => '/8cda2406ccb05308']);

    $this->expectException(NotFoundHttpException::class);
    $subscriber->onKernelRequest($this->requestEvent('/texts', 'view.t1.page_1'));
  }

  public function testAllowsCurrentRotatedPathAccess(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $subscriber = $this->createSubscriber([$t], ['t1' => '/8cda2406ccb05308']);

    $subscriber->onKernelRequest($this->requestEvent('/8cda2406ccb05308', 'view.t1.page_1'));
    $this->addToAssertionCount(1);
  }

  /**
   * @dataProvider provideRotatedPathVariants
   */
  public function testAllowsRotatedPathVariantsThatRoutingAccepts(string $rawPath): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $subscriber = $this->createSubscriber([$t], ['t1' => '/8cda2406ccb05308']);

    $subscriber->onKernelRequest($this->requestEvent($rawPath, 'view.t1.page_1'));
    $this->addToAssertionCount(1);
  }

  public static function provideRotatedPathVariants(): array {
    return [
      'trailing slash' => ['/8cda2406ccb05308/'],
      'language prefix' => ['/ru/8cda2406ccb05308'],
    ];
  }

  public function testBlocksNominalPathUnderLanguagePrefix(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $subscriber = $this->createSubscriber([$t], ['t1' => '/8cda2406ccb05308']);

    $this->expectException(NotFoundHttpException::class);
    $subscriber->onKernelRequest($this->requestEvent('/ru/texts', 'view.t1.page_1'));
  }

  public function testStorageFailureBlocksNothing(): void {
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $entityTypeManager->getStorage('views_path_rotator_target')->willThrow(new \RuntimeException('db down'));
    $subscriber = new NominalPathAccessSubscriber($entityTypeManager->reveal(), $this->prophesize(StateInterface::class)->reveal());

    $subscriber->onKernelRequest($this->requestEvent('/texts', 'view.t1.page_1'));
    $this->addToAssertionCount(1);
  }

  public function testAllowsNominalPathBeforeFirstRotation(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $subscriber = $this->createSubscriber([$t], ['t1' => NULL]);

    $subscriber->onKernelRequest($this->requestEvent('/texts', 'view.t1.page_1'));
    $this->addToAssertionCount(1);
  }

  public function testIgnoresUnrelatedRoutes(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $subscriber = $this->createSubscriber([$t], ['t1' => '/8cda2406ccb05308']);

    $subscriber->onKernelRequest($this->requestEvent('/some/other/page', 'some.other.route'));
    $this->addToAssertionCount(1);
  }

  public function testIgnoresRequestsWithNoResolvedRoute(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $subscriber = $this->createSubscriber([$t], ['t1' => '/8cda2406ccb05308']);

    $subscriber->onKernelRequest($this->requestEvent('/texts', NULL));
    $this->addToAssertionCount(1);
  }

  public function testIgnoresSubRequests(): void {
    $t = $this->target('t1', 'view.t1.page_1');
    $subscriber = $this->createSubscriber([$t], ['t1' => '/8cda2406ccb05308']);

    $subscriber->onKernelRequest($this->requestEvent('/texts', 'view.t1.page_1', isMain: FALSE));
    $this->addToAssertionCount(1);
  }

}
