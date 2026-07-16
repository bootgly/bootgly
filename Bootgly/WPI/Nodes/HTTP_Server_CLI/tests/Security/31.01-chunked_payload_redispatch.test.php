<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — decoded chunk payload length must not become the raw pipeline cursor.
 *
 * The request head is sent separately so the worker installs Decoder_Chunked.
 * In the attack body, decoded payload length 110 equals the raw wire offset at
 * which a complete GET begins inside chunk data. A correct decoder must treat
 * that GET as POST body and start pipelining only after the terminal chunk.
 */

$attackTarget = "GET /h2-smuggled-1234567 HTTP/1.1\r\n"
   . "Host: localhost\r\n"
   . "\r\n";
$controlTarget = "GET /h2-control-1234567 HTTP/1.1\r\n"
   . "Host: localhost\r\n"
   . "\r\n";
$chunkPrefix = str_repeat("1\r\nA\r\n", 10);

$attackLarge = str_repeat('B', 46) . $attackTarget;
$controlLarge = str_repeat('B', 47) . $controlTarget;
$attackDecoded = str_repeat('A', 10) . $attackLarge;
$controlDecoded = str_repeat('A', 10) . $controlLarge;
$attackChunked = $chunkPrefix . "64\r\n" . $attackLarge . "\r\n0\r\n\r\n";
$controlChunked = $chunkPrefix . "64\r\n" . $controlLarge . "\r\n0\r\n\r\n";
$next = "GET /h2-next HTTP/1.1\r\n"
   . "Host: localhost\r\n"
   . "Connection: close\r\n"
   . "\r\n";

$probe = [
   'error' => '',
   'controlError' => '',
   'attackResponse' => '',
   'controlResponse' => '',
   'decodedLength' => strlen($attackDecoded),
   'attackOffset' => strlen($chunkPrefix . "64\r\n" . str_repeat('B', 46)),
   'chunkedLength' => strlen($attackChunked),
];

return new Specification(
   description: 'Chunk payload bytes must not be redispatched through the raw pipeline cursor',
   Separator: new Separator(line: true),

   request: function (string $hostPort, int $testIndex = 0) use (
      &$probe,
      $attackChunked,
      $controlChunked,
      $next,
   ): string {
      $Transmit = static function (string $chunked, bool $attack) use (
         $hostPort,
         $testIndex,
         $next,
      ): array {
         $head = "POST /h2-original HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "\r\n";
         $tail = $chunked . $next;

         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );

         if (! is_resource($socket)) {
            return [
               "Could not connect to {$hostPort}: {$errorNumber} {$errorMessage}",
               '',
            ];
         }

         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);

         if (@fwrite($socket, $head) !== strlen($head)) {
            @fclose($socket);
            return ['Could not write the complete chunked request head.', ''];
         }

         // Give the worker time to install Decoder_Chunked before the wire
         // body and the legitimate following request arrive in a later read.
         usleep(250_000);

         if (@fwrite($socket, $tail) !== strlen($tail)) {
            @fclose($socket);
            return ['Could not write the complete chunked body/pipeline tail.', ''];
         }

         $response = '';
         while (true) {
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
            if ($attack) {
               if (
                  str_contains($response, 'H2-SMUGGLED')
                  || str_contains($response, 'H2-NEXT')
               ) {
                  break;
               }
            }
            else if (
               str_contains($response, 'H2-ORIGINAL')
               || str_contains($response, 'H2-CONTROL-ROUTED')
            ) {
               break;
            }
         }

         @fclose($socket);
         return ['', $response];
      };

      [$probe['controlError'], $probe['controlResponse']] = $Transmit(
         $controlChunked,
         false,
      );
      [$probe['error'], $probe['attackResponse']] = $Transmit(
         $attackChunked,
         true,
      );

      return "GET /h2-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) use (
      $attackDecoded,
      $controlDecoded,
   ) {
      yield $Router->route('/h2-original', function (Request $Request, Response $Response) use (
         $attackDecoded,
         $controlDecoded,
      ) {
         $valid = in_array(
            $Request->Body->raw,
            [$attackDecoded, $controlDecoded],
            true,
         );

         return $Response(
            code: 200,
            body: $valid ? 'H2-ORIGINAL' : 'H2-BODY-MISMATCH',
         );
      }, POST);

      yield $Router->route('/h2-smuggled-1234567', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H2-SMUGGLED');
      }, GET);

      yield $Router->route('/h2-control-1234567', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H2-CONTROL-ROUTED');
      }, GET);

      yield $Router->route('/h2-next', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H2-NEXT');
      }, GET);

      yield $Router->route('/h2-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'Harness request did not reach /h2-harness.';
      }

      if ($probe['decodedLength'] !== 110 || $probe['attackOffset'] !== 110) {
         Vars::$labels = ['H2 PoC geometry'];
         dump(json_encode($probe));
         return 'The PoC no longer aligns decoded payload length with the raw GET offset.';
      }

      if ($probe['controlError'] !== '') {
         return 'A/B control: ' . $probe['controlError'];
      }

      if (! str_contains($probe['controlResponse'], 'H2-ORIGINAL')) {
         Vars::$labels = ['H2 control response'];
         dump(json_encode(substr($probe['controlResponse'], 0, 800)));
         return 'A/B control did not complete the original chunked POST.';
      }

      if (str_contains($probe['controlResponse'], 'H2-CONTROL-ROUTED')) {
         Vars::$labels = ['H2 control response'];
         dump(json_encode(substr($probe['controlResponse'], 0, 800)));
         return 'A/B control unexpectedly routed the deliberately misaligned payload request.';
      }

      if ($probe['error'] !== '') {
         return $probe['error'];
      }

      if ($probe['attackResponse'] === '') {
         return 'The aligned split-write connection returned no response.';
      }

      if (str_contains($probe['attackResponse'], 'H2-SMUGGLED')) {
         Vars::$labels = ['H2 attack response'];
         dump(json_encode([
            ...$probe,
            'attackResponse' => substr($probe['attackResponse'], 0, 800),
            'controlResponse' => substr($probe['controlResponse'], 0, 800),
         ]));
         return 'CONFIRMED: chunk payload bytes were redispatched as GET /h2-smuggled-1234567. '
            . "Geometry: decoded={$probe['decodedLength']}, "
            . "raw_get_offset={$probe['attackOffset']}, "
            . "chunked_wire={$probe['chunkedLength']}. "
            . 'Control response: ' . json_encode(substr($probe['controlResponse'], 0, 800)) . '. '
            . 'Attack response: ' . json_encode(substr($probe['attackResponse'], 0, 800));
      }

      if (str_contains($probe['attackResponse'], 'H2-BODY-MISMATCH')) {
         return 'The original POST did not receive the exact decoded chunk payload.';
      }

      if (! str_contains($probe['attackResponse'], 'H2-ORIGINAL')) {
         return 'The server neither redispatched the payload nor completed the original POST.';
      }

      if (substr_count($probe['attackResponse'], 'H2-ORIGINAL') !== 1) {
         return 'The original chunked POST was dispatched more than once.';
      }

      if (! str_contains($probe['attackResponse'], 'H2-NEXT')) {
         return 'Bytes after the terminal chunk were not preserved for HTTP pipelining.';
      }

      return true;
   }
);
