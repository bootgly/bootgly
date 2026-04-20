<?php

use function json_encode;
use function str_contains;
use function strlen;
use function strpos;
use function substr;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — one-shot query-key churn evicts hot Decoder_ L1 entries.
 *
 * Probe strategy:
 * 1) BASE request twice (same bytes) to warm and capture baseline TIME marker.
 * 2) Flood many unique one-shot query keys once each.
 * 3) BASE again and compare TIME marker.
 */

$probe = [
   'baseline' => null,
   'afterFlood' => null,
   'samples' => [],
];

return new Specification(
   description: 'Decoder_ cache must resist one-shot query-key churn',
   Separator: new Separator(line: true),

   request: function (string $hostPort) use (&$probe): string {
      $base = "GET /cache-eviction-dos HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";

      $send = static function (string $raw) use ($hostPort): string {
         $socket = @\stream_socket_client(
            "tcp://{$hostPort}", $errno, $errstr, timeout: 5
         );
         if (! \is_resource($socket)) {
            return '';
         }

         \stream_set_blocking($socket, true);
         \stream_set_timeout($socket, 2);

         @\fwrite($socket, $raw);

         $buffer = '';
         while (true) {
            $chunk = @\fread($socket, 65535);
            if ($chunk === false || $chunk === '') {
               if (@\feof($socket)) {
                  break;
               }
               continue;
            }

            $buffer .= $chunk;
            if (str_contains($buffer, "\r\n\r\n")) {
               break;
            }
         }

         @\fclose($socket);

         return $buffer;
      };

      $extractTime = static function (string $response): null|string {
         $marker = 'RT:';
         $position = strpos($response, $marker);
         if ($position === false) {
            return null;
         }

         $start = $position + 3;
         $value = '';
         $length = strlen($response);
         for ($i = $start; $i < $length; $i++) {
            $char = $response[$i];
            if (($char < '0' || $char > '9') && $char !== '.') {
               break;
            }
            $value .= $char;
         }

         if ($value === '') {
            return null;
         }

         return $value;
      };

      $first = $send($base);
      $second = $send($base);
      $probe['samples'][] = substr($first, 0, 180);
      $probe['samples'][] = substr($second, 0, 180);
      $probe['baseline'] = $extractTime($second) ?? $extractTime($first);

      for ($i = 0; $i < 10000; $i++) {
         $noise = "GET /cache-eviction-dos?noise={$i} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
         $send($noise);
      }

      $afterFlood = $send($base);
      $probe['samples'][] = substr($afterFlood, 0, 180);
      $probe['afterFlood'] = $extractTime($afterFlood);

      return $base;
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/cache-eviction-dos', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'RT:' . (string) $Request->time);
      }, GET);

      // @ Compatibility route for 12.01 when its priming side-connection
      //   advances the server-side handler FIFO by one slot.
      yield $Router->route('/bodyparser-poc', function (Request $Request, Response $Response) {
         return $Response(code: 413, body: '');
      }, POST);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, '200 OK')) {
         Vars::$labels = ['Harness response'];
         dump(json_encode(substr($response, 0, 220)));
         return 'Harness request did not reach /cache-eviction-dos.';
      }

      if ($probe['baseline'] === null || $probe['afterFlood'] === null) {
         Vars::$labels = ['Probe samples'];
         dump(json_encode($probe['samples']));
         return 'Could not extract TIME markers from probe responses.';
      }

      if ($probe['baseline'] !== $probe['afterFlood']) {
         Vars::$labels = ['Probe baseline/afterFlood'];
         dump(json_encode($probe));
         return 'One-shot unique query keys evicted the hot cache entry.';
      }

      return true;
   }
);
