<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Regression — current-read consumption must account only for body bytes.
 *
 * Four body bytes arrive with the head. The second write contains the six
 * remaining body bytes followed by a pipelined request. The POST must receive
 * the exact ten-byte body, and the pipeline must start after the six bytes
 * consumed from the second write rather than after the total Content-Length.
 */

$probe = [
   'error' => '',
   'response' => '',
];

return new Specification(
   description: 'Partial Content-Length body completion must preserve the following pipeline',
   Separator: new Separator(line: true),

   request: function (string $hostPort, int $testIndex = 0) use (&$probe): string {
      $body = 'ABCDEFGHIJ';
      $initial = substr($body, 0, 4);
      $remaining = substr($body, 4);

      $head = "POST /c1-partial HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Content-Length: " . strlen($body) . "\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "\r\n"
         . $initial;

      $next = "GET /c1-partial-next HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";

      $socket = @stream_socket_client(
         "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
      );

      if (! is_resource($socket)) {
         $probe['error'] = "Could not connect to {$hostPort}: {$errorNumber} {$errorMessage}";
      }
      else {
         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);

         if (@fwrite($socket, $head) !== strlen($head)) {
            $probe['error'] = 'Could not write the request head and body prefix.';
         }
         else {
            usleep(250_000);
            $tail = $remaining . $next;
            if (@fwrite($socket, $tail) !== strlen($tail)) {
               $probe['error'] = 'Could not write the body remainder and pipeline.';
            }
         }

         $response = '';
         while ($probe['error'] === '') {
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
            if (str_contains($response, 'C1-PARTIAL-NEXT')) {
               break;
            }
         }

         $probe['response'] = $response;
         @fclose($socket);
      }

      return "GET /c1-partial-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/c1-partial', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'C1-PARTIAL:' . $Request->Body->raw);
      }, POST);

      yield $Router->route('/c1-partial-next', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'C1-PARTIAL-NEXT');
      }, GET);

      yield $Router->route('/c1-partial-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'Harness request did not reach /c1-partial-harness.';
      }

      if ($probe['error'] !== '') {
         return $probe['error'];
      }

      if (! str_contains($probe['response'], 'C1-PARTIAL:ABCDEFGHIJ')) {
         Vars::$labels = ['Partial-body response'];
         dump(json_encode(substr($probe['response'], 0, 600)));
         return 'The original POST did not receive its exact split body.';
      }

      if (! str_contains($probe['response'], 'C1-PARTIAL-NEXT')) {
         Vars::$labels = ['Partial-body response'];
         dump(json_encode(substr($probe['response'], 0, 600)));
         return 'The request after the split body was not pipelined.';
      }

      return true;
   }
);
