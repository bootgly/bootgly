<?php

use function fclose;
use function feof;
use function fread;
use function fwrite;
use function is_resource;
use function json_encode;
use function str_contains;
use function strlen;
use function stream_get_meta_data;
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
 * PoC — first-position Content-Length is ignored.
 *
 * Attack:
 *   Request::decode() looked for `\r\nContent-Length:` only. When
 *   Content-Length is the first field after the request-line, there is no
 *   preceding CRLF in `$header_raw`, so the decoder treats the request as
 *   body-less. The bytes that a proxy/client considers the POST body are
 *   then reinterpreted by Bootgly as a pipelined request.
 *
 * Expected (fixed) behaviour: the declared body bytes are consumed as body;
 * `GET /first-header-smuggled` must never be dispatched as a second request.
 * The normal Security harness injects `X-Bootgly-Test` right after the
 * request-line, so this PoC uses a raw side connection and places the test
 * dispatch header after Content-Length/Host. That keeps Content-Length as the
 * first real header while still routing to this Specification's handler.
 */

$probe = [
   'response' => '',
];

return new Specification(
   description: 'First-position Content-Length must frame the body, not smuggle a pipelined request',
   Separator: new Separator(line: true),

   request: function (string $hostPort, int $testIndex = 0) use (&$probe): string {
      $body = "GET /first-header-smuggled HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "\r\n";

      $raw = "POST /first-header-cl HTTP/1.1\r\n"
         . "Content-Length: " . strlen($body) . "\r\n"
         . "Host: localhost\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "\r\n"
         . $body;

      $socket = @stream_socket_client(
         "tcp://{$hostPort}", $errno, $errstr, timeout: 5
      );
      if (is_resource($socket)) {
         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 2);

         @fwrite($socket, $raw);

         $response = '';
         while (true) {
            $chunk = @fread($socket, 65535);
            if ($chunk === false || $chunk === '') {
               if (@feof($socket)) {
                  break;
               }
               $meta = stream_get_meta_data($socket);
               if (($meta['timed_out'] ?? false) === true) {
                  break;
               }
               continue;
            }

            $response .= $chunk;
            if (str_contains($response, 'SMUGGLED-FIRST-HEADER-CL')) {
               break;
            }
         }

         @fclose($socket);
         $probe['response'] = $response;
      }

      return "GET /first-header-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/first-header-cl', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'FIRST-REQUEST');
      }, POST);

      yield $Router->route('/first-header-smuggled', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'SMUGGLED-FIRST-HEADER-CL');
      }, GET);

      yield $Router->route('/first-header-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         Vars::$labels = ['Harness response'];
         dump(json_encode(substr($response, 0, 300)));
         return 'Harness request did not reach /first-header-harness.';
      }

      $response = $probe['response'];

      if ($response === '') {
         return 'Side-connection returned no response — PoC could not read the response.';
      }

      if (str_contains($response, 'SMUGGLED-FIRST-HEADER-CL')) {
         Vars::$labels = ['HTTP Response (truncated):'];
         dump(json_encode(substr($response, 0, 400)));
         return 'GET /first-header-smuggled was dispatched as a pipelined '
            . 'request because first-position Content-Length was ignored.';
      }

      return true;
   }
);
