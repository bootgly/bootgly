<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC — the session Cache key path must reject replaceable ancestors.
 *
 * The handler validated only the immediate key directory and the final file.
 * Key creation and import then perform SEPARATE pathname-based `lstat`, `link`
 * and read operations, so an ancestor that another local UID can rename or
 * replace leaves a window between those steps: the checked component can be
 * swapped, redirecting key creation/import or substituting key material.
 *
 * The File session handler already walks its whole ancestor chain; this drives
 * the real Cache key resolver against fixture trees to prove the Cache handler
 * now does too.
 *
 * Controls: a private tree owned by this process must still be accepted (so the
 * hardening cannot pass by rejecting everything), and a world-writable ancestor
 * WITH the sticky bit — the /tmp shape — must also be accepted, since sticky is
 * exactly what makes a shared directory non-replaceable.
 */
$probe = ['error' => '', 'legs' => []];

return new Test(
   description: 'session Cache key path must reject world-writable non-sticky ancestors',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $base = sys_get_temp_dir() . '/bootgly-m3-' . bin2hex(random_bytes(6));

      try {
         $Reflection = new ReflectionClass(Cache::class);
         $secure = $Reflection->getMethod('secure');
         $secure->setAccessible(true);

         // @ Drive the real validator and report whether it accepted the tree.
         $Accepts = static function (string $directory) use ($secure): bool {
            try {
               $secure->invoke(null, $directory);

               return true;
            }
            catch (Throwable) {
               return false;
            }
         };

         // ! Control — a private chain owned by this process must be accepted.
         $safe = "{$base}/safe/keys";
         mkdir($safe, 0700, true);
         chmod("{$base}", 0700);
         chmod("{$base}/safe", 0700);
         $probe['legs']['control_private_chain'] = $Accepts($safe);

         // @ Attack — a world-writable NON-sticky ancestor above the key dir.
         $unsafe = "{$base}/open/keys";
         mkdir($unsafe, 0700, true);
         chmod("{$base}/open", 0777);
         $probe['legs']['attack_writable_ancestor'] = $Accepts($unsafe);

         // ! Control — world-writable WITH sticky (the /tmp shape) is fine.
         $sticky = "{$base}/sticky/keys";
         mkdir($sticky, 0700, true);
         chmod("{$base}/sticky", 01777);
         $probe['legs']['control_sticky_ancestor'] = $Accepts($sticky);
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         exec('rm -rf ' . escapeshellarg($base) . ' 2>/dev/null');
      }

      return "GET /m3-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m3-harness', static function (Request $Request, Response $Response) {
         return $Response(body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'M3 harness request did not reach /m3-harness.';
      }
      if ($probe['error'] !== '') {
         return 'M3 fixture error: ' . $probe['error'];
      }

      $legs = $probe['legs'];

      // ? Controls — legitimate trees must still be accepted.
      if (($legs['control_private_chain'] ?? null) !== true) {
         return 'M3 control failed: a private chain owned by this process was rejected, so the '
            . 'hardening refuses everything: ' . json_encode($legs);
      }
      if (($legs['control_sticky_ancestor'] ?? null) !== true) {
         return 'M3 control failed: a world-writable STICKY ancestor was rejected. Sticky is '
            . 'exactly what makes a shared directory non-replaceable: ' . json_encode($legs);
      }

      if (($legs['attack_writable_ancestor'] ?? null) === true) {
         return 'CONFIRMED M3: the session Cache key path accepted a world-writable, '
            . 'non-sticky ancestor. Another local UID can rename that component between the '
            . 'separate lstat/link/read steps of key creation and import, redirecting them.';
      }

      return true;
   },
);
