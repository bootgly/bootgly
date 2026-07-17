<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security regression M3 — encoder-owned response framing across HEAD,
 * HTTP/1.0 and compressed HTTP/1.1 bodies.
 */

$payload = 'Bootgly-M3-compressed-response';

return new Specification(
   description: 'Encoder-owned framing must cover HEAD, HTTP/1.0 and compression',
   Separator: new Separator(line: true),

   requests: [
      function (): string {
         return "HEAD /m3-head HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function (): string {
         return "GET /m3-http10 HTTP/1.0\r\n\r\n";
      },
      function (): string {
         return "GET /m3-compressed HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) use ($payload) {
      yield $Router->route('/m3-head', function (Request $Request, Response $Response) {
         return $Response(
            headers: [
               'content-length' => '999',
               'transfer-encoding' => 'chunked',
               'X-M3-Mode' => 'head',
            ],
            body: 'hello',
         );
      });

      yield $Router->route('/m3-http10', function (Request $Request, Response $Response) {
         return $Response(
            headers: [
               'content-length' => '999',
               'transfer-encoding' => 'chunked',
               'X-M3-Mode' => 'http10',
            ],
            body: 'hello',
         );
      });

      yield $Router->route('/m3-compressed', function (Request $Request, Response $Response) use ($payload) {
         $compressed = $Response->compress($payload);
         if (! is_string($compressed)) {
            return $Response(code: 500, body: 'compression failed');
         }

         return $Response(
            headers: [
               'content-length' => '999',
               'transfer-encoding' => 'chunked',
               'X-M3-Mode' => 'compressed',
            ],
            body: $compressed,
         );
      });
   },

   test: function (array $responses) use ($payload): bool|string {
      if (count($responses) !== 3) {
         return 'M3 framing-mode regression did not receive all three responses.';
      }

      $Inspect = static function (string $response): array {
         $separator = strpos($response, "\r\n\r\n");
         if ($separator === false) {
            return ['', [], ''];
         }

         $head = substr($response, 0, $separator);
         $lines = explode("\r\n", $head);
         $status = array_shift($lines) ?? '';
         $headers = [];

         foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
               continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $headers[$name][] = trim(substr($line, $colon + 1));
         }

         return [$status, $headers, substr($response, $separator + 4)];
      };

      [$headStatus, $headHeaders, $headBody] = $Inspect($responses[0]);
      if (
         $headStatus !== 'HTTP/1.1 200 OK'
         || ($headHeaders['content-length'] ?? []) !== ['5']
         || ($headHeaders['transfer-encoding'] ?? []) !== []
         || ($headHeaders['x-m3-mode'] ?? []) !== ['head']
         || $headBody !== ''
      ) {
         Vars::$labels = ['M3 HEAD framing evidence'];
         dump(json_encode([$headStatus, $headHeaders, $headBody]));

         return 'HEAD response did not retain one representation length while suppressing its body.';
      }

      [$HTTP10Status, $HTTP10Headers, $HTTP10Body] = $Inspect($responses[1]);
      if (
         $HTTP10Status !== 'HTTP/1.0 200 OK'
         || ($HTTP10Headers['content-length'] ?? []) !== ['5']
         || ($HTTP10Headers['transfer-encoding'] ?? []) !== []
         || ($HTTP10Headers['x-m3-mode'] ?? []) !== ['http10']
         || $HTTP10Body !== 'hello'
      ) {
         Vars::$labels = ['M3 HTTP/1.0 framing evidence'];
         dump(json_encode([$HTTP10Status, $HTTP10Headers, $HTTP10Body]));

         return 'HTTP/1.0 response retained application framing or lost its canonical length.';
      }

      [$compressedStatus, $compressedHeaders, $compressedBody] = $Inspect($responses[2]);
      if (
         $compressedStatus !== 'HTTP/1.1 200 OK'
         || ($compressedHeaders['content-length'] ?? []) !== [(string) strlen($compressedBody)]
         || ($compressedHeaders['transfer-encoding'] ?? []) !== []
         || ($compressedHeaders['content-encoding'] ?? []) !== ['gzip']
         || ($compressedHeaders['x-m3-mode'] ?? []) !== ['compressed']
         || gzdecode($compressedBody) !== $payload
      ) {
         Vars::$labels = ['M3 compressed framing evidence'];
         dump(json_encode([
            $compressedStatus,
            $compressedHeaders,
            strlen($compressedBody),
            gzdecode($compressedBody),
         ]));

         return 'Compressed response was not framed by its encoded wire-body length.';
      }

      return true;
   },
);
