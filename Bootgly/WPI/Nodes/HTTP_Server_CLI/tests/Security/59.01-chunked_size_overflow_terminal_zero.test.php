<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M1 — an overflowing chunk-size token must never be read as the
 * terminal zero chunk.
 *
 * `chunk-size` is validated with `ctype_xdigit()` and then converted with
 * `(int) hexdec($sizeLine)`. Any token at or above 2^64 makes `hexdec()` return
 * a float outside the signed integer range, and the cast collapses it to `0`.
 * That zero is tested BEFORE the configured body-size cap, so the decoder
 * enters its terminal-trailer state, completes the request with an empty body
 * and hands every following byte to the HTTP/1 pipeline — where a complete
 * request smuggled behind the token is dispatched as its own request.
 *
 * Controls: a legitimate chunked body must complete AND pipeline its follower
 * normally (so a fix that simply breaks pipelining cannot pass), and
 * `7FFFFFFFFFFFFFFF` — the largest token that does NOT overflow — must be
 * rejected by the body-size cap, isolating the overflow as the bypass.
 */

$SENTINEL = "GET /m1-smuggled HTTP/1.1\r\n"
   . "Host: localhost\r\n"
   . "Connection: close\r\n"
   . "\r\n";
$FOLLOWER = "GET /m1-next HTTP/1.1\r\n"
   . "Host: localhost\r\n"
   . "Connection: close\r\n"
   . "\r\n";

// ! Every token below is accepted by ctype_xdigit() and collapses to 0 through
//   the float cast: 16 F's is 2^64-1, the 17-digit form is 2^64 exactly, and
//   the padded form proves leading zeros do not change the outcome.
$OVERFLOWS = [
   'upper_16F' => 'FFFFFFFFFFFFFFFF',
   'lower_16f' => 'ffffffffffffffff',
   'exact_2_64' => '10000000000000000',
   'zero_padded' => '00000000FFFFFFFFFFFFFFFF',
];

$probe = [
   'error' => '',
   'controlError' => '',
   'controlResponse' => '',
   'capError' => '',
   'capResponse' => '',
   'capExactError' => '',
   'capExactResponse' => '',
   'attacks' => [],
   'cast' => [],
];

// ! Record the language-level primitive alongside the wire evidence.
foreach ($OVERFLOWS as $label => $token) {
   $probe['cast'][$label] = [
      'token' => $token,
      'xdigit' => ctype_xdigit($token),
      'cast' => (int) hexdec($token),
   ];
}

