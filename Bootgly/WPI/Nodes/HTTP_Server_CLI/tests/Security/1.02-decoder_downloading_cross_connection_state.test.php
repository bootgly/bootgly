<?php

use function fclose;
use function fwrite;
use function is_resource;
use function json_decode;
use function json_encode;
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
 * PoC — Decoder_Downloading shares state across connections (Audit finding #1).
 *
 * Attack scenario:
 *   Connection A begins a multipart/form-data upload — server opens a temp file
 *   handle in `self::$fileHandler` and arms the boundary parser with A's
 *   boundary. Attacker stops writing mid-part.
 *
 *   Connection B sends any request (here: a plain GET). Because
 *   `HTTP_Server_CLI::$Decoder` is still the single global `Decoder_Downloading`
 *   instance and `$Request->Body->waiting` is still true for A, B's raw HTTP
 *   bytes are appended to the state machine and streamed into A's temp file
 *   until the attacker-chosen boundary happens to appear — which it won't —
 *   meaning B's request headers/cookies/Authorization leak to disk under A's
 *   control and B itself receives no valid response.
 *
 * Expected (fixed) behavior: per-connection decoder state → B's GET is parsed
 * by `Decoder_` and returns 200 OK with the handler's body.
 */

$attackerConnection = null;

return new Specification(
   description: 'Decoder_Downloading static state must not leak across connections',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort) use (&$attackerConnection): string {
         $boundary = 'X-ATTACKER-BOUNDARY-ZZZ';
         // ! Minimum plausible body — just the opening boundary + one partial
         //   part, with a huge Content-Length so the server keeps waiting.
         $bodyPrefix =
            "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"leak\"; filename=\"leak.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "\r\n"
            . "A"; // 1 byte of "real" body

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
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            // @ Declare a huge body so the server stays in streaming state.
            . "Content-Length: 1048576\r\n"
            . "\r\n"
            . $bodyPrefix
         );
         usleep(150_000);

         // : Victim on main connection, carrying a secret header that must NOT
         //   end up in the attacker's temp file.
         return
            "GET /victim HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Authorization: Bearer VICTIM-SECRET-TOKEN\r\n"
            . "\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/attacker', function (Request $Request, Response $Response) {
         return $Response(body: 'attacker ok');
      }, POST);

      yield $Router->route('/victim', function (Request $Request, Response $Response) {
         $auth = $Request->Header->get('Authorization') ?? '';
         return $Response->Json->send([
            'method' => $Request->method,
            'uri'    => $Request->URI,
            'auth'   => $auth,
         ]);
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) use (&$attackerConnection) {
      if (is_resource($attackerConnection)) {
         @fclose($attackerConnection);
      }

      $victim = $responses[0] ?? '';

      if ($victim === '') {
         return 'Victim received no response — streaming decoder consumed victim bytes into attacker upload.';
      }

      if ( ! str_contains($victim, '200 OK')) {
         Vars::$labels = ['Victim HTTP Response (truncated):'];
         dump(json_encode(substr($victim, 0, 400)));
         return 'Victim GET /victim should have returned 200 OK. '
              . 'Non-200 implies cross-connection multipart decoder state bleed.';
      }

      $parts = explode("\r\n\r\n", $victim, 2);
      $body = $parts[1] ?? '';
      $decoded = json_decode($body, true);

      if ( ! is_array($decoded)) {
         Vars::$labels = ['Victim body:'];
         dump(json_encode($body));
         return 'Victim body is not JSON — corrupted by attacker decoder state.';
      }

      if (($decoded['method'] ?? null) !== 'GET' || ($decoded['uri'] ?? null) !== '/victim') {
         return 'Victim request misparsed: ' . json_encode($decoded);
      }

      if (($decoded['auth'] ?? null) !== 'Bearer VICTIM-SECRET-TOKEN') {
         return 'Victim Authorization header not observed by its own handler — '
              . 'bytes were diverted into attacker upload pipe.';
      }

      return true;
   }
);
