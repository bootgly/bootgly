<?php

use function fclose;
use function fread;
use function fwrite;
use function is_resource;
use function json_encode;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function str_contains;
use function substr;
use function usleep;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — Decoder_Chunked shares state across connections (Audit finding #1).
 *
 * Attack scenario:
 *   Connection A sends `Transfer-Encoding: chunked` headers and a partial chunk
 *   (no terminating "0\r\n\r\n"). The server switches the global
 *   `HTTP_Server_CLI::$Decoder` to a single `Decoder_Chunked` instance and
 *   `$Request->Body->waiting = true`. Because the decoder state lives in
 *   `self::$buffer`, `self::$body`, `self::$totalSize`, etc. (class statics),
 *   every subsequent byte coming into this worker — regardless of which
 *   connection it belongs to — is fed into the same state machine.
 *
 *   Connection B (victim) sends a benign `GET /victim`. Its bytes are consumed
 *   by `Decoder_Chunked::decode()` (wrong decoder) → `hexdec("GET ...")`
 *   produces a bogus chunk size, the victim's handler never runs, and the
 *   victim observes silence or a malformed 400 response.
 *
 * Expected (fixed) behavior: decoder state is per-connection, so the victim's
 * GET is parsed correctly and returns "VICTIM /victim".
 *
 * This test therefore PASSES only when the framework has been fixed.
 */

$attackerConnection = null;

return new Specification(
   description: 'Decoder_Chunked static state must not leak across connections',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort) use (&$attackerConnection): string {
         // ! Attacker opens connection A and sends chunked headers + a partial
         //   chunk (no terminator). The server commits its global decoder.
         $attackerConnection = stream_socket_client(
            "tcp://{$hostPort}", $errno, $errstr, timeout: 5
         );
         if ($attackerConnection === false) {
            return "GET /victim HTTP/1.1\r\nHost: localhost\r\n\r\n";
         }

         fwrite(
            $attackerConnection,
            "POST /attacker HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "5\r\nAAAAA\r\n"
            // ! Intentionally NO "0\r\n\r\n" terminator — body is left pending.
         );
         // @ Let the event loop consume A's bytes before B arrives.
         usleep(150_000);

         // : Victim request on the main connection B.
         return "GET /victim HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/attacker', function (Request $Request, Response $Response) {
         return $Response(body: 'attacker ok');
      }, POST);

      yield $Router->route('/victim', function (Request $Request, Response $Response) {
         return $Response(body: 'VICTIM ' . $Request->method . ' ' . $Request->URI);
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) use (&$attackerConnection) {
      // @ Cleanup attacker connection
      if (is_resource($attackerConnection)) {
         @fclose($attackerConnection);
      }

      $victim = $responses[0] ?? '';

      if ($victim === '') {
         return 'Victim received no response — decoder state bleed from attacker connection consumed all bytes.';
      }

      if ( ! str_contains($victim, '200 OK')) {
         Vars::$labels = ['Victim HTTP Response (truncated):'];
         dump(json_encode(substr($victim, 0, 300)));
         return 'Victim GET /victim should have received 200 OK. '
              . 'A non-200 means attacker Decoder_Chunked consumed/corrupted the victim request.';
      }

      if ( ! str_contains($victim, 'VICTIM GET /victim')) {
         Vars::$labels = ['Victim HTTP Response (truncated):'];
         dump(json_encode(substr($victim, 0, 300)));
         return 'Victim handler did not observe "GET /victim" — '
              . 'request was mangled by cross-connection decoder state.';
      }

      return true;
   }
);
