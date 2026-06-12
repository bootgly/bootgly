<?php

use function count;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function implode;
use function is_resource;
use function json_encode;
use function microtime;
use function str_contains;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function substr;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — per-connection `Request` reuse must scrub ALL per-request state
 * between subsequent requests on the SAME keep-alive connection.
 *
 * Attack scenario:
 *   `Decoder_::decode()` no longer clones the cached template on every hit.
 *   Each connection owns a single `Request` instance (`$Package->decoded`)
 *   that is re-armed via `Request::assume()` on every cache hit. If
 *   `assume()` misses one per-request member, state set by request N leaks
 *   into request N+1 of the same connection. With a keep-alive reverse
 *   proxy in front, one upstream connection multiplexes MANY end users —
 *   a same-connection leak is a cross-user leak (auth identity, tenant,
 *   header/body poisoning).
 *
 * Probe:
 *   One side connection sends THREE byte-identical requests:
 *     1. cache MISS — primes the decoder template (captured pre-handler,
 *        so it is clean by construction).
 *     2. cache HIT — `$Package->decoded` is created and armed via
 *        `assume()`. The handler poisons every publicly mutable surface
 *        (attribute bag, Header fields, Body raw, auth identity/claims).
 *     3. cache HIT — SAME instance is re-armed via `assume()`. The handler
 *        must observe a fully pristine Request.
 *   The harness connection then sends identical bytes on a FOURTH,
 *   separate connection (cross-connection sanity: its own fresh
 *   per-connection instance must also be pristine).
 *
 * Expected (fixed) behavior: every request observes `contaminated=[]`.
 */

$sideResponses = [];

return new Specification(
   description: 'Per-connection Request reuse must scrub state between same-connection requests',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort, int $testIndex) use (&$sideResponses): string {
         // ! Byte-identical to what the harness will send below (the harness
         //   injects `X-Bootgly-Test: N` right after the request-line), so
         //   all four requests share ONE decoder-cache key.
         $bytes = "GET /assume-scrub HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\n\r\n";

         $socket = stream_socket_client(
            "tcp://{$hostPort}", $errno, $errstr, timeout: 5
         );
         if (is_resource($socket)) {
            stream_set_blocking($socket, true);
            stream_set_timeout($socket, 2);

            // : THREE sequential requests on the SAME connection.
            for ($i = 0; $i < 3; $i++) {
               fwrite($socket, $bytes);

               $deadline = microtime(true) + 2.0;
               $buf = '';
               while (microtime(true) < $deadline) {
                  $chunk = @fread($socket, 65535);
                  if ($chunk === false || $chunk === '') {
                     if (@feof($socket)) break;
                     if (str_contains($buf, ']')) break;
                     continue;
                  }
                  $buf .= $chunk;
                  if (str_contains($buf, ']')) {
                     break;
                  }
               }
               $sideResponses[] = $buf;

               if (@feof($socket)) {
                  break;
               }
            }

            @fclose($socket);
         }

         // : Fourth request — identical bytes on the harness's own (separate)
         //   connection. Its per-connection instance must be pristine too.
         return "GET /assume-scrub HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/assume-scrub', function (Request $Request, Response $Response) {
         static $hits = 0;
         $hits++;

         // ! Pristine-state oracle: anything below was set by the PREVIOUS
         //   request's handler — `Request::assume()` must have scrubbed it.
         $contaminated = [];
         if ( isSet($Request->poisoned) ) {
            $contaminated[] = 'attribute:' . (string) $Request->poisoned;
         }
         if ($Request->Header->get('X-Poison') !== null) {
            $contaminated[] = 'header';
         }
         if ($Request->Body->raw !== '') {
            $contaminated[] = 'body';
         }
         if ($Request->identity !== null) {
            $contaminated[] = 'identity';
         }
         if ($Request->claims !== []) {
            $contaminated[] = 'claims';
         }

         // @ Poison every publicly mutable per-request surface. In a real app
         //   these are auth middleware decisions / parsed body state.
         $Request->poisoned = 'tenant-' . $hits;
         $Request->Header->append('X-Poison', 'poison-' . $hits);
         $Request->Body->raw = 'poison-body-' . $hits;
         $Request->identity = (object) ['user' => 'attacker-' . $hits];
         $Request->claims = ['role' => 'admin-' . $hits];

         return $Response(
            code: 200,
            body: 'hit=' . $hits . ';contaminated=[' . implode(',', $contaminated) . ']'
         );
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) use (&$sideResponses) {
      // @ Same-connection probes (requests 1–3 on one keep-alive socket).
      if (count($sideResponses) < 3) {
         Vars::$labels = ['Side-connection responses received:'];
         dump(json_encode(count($sideResponses)));
         return 'Side connection should have completed 3 keep-alive requests '
              . '(connection closed early?).';
      }

      foreach ($sideResponses as $i => $response) {
         $n = $i + 1;

         if ( ! str_contains($response, 'hit=') ) {
            Vars::$labels = ["Same-connection response #{$n} (truncated):"];
            dump(json_encode(substr($response, 0, 300)));
            return "Same-connection request #{$n} did not reach the handler.";
         }

         if ( ! str_contains($response, 'contaminated=[]') ) {
            Vars::$labels = ["Same-connection response #{$n} (truncated):"];
            dump(json_encode(substr($response, 0, 300)));
            return "Request #{$n} on the SAME connection observed state from "
                 . 'a previous request (Request::assume() failed to scrub — '
                 . 'same-connection cross-request leak).';
         }
      }

      // @ Cross-connection sanity (request 4 on the harness connection).
      $victim = $responses[0] ?? '';

      if ($victim === '') {
         return 'Harness connection received no response.';
      }

      if ( ! str_contains($victim, 'contaminated=[]') ) {
         Vars::$labels = ['Harness-connection response (truncated):'];
         dump(json_encode(substr($victim, 0, 300)));
         return 'Request on a SEPARATE connection observed state from another '
              . 'connection (per-connection instance not isolated).';
      }

      return true;
   }
);
