<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI;


use function assert;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function sort;
use function str_contains;
use function strlen;
use function substr;
use function var_dump;
use Closure;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


/**
 * An HTTP client request holds its credential headers (`Authorization`,
 * `Cookie`) in its header fields, in their raw block and in the memoized wire
 * encoding, and the `Set-Cookie` of a response head split across reads in its
 * pending bytes; the client keeps that encoding again, with every input that
 * defines it, for re-dispatch; a response holds its `Set-Cookie` fields and
 * their raw block; an HTTP/2 session holds them in the frames it queues, the
 * partial frame it carries, the header block fragments it accumulates and the
 * response head of each completion record; the Configs and the client hold the
 * TLS context. A debug dump of any of them shows no credential header, no
 * encoding, no wire bytes and no key passphrase, while the path, every other
 * header, a response body and the certificate path show as they are. The
 * request body shows as it is.
 */
return new Test(
   description: 'HTTP_Client_CLI: debug dumps mask the credential headers, the memoized encodings, the wire buffers and the TLS passphrase',
   test: function () {
      // ! SYNTH credentials — never real ones
      $passphrase = 'SYNTH-HTTP-CLIENT-PASSPHRASE-12.1';
      $bearer = 'SYNTH-HTTP-CLIENT-BEARER-12.1';
      $session = 'SYNTH-HTTP-CLIENT-SESSION-12.1';
      $issued = 'SYNTH-HTTP-CLIENT-SET-COOKIE-12.1';
      $pending = 'SYNTH-HTTP-CLIENT-PENDING-COOKIE-12.1';
      $streamBearer = 'SYNTH-HTTP-CLIENT-H2-BEARER-12.1';
      $streamCookie = 'SYNTH-HTTP-CLIENT-H2-COOKIE-12.1';
      $completed = 'SYNTH-HTTP-CLIENT-H2-COMPLETED-12.1';
      $fragment = 'SYNTH-HTTP-CLIENT-H2-FRAGMENT-12.1';
      $partial = 'SYNTH-HTTP-CLIENT-H2-PARTIAL-12.1';
      $certificate = '/nonexistent/visible-certificate-12.1.pem';
      $path = '/visible-path-12.1';
      $accept = 'text/visible-accept-12.1';
      $type = 'text/visible-type-12.1';
      $body = 'visible-body-12.1';
      $secure = [
         'local_cert' => $certificate,
         'passphrase' => $passphrase
      ];
      $Dumper = new Dumper('plain');
      // ! No limit may hide a leak
      $Dumper->depth = 32;
      $Dumper->strings = 1_000_000;
      $Dumper->items = 10_000;
      // ! Each debug channel
      $Channels = [
         'var_dump' => static function (object $Object): string {
            ob_start();
            var_dump($Object);

            return (string) ob_get_clean();
         },
         'print_r' => static fn (object $Object): string => print_r($Object, true),
         'debug_zval_dump' => static function (object $Object): string {
            ob_start();
            debug_zval_dump($Object);

            return (string) ob_get_clean();
         },
         'Dumper' => static fn (object $Object): string => $Dumper->dump($Object),
      ];

      // ! The Configs, and a client that adopted it
      $Configs = new Configs(host: '127.0.0.1', port: 1, secure: $secure);
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure($Configs);
      // ! A request with credential headers, encoded and memoized as a dispatch does
      $Request = new Request;
      $Request('GET', $path, [
         'Authorization' => "Bearer {$bearer}",
         'Cookie' => "sid={$session}",
         'Accept' => $accept
      ]);
      $wire = Request\Encoders\Encoder_::encode('GET', $path, 'HTTP/1.1', $Request->Header->build(), '', '127.0.0.1', 1);
      Closure::bind(static function (HTTP_Client_CLI $Client, Request $Request, string $wire): void {
         $Client->remember($Request, $wire);
      }, null, HTTP_Client_CLI::class)($Client, $Request, $wire);
      // ! The first read of its response: a head cut short, still pending decode
      $head = "HTTP/1.1 200 OK\r\nSet-Cookie: sid={$pending}; HttpOnly\r\nContent-Ty";
      $Request->pendingBuffer = $head;
      // ! A response header block with two cookies issued
      $Response = new Request\Response\Raw\Header;
      $Response->define("Content-Type: {$type}\r\nSet-Cookie: sid={$issued}; HttpOnly\r\nSet-Cookie: theme={$issued}");
      $Response->build();
      // ! An HTTP/2 session with two requests on the wire: the first answered
      //   whole, the second answered by a header block cut short — a HEADERS
      //   awaiting CONTINUATION, then a CONTINUATION not fully read
      $Session = new Session;
      $first = $Session->open('GET', 'https', 'origin.test', $path, [
         'Authorization' => "Bearer {$streamBearer}",
         'Cookie' => "sid={$streamCookie}",
         'Accept' => $accept
      ]);
      $second = $Session->open('GET', 'https', 'origin.test', $path, [
         'Authorization' => "Bearer {$streamBearer}"
      ]);
      $tail = HPACK::encode([['content-type', $type]]);
      $continuation = Frame::pack(
         HTTP2::FRAME_CONTINUATION, HTTP2::FLAG_END_HEADERS, $second,
         HPACK::encode([['set-cookie', "theme={$partial}"]]) . $tail
      );
      $fed = $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, ''))
         && $Session->feed(Frame::pack(
            HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, $first,
            HPACK::encode([[':status', '200'], ['set-cookie', "sid={$completed}; HttpOnly"]])
         ))
         && $Session->feed(Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, $first, $body))
         && $Session->feed(Frame::pack(
            HTTP2::FRAME_HEADERS, 0, $second,
            HPACK::encode([[':status', '200'], ['set-cookie', "sid={$fragment}"]])
         ))
         && $Session->feed(substr($continuation, 0, -strlen($tail)));
      // ! The private header block fragments, read as the engine holds them
      $Fragments = Closure::bind(static fn (Session $Session): string => $Session->fragments, null, Session::class);
      $outbox = $Session->outbox;
      $carry = $Session->buffer;
      $records = $Session->done;
      $fragments = $Fragments($Session);

      // ? Every credential is live before any dump — never a vacuous pass
      yield assert(
         assertion: $fed === true
            && str_contains($outbox, "Bearer {$streamBearer}") === true
            && str_contains($outbox, "sid={$streamCookie}") === true
            && str_contains($records[$first]['headerRaw'] ?? '', "sid={$completed}") === true
            && ($records[$first]['body'] ?? null) === $body
            && str_contains($fragments, "sid={$fragment}") === true
            && str_contains($carry, "theme={$partial}") === true,
         description: 'The HTTP/2 session holds every credential live: in its queued frames, its completion record, its fragments and its carried partial frame'
      );

      $Holders = [
         'HTTP_Client_CLI\Configs' => [$Configs, [$passphrase], [$certificate]],
         'HTTP_Client_CLI' => [$Client, [$passphrase, $bearer, $session], [$certificate]],
         'Request' => [$Request, [$bearer, $session, $pending], [$path, $accept]],
         'Request\Raw\Header' => [$Request->Header, [$bearer, $session], [$accept]],
         'Request\Response\Raw\Header' => [$Response, [$issued], [$type]],
         'Session' => [$Session, [$streamBearer, $streamCookie, $completed, $fragment, $partial], [$body]]
      ];

      // @@ Every holder through every channel
      $leaks = [];
      $missing = [];
      foreach ($Holders as $holder => [$Holder, $secrets, $visible]) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Holder);

            foreach ($secrets as $secret) {
               if (str_contains($output, $secret) === true) {
                  $leaks[] = "{$secret} in {$holder} via {$channel}";
               }
            }
            foreach ([...$visible, Secret::MASK] as $shown) {
               if (str_contains($output, $shown) === false) {
                  $missing[] = "{$shown} in {$holder} via {$channel}";
               }
            }
         }
      }
      $leaked = implode(', ', $leaks);
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $leaks === [] && $missing === [],
         description: "No credential header, encoding, wire byte nor passphrase reaches var_dump, print_r, debug_zval_dump or Dumper, while the path, the other headers, the response body and the certificate path show (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ Dumping never writes
      yield assert(
         assertion: ($Configs->secure['passphrase'] ?? null) === $passphrase
            && ($Client->secure['passphrase'] ?? null) === $passphrase
            && $Request->encoded === $wire
            && str_contains($wire, "Authorization: Bearer {$bearer}\r\n") === true
            && $Request->Header->get('Cookie') === "sid={$session}"
            && $Request->pendingBuffer === $head
            && $Response->fields['set-cookie'] === ["sid={$issued}; HttpOnly", "theme={$issued}"]
            && $Session->outbox === $outbox
            && $Session->buffer === $carry
            && $Session->done === $records
            && $Fragments($Session) === $fragments,
         description: 'The live passphrase, headers, encoding, cookies and wire buffers are untouched by the dumps'
      );

      // @ The one way: the trait's __debugInfo, #[Secret] on the stored properties only
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $expected = [
         'HTTP_Client_CLI\Configs' => ['secure'],
         'HTTP_Client_CLI' => ['Encodings', 'secure'],
         'Request' => ['encoded', 'pendingBuffer'],
         'Request\Raw\Header' => ['fields', 'raw'],
         'Request\Response\Raw\Header' => ['fields', 'raw'],
         'Session' => ['buffer', 'done', 'fragments', 'outbox']
      ];
      $deviations = [];
      foreach ($Holders as $holder => [$Holder]) {
         $Own = new ReflectionMethod($Holder, '__debugInfo');
         if (
            $Own->getFileName() !== $Trait->getFileName()
            || $Own->getStartLine() !== $Trait->getStartLine()
         ) {
            $deviations[] = "{$holder} dumps through its own __debugInfo";
         }

         $marked = [];
         foreach (new ReflectionClass($Holder)->getProperties() as $Property) {
            if ($Property->getAttributes(Secret::class) === []) {
               continue;
            }

            $marked[] = $Property->name;
            if ($Property->isStatic() === true || $Property->isVirtual() === true) {
               $deviations[] = "{$holder}::\${$Property->name} is static or virtual";
            }
         }
         sort($marked);
         if ($marked !== $expected[$holder]) {
            $list = implode(', ', $marked);
            $deviations[] = "{$holder} marks [{$list}]";
         }
      }
      $deviated = implode('; ', $deviations);
      yield assert(
         assertion: $deviations === [],
         description: "Each holder dumps through the Redactable trait, #[Secret] on the stored properties only (deviations: [{$deviated}])"
      );
   }
);
