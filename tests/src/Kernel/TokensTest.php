<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;

/**
 * @group views_path_rotator
 */
class TokensTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'views',
    'interval_trigger',
    'views_path_rotator',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    View::create([
      'id' => 'tok_view',
      'label' => 'tok_view',
      'base_table' => 'users_field_data',
      'display' => [
        'default' => ['display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Master', 'position' => 0, 'display_options' => []],
        'page_1' => ['display_plugin' => 'page', 'id' => 'page_1', 'display_title' => 'Page', 'position' => 1, 'display_options' => ['path' => 'tok-nominal']],
      ],
    ])->save();
    $this->container->get('router.builder')->rebuild();
    $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target')
      ->create(['id' => 'tok', 'label' => 'Tok', 'view_id' => 'tok_view', 'display_id' => 'page_1'])
      ->save();
  }

  protected function replace(string $text, ?BubbleableMetadata $metadata = NULL): string {
    return (string) $this->container->get('token')->replace($text, [], [], $metadata ?? new BubbleableMetadata());
  }

  public function testNominalPathBeforeFirstRotation(): void {
    $this->assertSame('/tok-nominal', $this->replace('[views_path_rotator:target_path:tok]'));
    $this->assertStringEndsWith('/tok-nominal', $this->replace('[views_path_rotator:target_url:tok]'));
  }

  public function testCurrentPathAfterRotation(): void {
    $target = $this->container->get('entity_type.manager')->getStorage('views_path_rotator_target')->load('tok');
    $new = $this->container->get('views_path_rotator.manager')->rotate($target, 'manual')['new_path'];

    $metadata = new BubbleableMetadata();
    $this->assertSame($new, $this->replace('[views_path_rotator:target_path:tok]', $metadata));
    $this->assertStringEndsWith($new, $this->replace('[views_path_rotator:target_url:tok]', $metadata));
    $this->assertContains('views_path_rotator:target:tok', $metadata->getCacheTags());
  }

  public function testUnknownTargetIsLeftAlone(): void {
    $this->assertSame('[views_path_rotator:target_path:nope]', $this->replace('[views_path_rotator:target_path:nope]'));
  }

}
