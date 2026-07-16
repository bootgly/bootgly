<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — a Content-Length body overrun must not redispatch body bytes.
 *
 * The request head is sent separately so the live worker installs
 * Decoder_Waiting. The second write starts with exactly Content-Length bytes
 * that look like a complete GET request, followed by a legitimate pipelined
 * request. A conforming server must execute the POST with those bytes as its
 * body; it must never route /c1-smuggled.
 */

$probe = [
   'error' => '',
   'headLength' => 0,
   'bodyLength' => 0,
   'tailLength' => 0,
   'response' => '',
];
$body = "GET /c1-smuggled HTTP/1.1\r\n"
   . "Host: localhost\r\n"
   . "\r\n";

return new Specification(
   description: 'Content-Length body bytes must not be redispatched after an overrun read',
   Separator: new Separator(line: true),

   request: function (string $hostPort, int $testIndex = 0) use (&$probe, $body): string {
      $next = "GET /c1-next HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";

      $head = "POST /c1-original HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Content-Length: " . strlen($body) . "\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "\r\n";

      $tail = $body . $next;

      $probe['headLength'] = strlen($head);
      $probe['bodyLength'] = strlen($body);
      $probe['tailLength'] = strlen($tail);

      $socket = @stream_socket_client(
         "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
      );

      if (! is_resource($socket)) {
         $probe['error'] = "Could not connect to {$hostPort}: {$errorNumber} {$errorMessage}";
      }
      else {
         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);

         $headWritten = @fwrite($socket, $head);
         if ($headWritten !== strlen($head)) {
            $probe['error'] = 'Could not write the complete request head.';
         }
         else {
            // Give the live worker time to decode the head and install
            // Decoder_Waiting before the overrun arrives in a later read.
            usleep(250_000);

            $tailWritten = @fwrite($socket, $tail);
            if ($tailWritten !== strlen($tail)) {
               $probe['error'] = 'Could not write the complete body/pipeline tail.';
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
            if (
               str_contains($response, 'C1-SMUGGLED')
               || (
                  str_contains($response, 'C1-ORIGINAL')
                  && str_contains($response, 'C1-NEXT')
               )
            ) {
               break;
            }
         }

         $probe['response'] = $response;
         @fclose($socket);
      }

      return "GET /c1-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) use ($body) {
      yield $Router->route('/c1-original', function (Request $Request, Response $Response) use ($body) {
         $result = $Request->Body->raw === $body
            ? 'C1-ORIGINAL'
            : 'C1-BODY-MISMATCH';

         return $Response(code: 200, body: $result);
      }, POST);

      yield $Router->route('/c1-smuggled', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'C1-SMUGGLED');
      }, GET);

      yield $Router->route('/c1-next', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'C1-NEXT');
      }, GET);

      yield $Router->route('/c1-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         Vars::$labels = ['Harness response'];
         dump(json_encode(substr($response, 0, 400)));
         return 'Harness request did not reach /c1-harness.';
      }

      if ($probe['error'] !== '') {
         Vars::$labels = ['PoC state'];
         dump(json_encode($probe));
         return $probe['error'];
      }

      if ($probe['response'] === '') {
         Vars::$labels = ['PoC state'];
         dump(json_encode($probe));
         return 'The split-write side connection returned no response.';
      }

      if (str_contains($probe['response'], 'C1-SMUGGLED')) {
         Vars::$labels = ['PoC state'];
         dump(json_encode([
            ...$probe,
            'response' => substr($probe['response'], 0, 600),
         ]));
         return 'CONFIRMED: declared Content-Length body bytes were routed as GET /c1-smuggled. '
            . 'Raw response: ' . json_encode(substr($probe['response'], 0, 600));
      }

      if (str_contains($probe['response'], 'C1-BODY-MISMATCH')) {
         Vars::$labels = ['PoC state'];
         dump(json_encode([
            ...$probe,
            'response' => substr($probe['response'], 0, 600),
         ]));
         return 'The original POST did not receive the exact declared body.';
      }

      if (! str_contains($probe['response'], 'C1-ORIGINAL')) {
         Vars::$labels = ['PoC state'];
         dump(json_encode([
            ...$probe,
            'response' => substr($probe['response'], 0, 600),
         ]));
         return 'The server neither routed the declared body nor completed the original POST.';
      }

      if (substr_count($probe['response'], 'C1-ORIGINAL') !== 1) {
         Vars::$labels = ['PoC state'];
         dump(json_encode([
            ...$probe,
            'response' => substr($probe['response'], 0, 600),
         ]));
         return 'The original POST was dispatched more than once.';
      }

      if (! str_contains($probe['response'], 'C1-NEXT')) {
         Vars::$labels = ['PoC state'];
         dump(json_encode([
            ...$probe,
            'response' => substr($probe['response'], 0, 600),
         ]));
         return 'Bytes after Content-Length were not preserved for HTTP pipelining.';
      }

      return true;
   }
);
