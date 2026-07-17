<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit\Algorithms;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M7 — colliding shared-cache slots must not reset independent
 * rate-limit counters.
 *
 * With a fixed clock and one-second sliding window, the production RateLimit
 * middleware increments `ratelimit:<principal>:12345`. The two printable
 * principals below give that full key the same CRC32. An identical-key control
 * must be rejected on its second request, proving the live limiter is active.
 * Each colliding principal is then requested twice in alternating order; a
 * collision-safe backend must reject both second requests independently.
 */
$collisionA = 'b97186618aa1434e';
$collisionB = '2f6843fd71907689';
$controlKey = 'm7-control';
$now = 12_345;
$Clock = static fn (): int => $now;

$Cache = new Cache([
   'driver' => 'shared',
   'prefix' => 'ratelimit:',
   'segment' => random_int(10_000_000, 2_000_000_000),
   'size' => 262_144,
   'clock' => $Clock,
]);

$RateLimit = new RateLimit(
   limit: 1,
   window: 1,
   algorithm: Algorithms::Sliding,
   key: static function (object $Request): null|string {
      $key = $Request->Header->get('X-M7-Key');

      return is_string($key) && $key !== '' ? $key : null;
   },
   clock: $Clock,
   Cache: $Cache,
);

return new Specification(
   description: 'Shared-cache CRC32 collisions must not reset RateLimit counters',
   Separator: new Separator(line: true),
   skip: extension_loaded('sysvshm') === false || extension_loaded('sysvsem') === false,

   requests: [
      static function () use ($controlKey): string {
         return "GET /m7/control HTTP/1.1\r\nHost: localhost\r\nX-M7-Key: {$controlKey}\r\n\r\n";
      },
      static function () use ($controlKey): string {
         return "GET /m7/control HTTP/1.1\r\nHost: localhost\r\nX-M7-Key: {$controlKey}\r\n\r\n";
      },
      static function () use ($collisionA): string {
         return "GET /m7/transfer HTTP/1.1\r\nHost: localhost\r\nX-M7-Key: {$collisionA}\r\n\r\n";
      },
      static function () use ($collisionB): string {
         return "GET /m7/transfer HTTP/1.1\r\nHost: localhost\r\nX-M7-Key: {$collisionB}\r\n\r\n";
      },
      static function () use ($collisionA): string {
         return "GET /m7/transfer HTTP/1.1\r\nHost: localhost\r\nX-M7-Key: {$collisionA}\r\n\r\n";
      },
      static function () use ($collisionB): string {
         return "GET /m7/transfer HTTP/1.1\r\nHost: localhost\r\nX-M7-Key: {$collisionB}\r\n\r\n";
      },
   ],

   middlewares: [$RateLimit],

   response: static function (Request $Request, Response $Response): Response {
      $key = $Request->Header->get('X-M7-Key') ?? 'missing';

      return $Response(body: 'M7-PROTECTED-HANDLER:' . $key);
   },

   test: static function (array $responses) use (
      $Cache,
      $collisionA,
      $collisionB,
      $controlKey,
      $now
   ): bool|string {
      try {
         if (count($responses) !== 6) {
            return 'M7 probe did not receive all six RateLimit responses.';
         }

         $wireA = "ratelimit:{$collisionA}:{$now}";
         $wireB = "ratelimit:{$collisionB}:{$now}";
         $controlWire = "ratelimit:{$controlKey}:{$now}";
         $CRCA = crc32($wireA);
         $CRCB = crc32($wireB);
         if ($wireA === $wireB || $CRCA !== $CRCB || $CRCA === crc32($controlWire)) {
            return 'M7 collision fixture is invalid for the exact RateLimit cache keys.';
         }

         [
            $controlFirst,
            $controlSecond,
            $attackAFirst,
            $attackBFirst,
            $attackASecond,
            $attackBSecond,
         ] = $responses;

         if (
            ! str_contains($controlFirst, 'HTTP/1.1 200 OK')
            || ! str_contains($controlFirst, "M7-PROTECTED-HANDLER:{$controlKey}")
            || ! str_contains($controlFirst, 'X-RateLimit-Limit: 1')
         ) {
            Vars::$labels = ['M7 first identical-key control response'];
            dump(json_encode($controlFirst));

            return 'M7 control failed: the first request did not pass through the live RateLimit middleware.';
         }

         if (
            ! str_contains($controlSecond, 'HTTP/1.1 429 Too Many Requests')
            || ! str_contains($controlSecond, 'Too Many Requests')
            || str_contains($controlSecond, 'M7-PROTECTED-HANDLER:')
         ) {
            Vars::$labels = ['M7 repeated identical-key control response'];
            dump(json_encode($controlSecond));

            return 'M7 control failed: a repeated non-colliding key was not blocked at the configured limit.';
         }

         foreach (
            [
               $collisionA . '-first' => $attackAFirst,
               $collisionB . '-first' => $attackBFirst,
            ] as $label => $response
         ) {
            $principal = substr($label, 0, -6);
            if (
               ! str_contains($response, 'HTTP/1.1 200 OK')
               || ! str_contains($response, "M7-PROTECTED-HANDLER:{$principal}")
               || ! str_contains($response, 'X-RateLimit-Remaining: 0')
            ) {
               Vars::$labels = ["M7 {$label} response"];
               dump(json_encode($response));

               return "M7 {$label} control failed: the principal's first request did not reach the handler.";
            }
         }

         $bypasses = [];
         foreach (
            [
               $collisionA => $attackASecond,
               $collisionB => $attackBSecond,
            ] as $principal => $response
         ) {
            if (
               str_contains($response, 'HTTP/1.1 200 OK')
               && str_contains($response, "M7-PROTECTED-HANDLER:{$principal}")
               && str_contains($response, 'X-RateLimit-Remaining: 0')
            ) {
               $bypasses[] = $principal;
               continue;
            }

            if (
               ! str_contains($response, 'HTTP/1.1 429 Too Many Requests')
               || str_contains($response, 'M7-PROTECTED-HANDLER:')
            ) {
               Vars::$labels = ["M7 unexpected second response for {$principal}"];
               dump(json_encode($response));

               return "M7 {$principal} neither bypassed nor reached the expected RateLimit rejection control.";
            }
         }

         if ($bypasses !== []) {
            Vars::$labels = ['M7 colliding-principal bypass responses'];
            dump(json_encode($attackASecond), json_encode($attackBSecond));

            return 'CONFIRMED M7: alternating CRC32-colliding principals reset the shared '
               . 'RateLimit counters and reached the protected handler twice: '
               . implode(', ', $bypasses) . '.';
         }

         return true;
      }
      finally {
         // @ Attach from the test process, then remove the isolated SysV pair
         //   created by the worker so the retained PoC leaves no IPC artifact.
         $Cache->fetch('__m7_cleanup__');
         $Driver = $Cache->Driver;
         if ($Driver instanceof Shared) {
            $Driver->destroy();
         }
      }
   },
);
