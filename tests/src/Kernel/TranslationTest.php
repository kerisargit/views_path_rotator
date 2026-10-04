<?php

declare(strict_types=1);

namespace Drupal\Tests\views_path_rotator\Kernel;

use Drupal\Tests\interval_trigger\Kernel\ModuleTranslationTestBase;

/**
 * @group views_path_rotator
 */
class TranslationTest extends ModuleTranslationTestBase {

  protected static $modules = [
    'system',
    'user',
    'file',
    'language',
    'locale',
    'help',
    'views',
    'interval_trigger',
    'views_path_rotator',
  ];

  protected function moduleName(): string {
    return 'views_path_rotator';
  }

  public function testKnownStrings(): void {
    $this->assertSame('Ротировать сейчас', $this->ru('Rotate now'));
    $this->assertSame('Включена', $this->ru('Enabled', ['context' => 'Rotation status']));
    $this->assertSame('Enabled', $this->ru('Enabled'));
    $this->assertSame('минут', $this->ru('minutes', ['context' => 'Rotation interval unit']));
  }

  public function testPlurals(): void {
    $translation = $this->container->get('string_translation');
    $cases = [1 => 'включённая цель', 3 => 'включённые цели', 5 => 'включённых целей', 21 => 'включённая цель'];
    foreach ($cases as $count => $expected) {
      $text = (string) $translation->formatPlural($count, 'Rotation configured: 1 enabled target.', 'Rotation configured: @count enabled targets.', [], ['langcode' => 'ru']);
      $this->assertStringContainsString($expected, $text, "count=$count");
    }
  }

}
