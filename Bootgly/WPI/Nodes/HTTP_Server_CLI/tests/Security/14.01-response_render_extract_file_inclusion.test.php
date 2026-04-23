<?php

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function random_bytes;
use function register_shutdown_function;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Template::render()` (Bootgly/ABI/Templates/Template.php) builds
 * its render scope with:
 *
 *   (static function ($__file__, $parameters) {
 *      extract($parameters);      // ← default EXTR_OVERWRITE
 *      include $__file__;
 *   })($this->Cache->file, $parameters);
 *
 * The `$parameters` array is plumbed straight through
 * `Response::render(string $view, array $data)` (which also merges
 * `$this->uses`, populated via `$Response->export(...)`). If an attacker
 * controls any key in that array, `extract()` overwrites the closure's
 * `$__file__` sentinel — the next line `include $__file__` loads an
 * attacker-chosen PHP file instead of the cached template.
 *
 * Vulnerable call shape (any handler that forwards a user-influenced
 * array into render() / export()):
 *   $Response->render('test', $Request->post);   // or export($Request->post)
 *
 * Attack layout (auto-provisioned below):
 *   <sys_temp_dir>/bootgly-14.01-inclusion-<token>.php
 *     → writes a unique marker to
 *       <sys_temp_dir>/bootgly-14.01-witness-<token>.txt when executed.
 *
 * Observed on the wire — the response body is a side channel here
 * because `Response::render()` pipes into the Template and the
 * captured output is set via `$this->content` (a no-op under the
 * current __set hook). The real signal is the witness file: if
 * `include $__file__` fires, the witness file is created on disk.
 *
 *   * vulnerable: witness file exists after the request completes.
 *   * fixed    : witness file is never created (extract() honours
 *                EXTR_SKIP or filters `__file__`/`parameters`).
 */

$fixtureToken   = bin2hex(random_bytes(6));
$leakFile       = sys_get_temp_dir() . '/bootgly-14.01-inclusion-' . $fixtureToken . '.php';
$witnessFile    = sys_get_temp_dir() . '/bootgly-14.01-witness-'   . $fixtureToken . '.txt';
$witnessMarker  = 'EXECUTED-14.01-' . $fixtureToken;

@file_put_contents(
   $leakFile,
   "<?php @file_put_contents('{$witnessFile}', '{$witnessMarker}'); ?>"
);

$cleanup = static function () use ($leakFile, $witnessFile): void {
   @unlink($leakFile);
   @unlink($witnessFile);
};
register_shutdown_function($cleanup);


return new Specification(
   description: 'Response::render() must not let user-controlled data overwrite the Template closure sentinel',
   Separator: new Separator(line: true),

   request: function (): string {
      return "GET /render-inject HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) use ($leakFile) {
      yield $Router->route('/render-inject', function (Request $Request, Response $Response) use ($leakFile) {
         // : Simulates a handler that forwards a user-influenced array
         //   (e.g. `$Request->queries` or `$Request->post`) into render().
         //   The key `__file__` collides with the Template's closure
         //   sentinel — on a vulnerable build, `extract()` overwrites the
         //   local `$__file__` and `include $__file__` loads the
         //   attacker-controlled file.
         $Response->render('test', [
            '__file__' => $leakFile,
         ]);

         return $Response(code: 200, body: 'ack');
      });

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response) use ($cleanup, $witnessFile, $witnessMarker): bool|string {
      $leaked = file_exists($witnessFile)
         && str_contains((string) @file_get_contents($witnessFile), $witnessMarker);

      // @ Cleanup provisioned artifact (after reading the witness).
      $cleanup();

      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      if ($leaked) {
         return 'Arbitrary PHP file inclusion: Template::render() allowed a '
            . 'user-supplied `__file__` key to overwrite the render closure '
            . 'sentinel via extract(). Fix: pass EXTR_SKIP to extract() '
            . '(and/or rename the sentinel) in Template::render().';
      }

      return true;
   }
);
