<?php

use function is_array;
use function json_encode;
use function str_contains;
use function substr;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Decoder_::$inputs` shallow-clones the cached Request: sub-objects
 * (`Header`, `Body`, `Cookies`) remain SHARED with the cache template, so a
 * handler that mutates `$Request->Header->append(...)` (or `$Request->Body`)
 * contaminates every future connection that sends byte-identical headers.
 *
 * This is the residual vector after the clone-on-READ fix for audit #2:
 *   Request has `public Header $Header`; PHP's default `clone` copies the
 *   property SLOT but keeps the same object reference. The audit's stated
 *   `$this->Session`'s "set-cookie side-effect appended to Header->Cookies"
 *   generalises to any Header/Body mutation.
 *
 * Attack scenario:
 *   Attacker connection 1: handler calls
 *     `$Request->Header->append('X-Tenant-Override', 'attacker')`.
 *   Victim connection 2: identical bytes → cache hit → clone-on-READ; but the
 *     cloned Request STILL points at the same `Header` object → victim reads
 *     `X-Tenant-Override: attacker`.
 *
 * Expected (fixed) behaviour: `__clone()` deep-clones mutable sub-objects so
 *   connection 2 starts with a fresh Header (only decoded fields, no handler
 *   additions).
 */

$primingResponses = [];

return new Specification(
   description: 'Decoder_::$inputs must not share Header/Body across connections',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort) use (&$primingResponses): string {
         $bytes = "GET /header-bleed HTTP/1.1\r\nHost: localhost\r\n\r\n";

         // ! Priming attacker connections populate the cache and mutate the
         //   shared Header object.
         for ($i = 0; $i < 2; $i++) {
            $attacker = @\stream_socket_client(
               "tcp://{$hostPort}", $errno, $errstr, timeout: 5
            );
            if (! \is_resource($attacker)) {
               break;
            }
            \stream_set_blocking($attacker, true);
            \stream_set_timeout($attacker, 2);
            \fwrite($attacker, $bytes);

            $deadline = \microtime(true) + 2.0;
            $buf = '';
            while (\microtime(true) < $deadline) {
               $chunk = @\fread($attacker, 65535);
               if ($chunk === false || $chunk === '') {
                  if (@\feof($attacker) || str_contains($buf, "\r\n\r\n")) break;
                  continue;
               }
               $buf .= $chunk;
               if (str_contains($buf, "\r\n\r\n")) break;
            }
            $primingResponses[] = $buf;
            @\fclose($attacker);
         }

         // : Victim sends identical bytes on the harness's fresh connection.
         return $bytes;
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/header-bleed', function (Request $Request, Response $Response) {
         // @ Surface any leaked Body state from a prior connection.
         //   On a fresh/unbled Request, `Body->raw` is untouched (empty).
         if ($Request->Body->raw !== '') {
            return $Response(code: 200, body: 'LEAKED:' . $Request->Body->raw);
         }

         // @ Annotate this Request's Body (handler/middleware mutation).
         //   With shallow clone this mutates the SHARED cache template →
         //   next identical request clones the template that still points to
         //   the same Body object.
         $Request->Body->raw = 'attacker-tenant';

         return $Response(code: 200, body: 'CLEAN');
      });

      // @ Keep earlier suite routes alive (handler queue pops).
      yield $Router->route('/cache-bleed', function (Request $Request, Response $Response) {
         if (isset($Request->leaked)) {
            return $Response(code: 200, body: 'LEAKED:' . (string) $Request->leaked);
         }
         $Request->leaked = 'attacker-tenant';
         return $Response(code: 200, body: 'CLEAN');
      }, GET);
      yield $Router->route('/chunked', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'CHUNK-HANDLED');
      }, POST);
      yield $Router->route('/session-takeover', function (Request $Request, Response $Response) {
         $role = $Request->Session->get('role', 'none');
         return $Response(code: 200, body: 'SESSION_ROLE:' . (string) $role);
      }, GET);
      yield $Router->route('/smuggle', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'SHOULD-NOT-REACH-HANDLER');
      }, POST);
      yield $Router->route('/smuggled', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'SMUGGLED-REACHED');
      }, GET);
      yield $Router->route('/upload', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'UPLOAD-HANDLED');
      }, POST);
      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response): bool|string {
      $responses = is_array($response) ? $response : [$response];
      foreach ($responses as $resp) {
         if ($resp === '') {
            return 'Victim request: no response from server.';
         }

         if (str_contains($resp, 'LEAKED:')) {
            Vars::$labels = ['Victim response (Header leak):'];
            dump(json_encode(substr($resp, 0, 400)));
            return 'Decoder_ cache shallow-cloned Request: Header mutation '
               . 'on a prior connection leaked into the victim connection. '
               . '__clone() must deep-clone Header/Body/Cookies.';
         }
      }

      return true;
   }
);
