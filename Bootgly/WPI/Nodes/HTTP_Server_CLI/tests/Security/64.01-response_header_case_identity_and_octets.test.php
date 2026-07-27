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
$probe = ['error' => '', 'head' => '', 'queued' => '', 'type' => ''];

return new Specification(
   description: 'response headers must have case-insensitive identity and reject forbidden octets',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $Head = static function (string $URI) use ($hostPort, $testIndex): string {
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
            "GET {$URI} HTTP/1.1\r\nHost: localhost\r\n"
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

         return $separator === false ? $response : substr($response, 0, $separator);
      };

      try {
         $probe['head'] = $Head('/m8-headers');
         $probe['queued'] = $Head('/m8-queued');
         $probe['type'] = $Head('/m8-type');
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

         // @ Cross-map identity: a variant supplied through prepare() must
         //   collapse with the one in the fields map, not serialize beside it.
         $Header->prepare(['x-prepared' => 'from-prepared']);
         $Header->set('X-Prepared', 'from-fields');

         // @ And a lowercase Content-Type must suppress the default one that
         //   build() injects when it sees no Content-Type.
         $Header->set('content-type', 'application/json');

         // ! Control — a clean header must still arrive.
         $Header->set('X-Clean', 'clean');
         $Header->append('X-Append-Control', 'one');
         $Header->append('X-Append-Control', 'two');
         $Header->own('X-Own-Control', 'clean');

         // ! Every public value path must apply the same octet boundary.
         //   append() validates each value but previously let its caller-owned
         //   separator inject an entire new response field.
         $Header->append('X-Append-Attack', 'one');
         $Header->append(
            'X-Append-Attack',
            'two',
            "\r\nX-M4-Injected: yes\r\n"
         );
         // ! own() is encoder-oriented but public; its canonical value must not
         //   bypass the C0/DEL gate used by the other insertion paths.
         $Header->own('X-Own-Octets', "a\x00b\x0Bc\x7Fd");

         return $Response(body: 'M8-OK');
      }, GET);

      yield $Router->route('/m8-queued', static function (Request $Request, Response $Response) {
         $Header = $Response->Header;

         // @ Cross-SOURCE identity: `queue()` deduplicated only inside its own
         //   list, and the build union was assembled from preset/fields/prepared
         //   alone — so a queued line and a mapped one under a different casing
         //   both reached the wire and the recipient chose which policy applies.
         $Header->queue('X-Queued-Policy', 'queued');
         $Header->set('x-queued-policy', 'mapped');

         // @ A queued Content-Type must suppress the default one build()
         //   injects, exactly as a mapped one does.
         $Header->queue('content-type', 'application/queued');

         // ! Control — Set-Cookie is the documented repeatable field and must
         //   keep its multi-line semantics through the identity union.
         $Header->queue('Set-Cookie', 'a=1');
         $Header->queue('Set-Cookie', 'b=2');

         return $Response(body: 'M8-QUEUED');
      }, GET);

      yield $Router->route('/m8-type', static function (Request $Request, Response $Response) {
         // @ The public default media type is written directly — no insertion
         //   method gates it, so its octets can only be scrubbed where it is
         //   serialized. CR/LF were stripped there; NUL, VT and DEL were not.
         $Response->Header->type = "text/plain\x00; charset=\x0BUTF-8\x7F";

         return $Response(body: 'M8-TYPE');
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
      if (
         ! str_contains($head, 'X-Append-Control: one, two')
         || ! str_contains($head, 'X-Own-Control: clean')
      ) {
         return 'M4 control failed: clean append()/own() values did not reach the wire: '
            . json_encode($head);
      }

      $Count = static function (string $name, string $block): int {
         $seen = 0;
         foreach (explode("\r\n", $block) as $line) {
            if (stripos($line, $name . ':') === 0) {
               $seen++;
            }
         }

         return $seen;
      };
      $count = static fn (string $name): int => $Count($name, $head);

      $problems = [];
      if ($count('x-policy') !== 1) {
         $problems[] = 'X-Policy serialized ' . $count('x-policy')
            . ' times (case-insensitive identity broken)';
      }
      if ($count('content-type') !== 1) {
         $problems[] = 'Content-Type serialized ' . $count('content-type')
            . ' times (MIME ambiguity)';
      }
      if ($count('x-prepared') !== 1) {
         $problems[] = 'X-Prepared serialized ' . $count('x-prepared')
            . ' times (identity not unified across the prepared and fields maps)';
      }
      if (str_contains($head, "\x00")) {
         $problems[] = 'a NUL byte reached the response head';
      }
      if (str_contains($head, "\x0B")) {
         $problems[] = 'a vertical tab reached the response head';
      }
      if (str_contains($head, 'X-M4-Injected: yes')) {
         $problems[] = 'append() accepted a CRLF-bearing separator and injected a field';
      }
      foreach (["\x00" => 'NUL', "\x0B" => 'vertical tab', "\x7F" => 'DEL'] as $octet => $label) {
         if (str_contains($head, $octet)) {
            $problems[] = "own() serialized {$label} from its canonical value";
         }
      }

      // ---

      // @ Cross-source identity (queued vs the maps) and the default media type.
      $queued = $probe['queued'];
      $type = $probe['type'];

      // ? Controls first — both routes must have answered, and the repeatable
      //   field must have stayed repeatable.
      if (! str_contains($queued, '200 OK') || ! str_contains($type, '200 OK')) {
         return 'M8 control failed: the queued/type routes did not answer ('
            . json_encode([substr($queued, 0, 90), substr($type, 0, 90)])
            . '), so their legs prove nothing.';
      }
      if ($Count('set-cookie', $queued) !== 2) {
         return 'M8 control failed: Set-Cookie serialized ' . $Count('set-cookie', $queued)
            . ' times instead of 2 — the identity union must never collapse the one '
            . 'documented repeatable field: ' . json_encode($queued);
      }
      if (! str_contains($type, 'text/plain')) {
         return 'M8 control failed: the scrubbed default media type lost its value entirely: '
            . json_encode($type);
      }

      if ($Count('x-queued-policy', $queued) !== 1) {
         $problems[] = 'X-Queued-Policy serialized ' . $Count('x-queued-policy', $queued)
            . ' times (queued and mapped sources do not share one identity)';
      }
      if ($Count('content-type', $queued) !== 1) {
         $problems[] = 'Content-Type serialized ' . $Count('content-type', $queued)
            . ' times on the queued route (a queued type did not suppress the default)';
      }
      foreach (["\x00" => 'a NUL byte', "\x0B" => 'a vertical tab', "\x7F" => 'a DEL byte'] as $octet => $label) {
         if (str_contains($type, $octet)) {
            $problems[] = "{$label} reached the wire through the default media type";
         }
      }

      if ($problems !== []) {
         return 'CONFIRMED M8/M4: ' . implode('; ', $problems)
            . ' — heads: ' . json_encode([$head, $queued, $type]);
      }

      return true;
   },
);
