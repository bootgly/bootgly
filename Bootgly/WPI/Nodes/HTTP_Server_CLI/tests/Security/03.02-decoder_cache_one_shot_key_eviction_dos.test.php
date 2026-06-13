<?php

use function json_encode;
use function str_contains;
use function strlen;
use function strpos;
use function substr;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — one-shot query-key churn must NOT evict hot Decoder_ L1 entries.
 *
 * The protection is that query-bearing request targets are classified
 * UNCACHEABLE in Decoder_::decode (a `?` before the request-line CRLF), so a
 * flood of unique `?noise=N` keys never enters the L1 cache and therefore
 * cannot evict the warmed query-less BASE entry.
 *
 * Probe strategy:
 * 1) BASE request to warm the L1 entry.
 * 2) Flood unique one-shot query keys once each — the count only has to
 *    exceed the L1 capacity (512) so that, *if* these keys were cacheable,
 *    they would provably evict BASE. 600 keys (> capacity) is decisive while
 *    staying robust (see the bound note below).
 * 3) BASE again — it must still be served correctly (200 OK + body), proving
 *    the churn neither wedged the worker nor corrupted the warmed entry.
 *
 * BOUND NOTE: the flood is deliberately capped at 600. The Test-mode server
 * runs a SINGLE worker; an unbounded synchronous connect/close hammer (the
 * original 10000) saturates its `stream_select` loop and the kernel starts
 * refusing new connections (errno 111) around ~1700 sockets, wedging the
 * harness — an artifact of the test client, not of the cache being probed.
 *
 * DETECTION NOTE (changed): the original probe compared an `RT:` time marker
 * across the flood, expecting it unchanged on a cache hit. That signal is
 * INVALID since the per-connection Request rework (commit 5e30c266): every
 * `Connection: close` request gets a fresh Request whose `$time` is `readonly`
 * and set at construction (wall-clock seconds), and `assume()` does NOT copy
 * the template's time. So the marker reflects wall-clock, not hit/miss —
 * baseline vs afterFlood differ iff the flood crosses a 1-second boundary,
 * which is unrelated to eviction (a hit and a re-decode produce byte-identical
 * responses). The eviction-resistance itself is STRUCTURAL: query-bearing
 * targets are classified uncacheable in `Decoder_::decode`, so they never
 * enter the cache and cannot thrash it — there is no E2E-observable hit/miss
 * signal to assert on. This test now asserts the observable invariant (BASE
 * keeps working under churn); a direct unit test of the cacheability
 * classification is the right place for the strict non-entry assertion.
 */

$probe = [
   'baseline' => null,
   'afterFlood' => null,
   'samples' => [],
];

return new Specification(
   description: 'Decoder_ cache must resist one-shot query-key churn',
   Separator: new Separator(line: true),

   request: function (string $hostPort, int $testIndex = 0) use (&$probe): string {
      $base = "GET /cache-eviction-dos HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\nConnection: close\r\n\r\n";

      $send = static function (string $raw) use ($hostPort): string {
         // ! Retry transient connect failures (errno 111 under rapid
         //   connect/close churn) with a short backoff so the probe stays
         //   robust at the connection-rate boundary.
         $socket = false;
         for ($try = 0; $try < 3; $try++) {
            $socket = @\stream_socket_client(
               "tcp://{$hostPort}", $errno, $errstr, timeout: 5
            );
            if (\is_resource($socket)) {
               break;
            }
            \usleep(5000);
         }
         if (! \is_resource($socket)) {
            return '';
         }

         \stream_set_blocking($socket, true);
         \stream_set_timeout($socket, 2);

         @\fwrite($socket, $raw);

         $buffer = '';
         while (true) {
            $chunk = @\fread($socket, 65535);
            if ($chunk === false || $chunk === '') {
               if (@\feof($socket)) {
                  break;
               }
               continue;
            }

            $buffer .= $chunk;
            if (str_contains($buffer, "\r\n\r\n")) {
               break;
            }
         }

         @\fclose($socket);

         return $buffer;
      };

      $extractTime = static function (string $response): null|string {
         $marker = 'RT:';
         $position = strpos($response, $marker);
         if ($position === false) {
            return null;
         }

         $start = $position + 3;
         $value = '';
         $length = strlen($response);
         for ($i = $start; $i < $length; $i++) {
            $char = $response[$i];
            if (($char < '0' || $char > '9') && $char !== '.') {
               break;
            }
            $value .= $char;
         }

         if ($value === '') {
            return null;
         }

         return $value;
      };

      $first = $send($base);
      $second = $send($base);
      $probe['samples'][] = substr($first, 0, 180);
      $probe['samples'][] = substr($second, 0, 180);
      $probe['baseline'] = $extractTime($second) ?? $extractTime($first);

      // > the Decoder_ L1 capacity (512) — enough to evict BASE if these
      //   keys were cacheable; bounded well under the single-worker harness's
      //   rapid-connect/close ceiling (errno 111 around ~1700) to stay robust.
      for ($i = 0; $i < 600; $i++) {
         $noise = "GET /cache-eviction-dos?noise={$i} HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\nConnection: close\r\n\r\n";
         $send($noise);
      }

      $afterFlood = $send($base);
      $probe['samples'][] = substr($afterFlood, 0, 180);
      $probe['afterFlood'] = $extractTime($afterFlood);

      return $base;
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/cache-eviction-dos', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'RT:' . (string) $Request->time);
      }, GET);

      // @ Compatibility route for 12.01 when its priming side-connection
      //   advances the server-side handler FIFO by one slot.
      yield $Router->route('/bodyparser-poc', function (Request $Request, Response $Response) {
         return $Response(code: 413, body: '');
      }, POST);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response) use (&$probe): bool|string {
      // Final BASE (sent by the harness after the flood) must still be served
      //   correctly: 200 OK with the route body marker. A wedged worker or a
      //   corrupted L1 entry from the query-key churn would break this.
      if (! str_contains($response, '200 OK') || ! str_contains($response, 'RT:')) {
         Vars::$labels = ['Harness response'];
         dump(json_encode(substr($response, 0, 220)));
         return 'BASE was not served correctly after the query-key flood '
              . '(churn wedged the worker or corrupted the warmed L1 entry).';
      }

      // The in-probe post-flood BASE (3rd sample) must likewise be a valid
      //   200 response — guards against the flood silently breaking serving.
      $afterFlood = $probe['samples'][2] ?? '';
      if (! str_contains($afterFlood, '200 OK') || ! str_contains($afterFlood, 'RT:')) {
         Vars::$labels = ['Probe samples'];
         dump(json_encode($probe['samples']));
         return 'Post-flood BASE probe did not return a valid 200 response.';
      }

      // NOTE: eviction-resistance is structural (query targets are uncacheable
      //   in Decoder_::decode) and has no E2E-observable hit/miss signal — see
      //   the DETECTION NOTE in the file header. No time-marker assertion here.
      return true;
   }
);
