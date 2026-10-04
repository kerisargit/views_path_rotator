# Views Path Rotator

Periodically moves a View page display to a new random path (for example
`/search-3f9a2b7c1d4e58a0`), so bots that hit a known, hard-coded URL stop
finding the page. The old address simply returns 404. This is protection by
obscurity, not a replacement for rate limiting or CAPTCHA.

Nothing is ever saved to the View, blocks or menus, and the route is never
rewritten: the path is substituted while requests are processed and links are
generated. Only State changes.

## Requirements

| | Minimum | Why |
|---|---|---|
| Drupal | 10.3 (`^10.3 \|\| ^11`) | The `#[Condition]` plugin attribute exists since 10.3.0. |
| PHP | 8.1 | Drupal's own minimum. |
| Database | any supported by core | Only Entity/Config/State APIs. |
| Modules | `views`, `interval_trigger` | Help topics need `help` (core 10.2+); Russian UI needs `locale` + `language`. |

## Setup

1. Enable the module (and `interval_trigger`).
2. *Configuration → Search and metadata → Views Path Rotator*: add a rotation
   target (View page display, path format, interval).
3. Test it with the **Rotate now** operation, then enable automatic rotation.
4. Point menu links to the route (`route:view.VIEW_ID.DISPLAY_ID`) instead of a
   literal path. See the module's help topics for block visibility and tokens
   (`[views_path_rotator:target_path:ID]`, `[views_path_rotator:target_url:ID]`).

Rotation runs on cron and, optionally per target, after regular page requests
(through Interval Trigger). It clears Drupal's own caches only: a reverse proxy
or CDN in front of the site must be purged separately (for example with the
Purge module).

## Tests

```
SIMPLETEST_DB=sqlite://localhost/tmp/vpr.sqlite vendor/bin/phpunit -c core modules/custom/views_path_rotator/tests
```
