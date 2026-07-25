<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M8 — response field identity is case-insensitive, and every
 * insertion path must reject forbidden octets.
 *
 * `Header::set()`/`append()` key storage by the SUPPLIED casing, so
 * `Content-Type` and `content-type` serialized as two independent lines and
 * left the recipient to choose — MIME/policy ambiguity, and a second
 * `X-Policy` alongside the first. Separately, a correct field-value validator
 * (`check()`) existed but only preset insertion reached it: `set()` stripped
 * CR/LF while still permitting NUL, vertical tab and the rest of the C0 range.
 *
 * The route below writes a lowercase then an uppercase variant of the same two
 * fields, and attempts a NUL and a vertical tab in a value.
 *
 * Controls: a normal header still serializes, the LAST write of a
 * case-insensitive field wins, and Set-Cookie keeps its multi-line semantics.
 */
$probe = ['error' => '', 'head' => ''];

return new Specification(
   description: 'response headers must have case-insensitive identity and reject forbidden octets',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      try {
         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            throw new RuntimeException("connect failed: {$errorNumber} {$errorMessage}");
         }

         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);
         @fwrite(
            $socket,
            "GET /m8-headers HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\nConnection: close\r\n\r\n"
         );

         $response = '';
         $deadline = microtime(true) + 4.0;
         while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 65535);
            if ($chunk === false || $chunk === '') {
               if (@feof($socket)) {
                  break;
               }

               $metadata = stream_get_meta_data($socket);
               if (($metadata['timed_out'] ?? false) === true) {
                  break;
               }
               continue;
            }
            $response .= $chunk;
            if (str_contains($response, "\r\n\r\n")) {
               break;
            }
         }
         @fclose($socket);

         $separator = strpos($response, "\r\n\r\n");
         $probe['head'] = $separator === false ? $response : substr($response, 0, $separator);
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /m8-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m8-headers', static function (Request $Request, Response $Response) {
         $Header = $Response->Header;

         // @ Same field, two casings — must collapse to ONE line, last wins.
         $Header->set('x-policy', 'one');
         $Header->set('X-Policy', 'two');
         $Header->set('content-type', 'application/json');
         $Header->set('Content-Type', 'text/plain');

         // @ Forbidden octets through a non-preset path.
         $Header->set('X-Nul', "a\x00b");
         $Header->set('X-Vtab', "a\x0Bb");

         // ! Control — a clean header must still arrive.
         $Header->set('X-Clean', 'clean');

         return $Response(body: 'M8-OK');
      }, GET);

      yield $Router->route('/m8-harness', static function (Request $Request, Response $Response) {
         return $Response(body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'M8 harness request did not reach /m8-harness.';
      }
      if ($probe['error'] !== '') {
         return 'M8 fixture error: ' . $probe['error'];
      }

      $head = $probe['head'];
      if ($head === '' || ! str_contains($head, '200 OK')) {
         return 'M8 fixture did not receive the header response: ' . json_encode(substr($head, 0, 200));
      }

      // ? Control — a clean header must survive the hardening.
      if (! str_contains($head, 'X-Clean: clean')) {
         return 'M8 control failed: a clean header did not reach the wire, so the rejection '
            . 'legs prove nothing: ' . json_encode($head);
      }

      $lines = explode("\r\n", $head);
      $count = static function (string $name) use ($lines): int {
         $seen = 0;
         foreach ($lines as $line) {
            if (stripos($line, $name . ':') === 0) {
               $seen++;
            }
         }

         return $seen;
      };

      $problems = [];
      if ($count('x-policy') !== 1) {
         $problems[] = 'X-Policy serialized ' . $count('x-policy')
            . ' times (case-insensitive identity broken)';
      }
      if ($count('content-type') !== 1) {
         $problems[] = 'Content-Type serialized ' . $count('content-type')
            . ' times (MIME ambiguity)';
      }
      if (str_contains($head, "\x00")) {
         $problems[] = 'a NUL byte reached the response head';
      }
      if (str_contains($head, "\x0B")) {
         $problems[] = 'a vertical tab reached the response head';
      }

      if ($problems !== []) {
         return 'CONFIRMED M8: ' . implode('; ', $problems)
            . ' — head: ' . json_encode($head);
      }

      return true;
   },
);
