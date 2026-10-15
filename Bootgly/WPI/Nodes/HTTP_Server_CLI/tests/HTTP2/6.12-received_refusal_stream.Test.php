<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_Testing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


/**
 * BUGS ENC-12 over HTTP/2 — a `Request\Events::Received` listener that throws
 * refuses the stream it ran for, and only that stream.
 *
 * The encoders' Received catch used to answer every refusal with the HTTP/1
 * `reject()`: on an h2c connection it wrote a raw `HTTP/1.1 500` status line
 * between the frames, then a GOAWAY, and closed the connection, so the
 * refused stream never got a status and every sibling stream in flight died
 * with it. Now an HTTP/2 stream is refused through `Decoder_HTTP2::deny()`:
 * HEADERS `:status 500` (`503` for a budget `Decoders\Bodies\Refusal`) with
 * END_STREAM on that stream, and the connection lives.
 *
 * The suite's server arms a listener that throws a RuntimeException for
 * `/received-refuse` and a `Refusal` for `/received-refuse-budget`; its
 * arming route (`/received-arm/production|testing`) also picks the encoder
 * that serves the requests after it, so each leg pins one encoder: `Encoder_`
 * (production), then `Encoder_Testing` (the encoder `Modes::Test` boots).
 * Each leg, over its own prior-knowledge connection:
 *
 * - stream 1 arms the gate (`200`, the encoder's class);
 * - stream 3 opens a POST and sends half its body; stream 5, refused, ends
 *   the same write and is answered before the client sends anything else
 *   (the live reactor also flushes on its next tick, so that the refusal
 *   leaves with the read that refused it is pinned by Unit 1.68);
 * - stream 5 → HEADERS `:status 500` + END_STREAM, no DATA, no RST_STREAM;
 * - stream 7, sent after the refusal, → `200`; stream 3 then completes its
 *   body → `200` with the whole body;
 * - stream 9 (`Refusal`) → `:status 503` + END_STREAM; stream 11 → `200`;
 * - every byte the server wrote parses as frames: no `HTTP/1.1` bytes, no
 *   GOAWAY, no RST_STREAM, and a PING is still acknowledged.
 */
