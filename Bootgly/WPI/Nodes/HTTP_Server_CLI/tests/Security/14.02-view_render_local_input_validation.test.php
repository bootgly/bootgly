<?php

use function str_contains;
use function strlen;
use function strpos;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `View::render()` must validate the view name locally (audit F-12).
 *
 * `render()` is include-based, so a path traversal in `$view` is RCE, not mere
 *   LFI. The traversal is blocked today only because the downstream
 *   `File::guard()` does a `realpath` + base-containment check — one `File`
 *   default flip away from arbitrary inclusion. The fix makes the guard local
 *   and explicit at the sink (mirroring `Response::upload()`): reject null
 *   bytes, absolute paths, `..`, and any character outside `[A-Za-z0-9_/-]`
 *   before constructing `File`.
 *
 * This probe drives several hostile view names through a handler and asserts
 *   each is rejected with `403` before any inclusion, while a legitimate view
 *   name still renders.
 */

return new Specification(
   description: 'View::render() must reject traversal/null-byte/absolute view names locally (403)',
   Separator: new Separator(line: true),

   requests: [
      fn (string $hostPort): string => "GET /v-traversal HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
      fn (string $hostPort): string => "GET /v-nullbyte HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
      fn (string $hostPort): string => "GET /v-absolute HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
      fn (string $hostPort): string => "GET /v-noncanonical HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
      fn (string $hostPort): string => "GET /v-legit HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/v-traversal', fn (Request $Request, Response $Response) => $Response->View->render('../../etc/passwd'));
      yield $Router->route('/v-nullbyte',  fn (Request $Request, Response $Response) => $Response->View->render("test\0passwd"));
      yield $Router->route('/v-absolute',  fn (Request $Request, Response $Response) => $Response->View->render('/etc/passwd'));
      // @ Non-canonical name that `File::guard()` would resolve *in-jail* to
      //   the real `test` view (→ 200 without the local guard); the explicit
      //   `[\w/-]` whitelist rejects the `.` segment (→ 403). This is the
      //   fail-before / pass-after distinguisher for the local guard.
      yield $Router->route('/v-noncanonical', fn (Request $Request, Response $Response) => $Response->View->render('./test'));
      // @ Legit view shipped by the Demo project (`views/test.template.php`).
      yield $Router->route('/v-legit',     fn (Request $Request, Response $Response) => $Response->View->render('test'));
      yield $Router->route('/*', fn (Request $Request, Response $Response) => $Response(code: 404, body: 'Not Found'));
   },

   test: function (array $responses): bool|string {
      $status = static function (string $response): string {
         $end = strpos($response, "\r\n");
         return $end === false ? substr($response, 0, 80) : substr($response, 0, $end);
      };

      // @ Each hostile view name must be rejected with 403 before inclusion.
      //   `traversal`/`null byte`/`absolute` are also blocked by the downstream
      //   `File::guard()` (regression lock); `non-canonical` is the case the
      //   local guard alone rejects (fail-before / pass-after).
      $hostile = [
         'traversal (..)' => $responses[0] ?? '',
         'null byte'      => $responses[1] ?? '',
         'absolute path'  => $responses[2] ?? '',
         'non-canonical (. segment)' => $responses[3] ?? '',
      ];
      foreach ($hostile as $label => $response) {
         if ($response === '' || strlen($response) === 0) {
            return "No response for the {$label} case.";
         }
         if (! str_contains($status($response), '403')) {
            return "View::render() did not reject a {$label} view name with 403. "
               . 'Status: ' . $status($response);
         }
         // @ Defense: no /etc/passwd content (root: marker) may leak.
         if (str_contains($response, 'root:')) {
            return "View::render() leaked file content for the {$label} case.";
         }
      }

      // @ A legitimate view name must still render (not 403).
      $legit = $responses[4] ?? '';
      if (! str_contains($status($legit), '200')) {
         return 'Legit view "test" should render 200. Status: ' . $status($legit);
      }

      return true;
   }
);
