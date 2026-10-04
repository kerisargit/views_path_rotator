<?php

/**
 * @file
 * Token hooks for Views Path Rotator.
 */

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Url;
use Drupal\views_path_rotator\Entity\ViewsPathRotatorTarget;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Implements hook_token_info().
 */
function views_path_rotator_token_info() {
  $types['views_path_rotator'] = [
    'name' => t('Views Path Rotator'),
    'description' => t('Current path of a specific rotation target (as of the last rotation).'),
  ];

  $views_path_rotator['target_path'] = [
    'name' => t('Target path (by id)'),
    'description' => t('For example [views_path_rotator:target_path:search]: path relative to the site root. TARGET_ID is shown in the target list on the settings page.'),
    'dynamic' => TRUE,
  ];
  $views_path_rotator['target_url'] = [
    'name' => t('Target URL (by id)'),
    'description' => t('For example [views_path_rotator:target_url:search]: absolute link, such as https://site/search-3f9a2b7c.'),
    'dynamic' => TRUE,
  ];

  return [
    'types' => $types,
    'tokens' => ['views_path_rotator' => $views_path_rotator],
  ];
}

/**
 * Implements hook_tokens().
 */
function views_path_rotator_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $replacements = [];

  if ($type !== 'views_path_rotator') {
    return $replacements;
  }

  $token_service = \Drupal::token();
  $manager = \Drupal::service('views_path_rotator.manager');

  foreach (['target_path' => 'path', 'target_url' => 'url'] as $prefix => $kind) {
    $dynamic_tokens = $token_service->findWithPrefix($tokens, $prefix);
    if (!$dynamic_tokens) {
      continue;
    }
    foreach ($dynamic_tokens as $target_id => $original) {
      try {
        views_path_rotator_replace_token((string) $target_id, $original, $kind, $manager, $bubbleable_metadata, $replacements);
      }
      catch (\Throwable $e) {
        // Leave the token unreplaced rather than break the page rendering it.
        \Drupal::logger('views_path_rotator')->error('Token @token not replaced: @message', ['@token' => $original, '@message' => $e->getMessage()]);
      }
    }
  }

  return $replacements;
}

/**
 * Replaces one [views_path_rotator:target_*:ID] token.
 */
function views_path_rotator_replace_token(string $target_id, string $original, string $kind, $manager, BubbleableMetadata $bubbleable_metadata, array &$replacements): void {
  $target = ViewsPathRotatorTarget::load($target_id);
  if (!$target) {
    return;
  }
  $bubbleable_metadata->addCacheTags(['views_path_rotator:target:' . $target_id]);

  // Built from the route, so a target that has not rotated yet yields its
  // nominal path instead of an unreplaced token.
  try {
    $generated = Url::fromRoute($target->getRouteName(), [], ['absolute' => $kind === 'url'])->toString(TRUE);
  }
  catch (RouteNotFoundException) {
    return;
  }
  $bubbleable_metadata->addCacheableDependency($generated);

  if ($kind === 'url') {
    $replacements[$original] = $generated->getGeneratedUrl();
  }
  else {
    $replacements[$original] = $manager->getStatus($target)['current_path']
      ?: \Drupal::service('router.route_provider')->getRouteByName($target->getRouteName())->getPath();
  }
}
