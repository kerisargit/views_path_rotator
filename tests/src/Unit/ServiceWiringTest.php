<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * @group views_path_rotator
 */
class ServiceWiringTest extends UnitTestCase {

  protected function servicesDefinition(): array {
    $path = __DIR__ . '/../../../views_path_rotator.services.yml';
    return Yaml::parseFile($path)['services'];
  }

  public function testPathProcessorDoesNotEagerlyDependOnRouteProvider(): void {
    $definition = $this->servicesDefinition()['views_path_rotator.path_processor'];

    $this->assertNotContains(
      '@router.route_provider',
      $definition['arguments'],
      "views_path_rotator.path_processor must not take '@router.route_provider' as a constructor argument: the route provider depends on path_processor_manager, which collects this service, so the container fails with ServiceCircularReferenceException on every request. Resolve it lazily through '@service_container' (RotatedPathProcessor::routeProvider())."
    );
  }

  public function testPathProcessorIsTaggedForBothDirections(): void {
    $definition = $this->servicesDefinition()['views_path_rotator.path_processor'];
    $tagNames = array_column($definition['tags'], 'name');

    $this->assertContains('path_processor_inbound', $tagNames);
    $this->assertContains('path_processor_outbound', $tagNames);
  }

}