return new Test(
   description: 'ENC-12: a throwing Received listener refuses its h2 stream alone (:status 500/503), never the connection',
   test: new Assertions(Case: function (): Generator {
      // ! One leg per encoder — the testing one last: the worker keeps the
      //   encoder `Modes::Test` booted
      $legs = [
         'production' => Encoder_::class,
         'testing' => Encoder_Testing::class
      ];

      // @@
      foreach ($legs as $leg => $class) {
         $Client = new Client;
         $Client->preface();

         // ! Every byte the server writes, in order, and every frame parsed
         //   from them (HEADERS decoded on arrival: one HPACK context)
         $wire = '';
         $closed = false;
         $frames = [];
         $HPACK = new HPACK(4096);
         $Read = static function (float $timeout) use ($Client, $HPACK, &$wire, &$closed, &$frames): null|array {
            $deadline = microtime(true) + $timeout;

            while (true) {
               // ?: A whole frame already buffered — `frame(0.0)` never reads
               $frame = $Client->frame(0.0);
               if ($frame !== null) {
                  $frame['fields'] = [];
                  if ($frame['type'] === HTTP2::FRAME_HEADERS) {
                     foreach ($HPACK->decode($frame['payload'], PHP_INT_MAX) ?? [] as [$name, $value]) {
                        $frame['fields'][$name] = $value;
                     }
                  }
                  $frames[] = $frame;

                  return $frame;
               }

               $chunk = @fread($Client->Socket, 65536);
               if ($chunk !== false && $chunk !== '') {
                  $Client->buffer .= $chunk;
                  $wire .= $chunk;
                  continue;
               }
               if (@feof($Client->Socket)) {
                  $closed = true;
                  return null;
               }
               if (microtime(true) >= $deadline) {
                  return null;
               }

               usleep(5000);
            }
         };
         // ! What one stream got: status, END_STREAM, body, DATA frames, RST_STREAM
         $Inspect = static function (int $stream) use (&$frames): array {
            $state = ['status' => '', 'ended' => false, 'body' => '', 'data' => 0, 'reset' => false];
            foreach ($frames as $frame) {
               if ($frame['stream'] !== $stream) {
                  continue;
               }
               if ($frame['type'] === HTTP2::FRAME_HEADERS) {
                  $state['status'] = $frame['fields'][':status'] ?? '';
               }
               else if ($frame['type'] === HTTP2::FRAME_DATA) {
                  $state['body'] .= $frame['payload'];
                  $state['data']++;
               }
               else if ($frame['type'] === HTTP2::FRAME_RST_STREAM) {
                  $state['reset'] = true;
               }
               if (
                  ($frame['type'] === HTTP2::FRAME_HEADERS || $frame['type'] === HTTP2::FRAME_DATA)
                  && ($frame['flags'] & HTTP2::FLAG_END_STREAM) !== 0
               ) {
                  $state['ended'] = true;
               }
            }

            return $state;
         };
         // ! Read until `$stream` ends (or is reset), the connection closes or
         //   5 s pass — never writing anything meanwhile
         $Await = static function (int $stream) use ($Read, $Inspect, &$closed): array {
            $deadline = microtime(true) + 5.0;
            while ($closed === false && microtime(true) < $deadline) {
               $state = $Inspect($stream);
               if ($state['ended'] || $state['reset']) {
                  break;
               }
               $Read($deadline - microtime(true));
            }

            return $Inspect($stream);
         };
         // ! A request HEADERS frame
         $Compose = static function (int $stream, string $method, string $path, int $flags, array $fields = []): string {
            return Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS | $flags,
               $stream,
               HPACK::encode([
                  [':method', $method],
                  [':scheme', 'http'],
                  [':path', $path],
                  [':authority', 'localhost:8085'],
                  ...$fields
               ])
            );
         };

         // @ Settings exchange
         $deadline = microtime(true) + 5.0;
         $settings = null;
         while ($settings === null && $closed === false && microtime(true) < $deadline) {
            $frame = $Read($deadline - microtime(true));
            if (
               $frame !== null
               && $frame['type'] === HTTP2::FRAME_SETTINGS
               && ($frame['flags'] & HTTP2::FLAG_ACK) === 0
            ) {
               $settings = $frame;
            }
         }
         $Client->send(Frame::pack(HTTP2::FRAME_SETTINGS, HTTP2::FLAG_ACK, 0));

         // @ Stream 1 arms the Received gate and picks this leg's encoder
         $Client->send($Compose(1, 'GET', "/received-arm/{$leg}", HTTP2::FLAG_END_STREAM));
         $armed = $Await(1);

         yield new Assertion(
            description: "[{$leg}] Server SETTINGS received; stream 1 arms the Received gate on {$class}",
         )
            ->expect([$settings !== null, $armed['status'], $armed['ended'], $armed['body']])
            ->to->be([true, '200', true, "armed:{$class}"])
            ->assert();

         // @ One write: stream 3 opens a POST with half its body; stream 5 —
         //   refused by the gate — is the write's last frame
         $Client->send(
            $Compose(3, 'POST', '/before', 0, [['content-type', 'text/plain'], ['content-length', '10']])
            . Frame::pack(HTTP2::FRAME_DATA, 0, 3, '01234')
            . $Compose(5, 'GET', '/received-refuse', HTTP2::FLAG_END_STREAM)
         );
         $refused = $Await(5);

         yield new Assertion(
            description: "[{$leg}] Refused stream 5 → HEADERS :status 500 with END_STREAM (no DATA, no RST_STREAM)",
         )
            ->expect($refused)
            ->to->be(['status' => '500', 'ended' => true, 'body' => '', 'data' => 0, 'reset' => false])
            ->assert();

         // @ Siblings: stream 7 sent after the refusal; stream 3 — open
         //   across it — then completes its body
         $Client->send($Compose(7, 'GET', '/after', HTTP2::FLAG_END_STREAM));
         $after = $Await(7);
         $Client->send(Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 3, '56789'));
         $before = $Await(3);

         yield new Assertion(
            description: "[{$leg}] Sibling streams → 200: stream 7 (after the refusal), stream 3 (open across it)",
         )
            ->expect([
               [$after['status'], $after['ended'], $after['body']],
               [$before['status'], $before['ended'], $before['body']]
            ])
            ->to->be([
               ['200', true, 'method=GET;uri=/after;protocol=HTTP/2;body='],
               ['200', true, 'method=POST;uri=/before;protocol=HTTP/2;body=0123456789']
            ])
            ->assert();

         // @ A budget Refusal: 503 on its stream, then a sibling
         $Client->send($Compose(9, 'GET', '/received-refuse-budget', HTTP2::FLAG_END_STREAM));
         $unavailable = $Await(9);
         $Client->send($Compose(11, 'GET', '/after', HTTP2::FLAG_END_STREAM));
         $next = $Await(11);

         yield new Assertion(
            description: "[{$leg}] Refusal stream 9 → HEADERS :status 503 with END_STREAM; stream 11 after it → 200",
         )
            ->expect([$unavailable, [$next['status'], $next['ended'], $next['body']]])
            ->to->be([
               ['status' => '503', 'ended' => true, 'body' => '', 'data' => 0, 'reset' => false],
               ['200', true, 'method=GET;uri=/after;protocol=HTTP/2;body=']
            ])
            ->assert();

         // @ The connection lives: PING acknowledged
         $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'enc12h2!'));
         $pong = null;
         $deadline = microtime(true) + 5.0;
         while ($pong === null && $closed === false && microtime(true) < $deadline) {
            $frame = $Read($deadline - microtime(true));
            if (
               $frame !== null
               && $frame['type'] === HTTP2::FRAME_PING
               && ($frame['flags'] & HTTP2::FLAG_ACK) !== 0
            ) {
               $pong = $frame;
            }
         }
         $goaways = 0;
         $resets = 0;
         foreach ($frames as $frame) {
            if ($frame['type'] === HTTP2::FRAME_GOAWAY) {
               $goaways++;
            }
            else if ($frame['type'] === HTTP2::FRAME_RST_STREAM) {
               $resets++;
            }
         }

         yield new Assertion(
            description: "[{$leg}] Connection alive: every byte parses as frames (no HTTP/1.1), no GOAWAY, no RST_STREAM, PING acknowledged",
         )
            ->expect([
               str_contains($wire, 'HTTP/1.1'),
               $Client->buffer,
               $goaways,
               $resets,
               $closed,
               $pong['payload'] ?? ''
            ])
            ->to->be([false, '', 0, 0, false, 'enc12h2!'])
            ->assert();

         $Client->close();
      }
   })
);
