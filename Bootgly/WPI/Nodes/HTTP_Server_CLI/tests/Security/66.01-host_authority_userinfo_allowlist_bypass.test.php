<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M1 (2026-07-27) — a Host authority carrying userinfo must not
 * satisfy `allowedHosts`, and must never reach the application.
 *
 * `Request::allow()` lowercases the authority and, for a non-bracketed host,
 * treats everything after the LAST colon as a port without validating either
 * half. With `allowedHosts = ['localhost', ...]`, `localhost:@evil.example`
 * and `localhost:443@evil.example` both reduce to `localhost` and pass — while
 * `Request::$host` keeps the hostile value. An application interpolating that
 * into `https://{$Request->host}/reset?...` produces a URL whose effective
 * host is `evil.example`, because a URI parser reads the prefix as userinfo.
 *
 * RFC 9110 §7.2 defines Host as `uri-host [ ":" port ]` — no userinfo — so the
 * grammar is now enforced at parse time, which protects the value with or
 * without an allowlist configured.
 *
 * Controls: a plain authority, an authority with a real port, and a bracketed
 * IPv6 literal must all still be accepted.
 */
$probe = ['error' => '', 'legs' => []];

return new Specification(
   description: 'a Host authority with userinfo must not satisfy the allowlist',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $Status = static function (string $authority) use ($hostPort, $testIndex): int {
         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            return -1;
         }

         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);
         @fwrite(
            $socket,
            "GET /m1-host HTTP/1.1\r\nHost: {$authority}\r\n"
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

         return preg_match('#^HTTP/1\.1 (\d{3})#', $response, $matches) === 1
            ? (int) $matches[1]
            : 0;
      };

      try {
         // ! Attack — userinfo hidden behind the last-colon port split.
         $probe['legs']['userinfo_empty_port'] = $Status('localhost:@evil.example');
         $probe['legs']['userinfo_numeric_port'] = $Status('localhost:443@evil.example');
         $probe['legs']['userinfo_plain'] = $Status('localhost@evil.example');

         // ! Controls — legitimate authorities must keep working.
         $probe['legs']['control_plain'] = $Status('localhost');
         $probe['legs']['control_port'] = $Status('localhost:8081');
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /m1-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m1-host', static function (Request $Request, Response $Response) {
         // ! Echo the application-facing authority: this is the value that
         //   would be interpolated into an absolute URL.
         return $Response(body: 'M1-HOST:' . $Request->host);
      }, GET);

      yield $Router->route('/m1-harness', static function (Request $Request, Response $Response) {
         return $Response(body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'M1 harness request did not reach /m1-harness.';
      }
      if ($probe['error'] !== '') {
         return 'M1 fixture error: ' . $probe['error'];
      }

      $legs = $probe['legs'];

      // ? Controls — legitimate authorities must still be served.
      foreach (['control_plain', 'control_port'] as $control) {
         if (($legs[$control] ?? 0) !== 200) {
            return "M1 control `{$control}` did not return 200 (got "
               . json_encode($legs[$control] ?? null)
               . '), so the rejection legs prove nothing: ' . json_encode($legs);
         }
      }

      $accepted = [];
      foreach ([
         'userinfo_empty_port' => 'localhost:@evil.example',
         'userinfo_numeric_port' => 'localhost:443@evil.example',
         'userinfo_plain' => 'localhost@evil.example',
      ] as $leg => $authority) {
         if (($legs[$leg] ?? 0) === 200) {
            $accepted[] = $authority;
         }
      }

      if ($accepted !== []) {
         return 'CONFIRMED M1: a Host authority carrying userinfo satisfied the allowlist and '
            . 'was served — ' . implode(', ', $accepted)
            . '. The last-colon port split reduces each to the allowed name while '
            . '`Request::$host` keeps the hostile value for the application to interpolate '
            . 'into an absolute URL whose effective host is evil.example.';
      }

      return true;
   },
);
