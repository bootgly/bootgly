<?php

use function str_contains;
use function rtrim;
use function is_dir;
use function mkdir;
use function file_put_contents;
use function unlink;
use function rmdir;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Response::process()` (and `render()`/`upload()`) validate file paths
 * with `str_starts_with($resolved, $base)` where `$base` has NO trailing
 * directory separator. A sibling directory whose name shares a prefix with
 * the base (e.g. `.../views_leak_poc/` vs base `.../views`) passes the guard
 * even though it is OUTSIDE the intended tree.
 *
 * Exercise via `Response::render()` (the `'view'` branch of `process()`)
 * because it is the only path that actually transmits file content to the
 * client after the guard. `render()` concatenates `$view . '.template.php'`
 * and opens `BOOTGLY_PROJECT->path . 'views/' . $that`.
 *
 * Attack layout (auto-provisioned below):
 *   BOOTGLY_PROJECT->path/
 *     views/                     ← intended base (must exist for realpath)
 *     views_leak_poc/            ← sibling, NOT inside views/
 *       SECRET.template.php      ← contains attacker marker
 *
 * Relative view argument: `../views_leak_poc/SECRET`
 *   File    → `.../Demo/views/../views_leak_poc/SECRET.template.php`
 *   realpath→ `.../Demo/views_leak_poc/SECRET.template.php`
 *   base    → `.../Demo/views`                (no trailing sep)
 *   str_starts_with(realpath, base) → TRUE ⇒ template is rendered ⇒ LEAK.
 *
 * Expected (fixed) behaviour: base is compared WITH a trailing
 * DIRECTORY_SEPARATOR so `.../views_leak_poc/...` no longer starts with
 * `.../views/` → 403 (or empty body).
 */

// ! Provision fixture dirs/files at suite-include time.
$projectPath    = rtrim(BOOTGLY_PROJECT->path, '/\\');
$viewsDir       = $projectPath . '/views';
$leakDir        = $projectPath . '/views_leak_poc';
$leakTemplate   = $leakDir . '/SECRET.template.php';
$secretMarker   = 'LEAKED-SECRET-PATH-TRAVERSAL-8.01';
$viewsCreated   = false;

if (! is_dir($viewsDir)) {
   @mkdir($viewsDir, 0700, true);
   $viewsCreated = true;
}
if (! is_dir($leakDir)) {
   @mkdir($leakDir, 0700, true);
}
@file_put_contents($leakTemplate, "<?php echo '{$secretMarker}'; ?>");


return new Specification(
   description: 'Response::render() must reject sibling-prefix escape from views/',
   Separator: new Separator(line: true),

   request: function (): string {
      return "GET /traversal HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/traversal', function (Request $Request, Response $Response) {
         // : The literal `../views_leak_poc/SECRET` is what an attacker would
         //   feed into any handler that forwards a request-controlled name
         //   into $Response->render(). The guard in process()/view branch
         //   is the only gate — we observe it through $Response->code:
         //     * vulnerable: render() silently accepts → code stays 200.
         //     * fixed     : guard rejects → code becomes 403.
         $Response->render('../views_leak_poc/SECRET');
         $code = $Response->code;
         $verdict = ($code === 403) ? 'GUARD-REJECTED' : "GUARD-BYPASSED({$code})";

         return $Response(code: 200, body: $verdict);
      });

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response) use (
      $leakTemplate, $leakDir, $viewsDir, $viewsCreated
   ): bool|string {
      // @ Cleanup provisioned artifacts.
      @unlink($leakTemplate);
      @rmdir($leakDir);
      if ($viewsCreated === true) {
         @rmdir($viewsDir);
      }

      if ($response === '') {
         return 'No response from server.';
      }

      if (str_contains($response, 'GUARD-BYPASSED')) {
         return 'Path traversal: Response::process() accepted a sibling-prefix '
            . 'escape (views/ → views_leak_poc/) because the str_starts_with() '
            . 'base path lacks a trailing DIRECTORY_SEPARATOR. Fix all three '
            . 'guards in Response.php (process() view branch, process() default '
            . 'branch, upload()).';
      }

      if (! str_contains($response, 'GUARD-REJECTED')) {
         return 'Unexpected response (neither GUARD-REJECTED nor GUARD-BYPASSED): '
            . substr($response, 0, 200);
      }

      return true;
   }
);
