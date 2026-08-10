<?php

use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M1 — Bootgly rejects HTTP/1.0 Transfer-Encoding and closes.
 *
 * HTTP/1.0 does not define Transfer-Encoding request framing. A frontend that
 * ignores Transfer-Encoding on HTTP/1.0 can disagree with a backend that
 * decodes it, especially when the backend also honors Connection: keep-alive.
 * Bytes after the terminal chunk can then become a second backend request.
 *
 * The attack sends a complete chunked HTTP/1.0 POST and follower in one write.
 * The real TCP pipeline redispatches the suffix through Decoder_Chunked.
 * This regression adopts Bootgly's security policy: respond with 400 before
 * route dispatch and close the connection.
 *
 * Controls prove that the live harness can decode and pipeline HTTP/1.1
 * chunked requests, and that ordinary persistent HTTP/1.0 remains supported.
 */

$probe = [
   'HTTP11Error' => '',
   'HTTP11Response' => '',
   'HTTP10Error' => '',
   'HTTP10Response' => '',
   'attackError' => '',
   'attackResponse' => '',
   'attackClosed' => false,
   'attackTimedOut' => false,
];

return new Test(
   description: 'HTTP/1.0 Transfer-Encoding returns 400 and closes before dispatch',
   Separator: new Separator(line: true),

   request: function (string $hostPort, int $testIndex = 0) use (&$probe): string {
      /**
       * @param array<int,string> $stops
       * @return array{error:string,response:string,closed:bool,timedOut:bool}
       */
      $Transmit = static function (
         string $raw,
         array $stops
      ) use ($hostPort): array {
         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            return [
               'error' => "Could not connect to {$hostPort}: {$errorNumber} {$errorMessage}",
               'response' => '',
               'closed' => false,
               'timedOut' => false,
            ];
         }

         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);

         $written = @fwrite($socket, $raw);
         if ($written !== strlen($raw)) {
            @fclose($socket);
            return [
               'error' => "Could not write the complete probe: {$written}/"
                  . strlen($raw),
               'response' => '',
               'closed' => false,
               'timedOut' => false,
            ];
         }

         $response = '';
         $timedOut = false;
         while (true) {
            $chunk = @fread($socket, 65535);
            if ($chunk === false) {
               @fclose($socket);
               return [
                  'error' => 'Could not read the probe response.',
                  'response' => $response,
                  'closed' => false,
                  'timedOut' => false,
               ];
            }

            if ($chunk === '') {
               if (@feof($socket)) {
                  break;
               }

               $metadata = stream_get_meta_data($socket);
               if (($metadata['timed_out'] ?? false) === true) {
                  $timedOut = true;
                  break;
               }

               continue;
            }

            $response .= $chunk;
            foreach ($stops as $stop) {
               if (str_contains($response, $stop)) {
                  break 2;
               }
            }
         }

         $closed = @feof($socket);
         @fclose($socket);

         return [
            'error' => '',
            'response' => $response,
            'closed' => $closed,
            'timedOut' => $timedOut,
         ];
      };

      // ! Control A — HTTP/1.1 owns chunked request framing and may persist
      //   for a complete follower.
      $HTTP11 = $Transmit(
         "POST /m1-http11-control HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n"
            . "1\r\nA\r\n0\r\n\r\n"
            . "GET /m1-http11-next HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\n"
            . "Connection: close\r\n"
            . "\r\n",
         ['M1-HTTP11-NEXT']
      );
      $probe['HTTP11Error'] = $HTTP11['error'];
      $probe['HTTP11Response'] = $HTTP11['response'];

      // ! Control B — HTTP/1.0 itself remains supported and may persist when
      //   Connection: keep-alive is explicit.
      $HTTP10 = $Transmit(
         "GET /m1-http10-control HTTP/1.0\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n"
            . "GET /m1-http10-next HTTP/1.0\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\n"
            . "Connection: close\r\n"
            . "\r\n",
         ['M1-HTTP10-NEXT']
      );
      $probe['HTTP10Error'] = $HTTP10['error'];
      $probe['HTTP10Response'] = $HTTP10['response'];

      // @ Attack — HTTP/1.0 must reject Transfer-Encoding before the body
      //   decoder or either application route can consume these bytes.
      $attack = $Transmit(
         "POST /m1-http10-attack HTTP/1.0\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n"
            . "1\r\nA\r\n0\r\n\r\n"
            . "GET /m1-http10-smuggled HTTP/1.0\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\n"
            . "Connection: close\r\n"
            . "\r\n",
         ['M1-ATTACK-SMUGGLED']
      );
      $probe['attackError'] = $attack['error'];
      $probe['attackResponse'] = $attack['response'];
      $probe['attackClosed'] = $attack['closed'];
      $probe['attackTimedOut'] = $attack['timedOut'];

      return "GET /m1-http10-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route(
         '/m1-http11-control',
         function (Request $Request, Response $Response) {
            return $Response(
               code: 200,
               body: "M1-HTTP11-BODY:{$Request->protocol}:{$Request->Body->raw}"
            );
         },
         POST
      );

      yield $Router->route(
         '/m1-http11-next',
         function (Request $Request, Response $Response) {
            return $Response(
               code: 200,
               body: "M1-HTTP11-NEXT:{$Request->protocol}"
            );
         },
         GET
      );

      yield $Router->route(
         '/m1-http10-control',
         function (Request $Request, Response $Response) {
            return $Response(
               code: 200,
               body: "M1-HTTP10-ORIGINAL:{$Request->protocol}"
            );
         },
         GET
      );

      yield $Router->route(
         '/m1-http10-next',
         function (Request $Request, Response $Response) {
            return $Response(
               code: 200,
               body: "M1-HTTP10-NEXT:{$Request->protocol}"
            );
         },
         GET
      );

      yield $Router->route(
         '/m1-http10-attack',
         function (Request $Request, Response $Response) {
            return $Response(
               code: 200,
               body: "M1-ATTACK-BODY:{$Request->protocol}:{$Request->Body->raw}"
            );
         },
         POST
      );

      yield $Router->route(
         '/m1-http10-smuggled',
         function (Request $Request, Response $Response) {
            return $Response(
               code: 200,
               body: "M1-ATTACK-SMUGGLED:{$Request->protocol}"
            );
         },
         GET
      );

      yield $Router->route(
         '/m1-http10-harness',
         function (Request $Request, Response $Response) {
            return $Response(code: 200, body: 'M1-HARNESS-OK');
         },
         GET
      );

      yield $Router->route(
         '/*',
         function (Request $Request, Response $Response) {
            return $Response(code: 404, body: 'Not Found');
         }
      );
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'M1-HARNESS-OK')) {
         return 'M1 harness request did not reach its registered route: '
            . json_encode(substr($response, 0, 300));
      }

      if ($probe['HTTP11Error'] !== '') {
         return 'M1 HTTP/1.1 control transport failed: ' . $probe['HTTP11Error'];
      }
      if (
         ! str_contains(
            $probe['HTTP11Response'],
            'M1-HTTP11-BODY:HTTP/1.1:A'
         )
         || ! str_contains(
            $probe['HTTP11Response'],
            'M1-HTTP11-NEXT:HTTP/1.1'
         )
      ) {
         return 'M1 HTTP/1.1 control did not decode and pipeline both requests: '
            . json_encode(substr($probe['HTTP11Response'], 0, 500));
      }

      if ($probe['HTTP10Error'] !== '') {
         return 'M1 HTTP/1.0 control transport failed: ' . $probe['HTTP10Error'];
      }
      if (
         ! str_contains(
            $probe['HTTP10Response'],
            'M1-HTTP10-ORIGINAL:HTTP/1.0'
         )
         || ! str_contains(
            $probe['HTTP10Response'],
            'M1-HTTP10-NEXT:HTTP/1.0'
         )
      ) {
         return 'M1 ordinary HTTP/1.0 control did not persist both requests: '
            . json_encode(substr($probe['HTTP10Response'], 0, 500));
      }

      if ($probe['attackError'] !== '') {
         return 'M1 HTTP/1.0 attack transport failed: ' . $probe['attackError'];
      }

      $bodyDispatched = str_contains(
         $probe['attackResponse'],
         'M1-ATTACK-BODY:HTTP/1.0:A'
      );
      $followerDispatched = str_contains(
         $probe['attackResponse'],
         'M1-ATTACK-SMUGGLED:HTTP/1.0'
      );
      if ($bodyDispatched && $followerDispatched) {
         return 'CONFIRMED M1: Bootgly accepted Transfer-Encoding: chunked on an '
            . 'HTTP/1.0 keep-alive request, decoded its body, and dispatched bytes '
            . 'after the terminal chunk as a second request. This proves the backend '
            . 'framing/persistence primitive; frontend differential remains '
            . 'deployment-dependent.';
      }
      if ($bodyDispatched || $followerDispatched) {
         return 'M1 insecure partial dispatch: HTTP/1.0 Transfer-Encoding reached '
            . 'application routing: '
            . json_encode(substr($probe['attackResponse'], 0, 500));
      }

      if (
         preg_match(
            '/^HTTP\/1\.[01] 400 /',
            $probe['attackResponse']
         ) !== 1
      ) {
         return 'M1 HTTP/1.0 Transfer-Encoding was not rejected with 400: '
            . json_encode(substr($probe['attackResponse'], 0, 500));
      }
      if ($probe['attackTimedOut']) {
         return 'M1 rejection left the HTTP/1.0 connection open until client timeout.';
      }
      if (! $probe['attackClosed']) {
         return 'M1 rejection did not close the HTTP/1.0 connection.';
      }

      return true;
   },
);
