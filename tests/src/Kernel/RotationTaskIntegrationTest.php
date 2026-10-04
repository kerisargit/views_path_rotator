<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @group views_path_rotator
 */
class RotationTaskIntegrationTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'views',
    'interval_trigger',
    'views_path_rotator',
  ];

  protected function createTestView(string $id, string $path): void {
    $view = View::create([
      'id' => $id,
      'label' => 'RotationTask integration test view',
      'base_table' => 'users_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Master',
          'position' => 0,
          'display_options' => [],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_title' => 'Page',
          'position' => 1,
          'display_options' => ['path' => $path],
        ],
      ],
    ]);
    $view->save();
  }

  protected function createTargetEntity(string $id, string $viewId, bool $status = TRUE, bool $triggerOnRequest = FALSE): ViewsPathRotatorTargetInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target');
    /** @var \Drupal\views_path_rotator\Entity\ViewsPathRotatorTargetInterface $target */
    $target = $storage->create([
      'id' => $id,
      'label' => $id,
      'status' => $status,
      'view_id' => $viewId,
      'display_id' => 'page_1',
      'interval_count' => 30,
      'interval_unit' => 'minutes',
      'trigger_on_request' => $triggerOnRequest,
    ]);
    $target->save();
    return $target;
  }

  public function testCronHookOfOtherModuleRotatesUs(): void {
    $this->installConfig(['system']);
    $this->createTestView('vpr_it_test', 'it-test-nominal');
    $this->createTargetEntity('t1', 'vpr_it_test');

    $this->assertEmpty($this->container->get('state')->get('views_path_rotator.current_path.t1'), 'Not rotated yet.');

    \Drupal::moduleHandler()->invoke('interval_trigger', 'cron');

    $current = $this->container->get('state')->get('views_path_rotator.current_path.t1');
    $this->assertNotEmpty($current, 'interval_trigger_cron() must find and run the registered RotationTask.');
    $this->assertMatchesRegularExpression('#^/[0-9a-f]{16}$#', $current);
  }

  protected function dispatchTerminate(): void {
    $event = new TerminateEvent(
      $this->container->get('http_kernel'),
      Request::create('/'),
      new Response()
    );
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::TERMINATE);
  }

  public function testRealTerminateEventRotatesOptedInTarget(): void {
    $this->installConfig(['system', 'interval_trigger']);
    $this->createTestView('vpr_it_term', 'it-term-nominal');
    $this->createTargetEntity('term_on', 'vpr_it_term', TRUE, TRUE);

    $this->dispatchTerminate();

    $this->assertNotEmpty($this->container->get('state')->get('views_path_rotator.current_path.term_on'));
  }

  public function testRealTerminateEventIgnoresTargetWithoutRequestOptIn(): void {
    $this->installConfig(['system', 'interval_trigger']);
    $this->createTestView('vpr_it_term2', 'it-term-nominal-2');
    $this->createTargetEntity('term_off', 'vpr_it_term2', TRUE, FALSE);

    $this->dispatchTerminate();

    $this->assertEmpty($this->container->get('state')->get('views_path_rotator.current_path.term_off'));
  }

  public function testRotatedTargetIsNotRotatedAgainBeforeItsInterval(): void {
    $this->installConfig(['system', 'interval_trigger']);
    $this->createTestView('vpr_it_term3', 'it-term-nominal-3');
    $this->createTargetEntity('term_repeat', 'vpr_it_term3', TRUE, TRUE);

    $this->dispatchTerminate();
    $first = $this->container->get('state')->get('views_path_rotator.current_path.term_repeat');
    $this->assertNotEmpty($first);

    $this->dispatchTerminate();
    $this->dispatchTerminate();
    $this->assertSame($first, $this->container->get('state')->get('views_path_rotator.current_path.term_repeat'), 'Repeated requests within the interval must not rotate again.');
  }

  public function testCronRotatesAllEnabledTargetsInOneRun(): void {
    $this->installConfig(['system']);
    $this->createTestView('vpr_it_test_multi_a', 'it-test-multi-nominal-a');
    $this->createTestView('vpr_it_test_multi_b', 'it-test-multi-nominal-b');
    $this->createTargetEntity('multi_a', 'vpr_it_test_multi_a');
    $this->createTargetEntity('multi_b', 'vpr_it_test_multi_b');

    \Drupal::moduleHandler()->invoke('interval_trigger', 'cron');

    $state = $this->container->get('state');
    $this->assertNotEmpty($state->get('views_path_rotator.current_path.multi_a'));
    $this->assertNotEmpty($state->get('views_path_rotator.current_path.multi_b'));
  }

  public function testTerminateChannelRotatesWhenTriggerOnRequestEnabled(): void {
    $this->installConfig(['system']);
    $this->createTestView('vpr_it_test_2', 'it-test-nominal-2');
    $this->createTargetEntity('t2', 'vpr_it_test_2', TRUE, TRUE);

    $this->container->get('interval_trigger.runner')->runDueTasks('request');

    $current = $this->container->get('state')->get('views_path_rotator.current_path.t2');
    $this->assertNotEmpty($current);
  }

  public function testTerminateChannelDoesNothingWhenTriggerOnRequestDisabled(): void {
    $this->installConfig(['system']);
    $this->createTestView('vpr_it_test_3', 'it-test-nominal-3');
    $this->createTargetEntity('t3', 'vpr_it_test_3', TRUE, FALSE);

    $this->container->get('interval_trigger.runner')->runDueTasks('request');

    $this->assertEmpty($this->container->get('state')->get('views_path_rotator.current_path.t3'), 'trigger_on_request is off: the request channel must not rotate this target.');
  }

  public function testTerminateChannelOnlyRotatesTargetsThatOptedIn(): void {
    $this->installConfig(['system']);
    $this->createTestView('vpr_it_test_4a', 'it-test-mixed-a');
    $this->createTestView('vpr_it_test_4b', 'it-test-mixed-b');
    $this->createTargetEntity('mixed_opted_in', 'vpr_it_test_4a', TRUE, TRUE);
    $this->createTargetEntity('mixed_opted_out', 'vpr_it_test_4b', TRUE, FALSE);

    $this->container->get('interval_trigger.runner')->runDueTasks('request');

    $state = $this->container->get('state');
    $this->assertNotEmpty($state->get('views_path_rotator.current_path.mixed_opted_in'));
    $this->assertEmpty($state->get('views_path_rotator.current_path.mixed_opted_out'));
  }

  public function testNeitherChannelRotatesWhenTargetDisabled(): void {
    $this->installConfig(['system']);
    $this->createTestView('vpr_it_test_5', 'it-test-nominal-5');
    $this->createTargetEntity('t5', 'vpr_it_test_5', FALSE, TRUE);

    \Drupal::moduleHandler()->invoke('interval_trigger', 'cron');
    $this->container->get('interval_trigger.runner')->runDueTasks('request');

    $this->assertEmpty($this->container->get('state')->get('views_path_rotator.current_path.t5'), 'status=FALSE: no channel may rotate this target automatically.');
  }

}
