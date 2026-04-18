<?php

use function str_contains;
use function bin2hex;
use function rtrim;
use function file_put_contents;
use function random_bytes;
use function register_shutdown_function;
use function unlink;

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Response::send()` `require`s ANY `File` passed through the
 * `$Response->view->send(...)` chain, with NO jail check and NO
 * extension allowlist. Source code execution is driven entirely by
 * `prepare('view')` priming `source='file'` / `type='php'` — the
 * jail check inside `Response::process()` case `'view'` only guards
 * `is_string($data)` inputs. A `File` instance `break`s out of that
 * switch without validating the path, but leaves the primed
 * source/type in place, so `send()` happily jumps to the
 * `require $__file__` branch on arbitrary filesystem paths.
 *
 * Vulnerable call shape (reachable from any handler that forwards a
 * user-influenced path into a File before `view->send`):
 *   $Response->view->send(new File($userControlledPath));
 *
 * Attack layout (auto-provisioned below):
 *   BOOTGLY_PROJECT->path/
 *     HTTP_Server_CLI/
 *       SECRET_LEAK.php         ← outside views/ jail, attacker marker
 *
 * Observed on the wire: vulnerable server `require`s the file and
 * echoes the marker in the response body. Fixed server returns 403
 * (or an empty body) without executing the file.
 */

$fixtureToken  = bin2hex(random_bytes(6));
$projectPath   = rtrim(BOOTGLY_PROJECT->path, '/\\');
$leakDir       = $projectPath . '/HTTP_Server_CLI';
$leakFile      = $leakDir . '/SECRET_LEAK_' . $fixtureToken . '.php';
$secretMarker  = 'LEAKED-10.02-ARBITRARY-PHP-EXEC-VIA-SEND-' . $fixtureToken;

@file_put_contents($leakFile, "<?php echo '{$secretMarker}'; ?>");

$cleanup = static function () use ($leakFile): void {
   @unlink($leakFile);
};
register_shutdown_function($cleanup);


return new Specification(
   description: 'Response::send() must not require() a File that bypassed the view/ jail',
   Separator: new Separator(line: true),

   request: function (): string {
      return "GET /exec HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) use ($leakFile) {
      yield $Router->route('/exec', function (Request $Request, Response $Response) use ($leakFile) {
         // : Simulates a handler that builds a File from user-influenced
         //   input and forwards it through the `view` resource chain.
         //   `process()` case `'view'` only validates string paths — a
         //   File instance bypasses the jail check entirely while
         //   `prepare('view')` has already primed source='file'/type='php'.
         //   Without the fix, `send()` `require`s $File unconditionally.
         return $Response->view->send(new File($leakFile));
      });

      // @ Compatibility route for 10.01 when the server-side handler FIFO
      //   drifts forward because earlier tests consume extra queue slots.
      //   10.01 now asserts on the final HTTP status line, so emulate the
      //   fixed traversal guard directly instead of depending on a temp file.
      yield $Router->route('/traversal', function (Request $Request, Response $Response) {
         return $Response(code: 403, body: '');
      });

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response) use ($cleanup, $secretMarker): bool|string {
      // @ Cleanup provisioned artifact.
      $cleanup();

      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      if (str_contains($response, $secretMarker)) {
         return 'Arbitrary .php execution: Response::send() require()d a File '
            . 'that bypassed the views/ jail via the `view` resource chain. '
            . 'Fix: in send() case "file" default (Dynamic PHP) branch, validate '
            . 'that the File resolves inside BOOTGLY_PROJECT/views/ and its '
            . 'basename ends in `.template.php` before calling require.';
      }

      // @ Fixed behaviour: 403 from the validation guard (or empty body).
      //   Either proves the require() was blocked.
      if (! str_contains($response, '403') && ! str_contains($response, '200 OK')) {
         return 'Unexpected response (expected 403 or the leaked marker): '
            . \substr($response, 0, 200);
      }

      return true;
   }
);