return new Specification(
   description: 'an overflowing chunk-size must not be decoded as the terminal zero chunk',
   Separator: new Separator(line: true),

   request: function (string $hostPort, int $testIndex = 0) use (
      &$probe,
      $OVERFLOWS,
      $SENTINEL,
      $FOLLOWER
   ): string {
      $Transmit = static function (string $body, array $stops) use (
         $hostPort,
         $testIndex
      ): array {
         $head = "POST /m1-original HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "\r\n";

         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            return ["Could not connect to {$hostPort}: {$errorNumber} {$errorMessage}", ''];
         }

         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);

         if (@fwrite($socket, $head) !== strlen($head)) {
            @fclose($socket);
            return ['Could not write the complete chunked request head.', ''];
         }

         // @ Let the worker install Decoder_Chunked before the body and the
         //   bytes hiding behind the size token arrive in a later read.
         usleep(250_000);

         if (@fwrite($socket, $body) !== strlen($body)) {
            @fclose($socket);
            return ['Could not write the complete chunked body.', ''];
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
            foreach ($stops as $stop) {
               if (str_contains($response, $stop)) {
                  break 2;
               }
            }
         }

         @fclose($socket);

         return ['', $response];
      };

      // ! Control — a legitimate chunked body completes and its pipelined
      //   follower is dispatched. Proves pipelining itself is healthy.
      [$probe['controlError'], $probe['controlResponse']] = $Transmit(
         "5\r\nHELLO\r\n0\r\n\r\n" . $FOLLOWER,
         ['M1-NEXT']
      );

      // ! Control — the largest NON-overflowing token must hit the body cap.
      [$probe['capError'], $probe['capResponse']] = $Transmit(
         "7FFFFFFFFFFFFFFF\r\n\r\n" . $SENTINEL,
         ['413', 'M1-SMUGGLED']
      );

      // ! Control — 15 significant digits convert exactly, so this one reaches
      //   the body-size cap itself rather than any digit bound. Keeps the cap
      //   path covered so a fix cannot quietly replace it.
      [$probe['capExactError'], $probe['capExactResponse']] = $Transmit(
         "FFFFFFFFFFFFFFF\r\n\r\n" . $SENTINEL,
         ['413', 'M1-SMUGGLED']
      );

      // @ Attack — each overflowing token, with a complete request hidden
      //   immediately behind it.
      foreach ($OVERFLOWS as $label => $token) {
         [$error, $response] = $Transmit(
            "{$token}\r\n\r\n" . $SENTINEL,
            ['M1-SMUGGLED', '400', '413']
         );

         $probe['attacks'][$label] = [
            'token' => $token,
            'error' => $error,
            'smuggled' => str_contains($response, 'M1-SMUGGLED'),
            'rejected' => str_contains($response, '400') || str_contains($response, '413'),
            'response' => substr($response, 0, 300),
         ];
      }

      return "GET /m1-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m1-original', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'M1-ORIGINAL:' . strlen($Request->Body->raw));
      }, POST);

      yield $Router->route('/m1-smuggled', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'M1-SMUGGLED');
      }, GET);

      yield $Router->route('/m1-next', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'M1-NEXT');
      }, GET);

      yield $Router->route('/m1-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'M1 harness request did not reach /m1-harness.';
      }

      // ? The language primitive this finding rests on must still hold.
      foreach ($probe['cast'] as $label => $cast) {
         if ($cast['xdigit'] !== true || $cast['cast'] !== 0) {
            return "M1 primitive no longer holds for {$label}: " . json_encode($cast);
         }
      }

      if ($probe['controlError'] !== '') {
         return 'M1 legitimate control: ' . $probe['controlError'];
      }
      if (! str_contains($probe['controlResponse'], 'M1-ORIGINAL:5')) {
         return 'M1 legitimate control did not complete the chunked POST with its 5-byte body: '
            . json_encode(substr($probe['controlResponse'], 0, 300));
      }
      if (! str_contains($probe['controlResponse'], 'M1-NEXT')) {
         return 'M1 legitimate control did not pipeline its follower after the terminal chunk: '
            . json_encode(substr($probe['controlResponse'], 0, 300));
      }

      if ($probe['capError'] !== '') {
         return 'M1 body-cap control: ' . $probe['capError'];
      }
      if (str_contains($probe['capResponse'], 'M1-SMUGGLED')) {
         return 'M1 body-cap control smuggled: 7FFFFFFFFFFFFFFF does not overflow and must be '
            . 'rejected by the size cap: ' . json_encode(substr($probe['capResponse'], 0, 300));
      }
      if (! str_contains($probe['capResponse'], '413')) {
         return 'M1 body-cap control did not reject 7FFFFFFFFFFFFFFF with 413: '
            . json_encode(substr($probe['capResponse'], 0, 300));
      }

      if ($probe['capExactError'] !== '') {
         return 'M1 exact-conversion cap control: ' . $probe['capExactError'];
      }
      if (str_contains($probe['capExactResponse'], 'M1-SMUGGLED')) {
         return 'M1 exact-conversion cap control smuggled: FFFFFFFFFFFFFFF converts exactly '
            . 'and must be rejected by the body-size cap: '
            . json_encode(substr($probe['capExactResponse'], 0, 300));
      }
      if (! str_contains($probe['capExactResponse'], '413')) {
         return 'M1 exact-conversion cap control did not reject FFFFFFFFFFFFFFF with 413 — '
            . 'the body-size cap path is no longer reachable: '
            . json_encode(substr($probe['capExactResponse'], 0, 300));
      }

      $smuggled = [];
      $unrejected = [];
      foreach ($probe['attacks'] as $label => $attack) {
         if ($attack['error'] !== '') {
            return "M1 attack leg {$label}: " . $attack['error'];
         }
         if ($attack['smuggled'] === true) {
            $smuggled[] = "{$label} ({$attack['token']})";
         }
         else if ($attack['rejected'] !== true) {
            $unrejected[] = "{$label} ({$attack['token']})";
         }
      }

      if ($smuggled !== []) {
         return 'CONFIRMED M1: an overflowing chunk-size token was decoded as the terminal '
            . 'zero chunk, completing the request with an empty body and dispatching the '
            . 'bytes behind it as a smuggled request — ' . implode(', ', $smuggled)
            . '. Each token passes ctype_xdigit() and casts to 0 through hexdec()\'s float, '
            . 'and the zero test runs before the body-size cap.';
      }

      if ($unrejected !== []) {
         return 'M1: overflowing tokens were not smuggled but also not rejected with 400/413 — '
            . implode(', ', $unrejected) . ': ' . json_encode($probe['attacks']);
      }

      return true;
   },
);
