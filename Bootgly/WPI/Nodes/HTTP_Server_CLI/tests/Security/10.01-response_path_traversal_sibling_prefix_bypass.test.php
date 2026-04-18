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
 * Exercise via `Response::render()` because it renders the resolved template
 * immediately into the HTTP response body. `render()` concatenates
 * `$view . '.template.php'` and opens
 * `BOOTGLY_PROJECT->path . 'views/' . $that`.
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
$secretMarker   = 'LEAKED-SECRET-PATH-TRAVERSAL-10.01';
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
         //   into $Response->render().
         //     * vulnerable: the sibling template is rendered and its marker
         //       reaches the client body.
         //     * fixed     : the path guard rejects before rendering and the
         //       final response status is 403.
         return $Response->render('../views_leak_poc/SECRET');
      });

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response) use (
      $leakTemplate, $leakDir, $viewsDir, $viewsCreated, $secretMarker
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

      $separator = \strpos($response, "\r\n\r\n");
      if ($separator === false) {
         return 'Malformed response (no CRLFCRLF): '
            . \substr($response, 0, 200);
      }
      $headers = \substr($response, 0, $separator);
      $body = \substr($response, $separator + 4);

      if (str_contains($body, $secretMarker)) {
         return 'Path traversal: Response::render() rendered a sibling-prefix '
            . 'escape (views/ → views_leak_poc/) outside the intended jail. '
            . 'Fix all three guards in Response.php (process() view branch, '
            . 'process() default branch, upload()).';
      }

      if (! str_contains($headers, '403')) {
         return 'Unexpected response (expected 403 after guard rejection): '
            . \substr($response, 0, 200);
      }

      return true;
   }
);
