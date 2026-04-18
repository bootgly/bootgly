<?php

use function fclose;
use function feof;
use function fread;
use function fwrite;
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
 * PoC — `Decoder_::$inputs` shared cache reuses cloned `Request` across
 * unrelated connections (Audit finding #2).
 *
 * Attack scenario:
 *   The L1 decoder cache in `Decoder_::decode()` is keyed by the raw request
 *   buffer:
 *
 *     static $inputs = [];
 *     if ($size <= 2048 && isSet($inputs[$buffer])) {
 *        Server::$Request = $inputs[$buffer];
 *        if ($Package->changed) {
 *           Server::$Request->reboot();  // only resets $_SERVER + Session
 *           ...
 *        }
 *        return $size;
 *     }
 *
 *   Since `reboot()` does NOT clear dynamic properties set by handlers or
 *   middlewares (auth user, tenant, CSRF verdict…), any two connections that
 *   send byte-identical headers (extremely common: `GET / HTTP/1.1\r\nHost: …`)
 *   SHARE the same `Request` object. The second connection observes leftover
 *   state from the first — a real privilege-escalation primitive.
 *
 * Expected (fixed) behavior: each connection parses its own Request; the
 * leaked dynamic property is not visible.
 *
 *   - Attacker connection sends `GET /cache-bleed …`. The handler tags the
 *     Request with `$Request->leaked = 'attacker-tenant'`.
 *   - Victim connection sends the SAME bytes on a fresh socket. The handler
 *     checks for `isset($Request->leaked)` — if set, cache bled cross-conn.
 */

$primingResponses = [];

return new Specification(
   description: 'Decoder_::$inputs cache must not share Request across connections',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort) use (&$primingResponses): string {
         $bytes = "GET /cache-bleed HTTP/1.1\r\nHost: localhost\r\n\r\n";

         // ! Two priming attacker connections:
         //   (1) populates Decoder_::$inputs with a cloned Request.
         //   (2) hits the cache → handler mutates the cached object
         //       (sets $Request->leaked = 'attacker-tenant').
         //   The victim's request (returned below) then runs on a third,
         //   separate connection that should NOT observe the leaked tag.
         for ($i = 0; $i < 2; $i++) {
            $attacker = stream_socket_client(
               "tcp://{$hostPort}", $errno, $errstr, timeout: 5
            );
            if (! is_resource($attacker)) {
               break;
            }
            stream_set_blocking($attacker, true);
            stream_set_timeout($attacker, 2);

            fwrite($attacker, $bytes);

            $deadline = microtime(true) + 2.0;
            $buf = '';
            while (microtime(true) < $deadline) {
               $chunk = @fread($attacker, 65535);
               if ($chunk === false || $chunk === '') {
                  if (@feof($attacker) || str_contains($buf, "\r\n\r\n")) break;
                  continue;
               }
               $buf .= $chunk;
               if (str_contains($buf, "\r\n\r\n")) {
                  break;
               }
            }
            $primingResponses[] = $buf;

            @fclose($attacker);
         }

         // : Victim sends IDENTICAL bytes on the harness's fresh connection.
         //   With the cache shared, handler observes $Request->leaked from the
         //   previous, unrelated connection.
         return $bytes;
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/cache-bleed', function (Request $Request, Response $Response) {
         // ! Detect leaked state from a prior, unrelated connection.
         if (isset($Request->leaked)) {
            return $Response(
               code: 200,
               body: 'LEAKED:' . (string) $Request->leaked
            );
         }

         // @ Tag the Request with a per-connection "authentication decision".
         //   In a real app this would be $Request->user / $Request->tenant
         //   set by an auth middleware.
         $Request->leaked = 'attacker-tenant';

         return $Response(code: 200, body: 'CLEAN');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) use (&$primingResponses) {
      $victim = $responses[0] ?? '';

      if ($victim === '') {
         return 'Victim received no response — harness could not read the response.';
      }

      if (str_contains($victim, 'LEAKED:')) {
         Vars::$labels = ['Victim HTTP Response (truncated):'];
         dump(json_encode(substr($victim, 0, 300)));
         return 'Victim handler observed the attacker-set dynamic property '
              . '(Decoder_::$inputs cache reused the cloned Request across '
              . 'connections — cross-connection state leak).';
      }

      if ( ! str_contains($victim, '200 OK')) {
         Vars::$labels = [
            'Victim HTTP Response (truncated):',
            'Priming #1:', 'Priming #2:',
         ];
         dump(
            json_encode(substr($victim, 0, 300)),
            json_encode(substr($primingResponses[0] ?? '', 0, 200)),
            json_encode(substr($primingResponses[1] ?? '', 0, 200)),
         );
         return 'Victim GET /cache-bleed should have returned 200 OK.';
      }

      return true;
   }
);
