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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


/**
 * A JSON body whose decode bound (`Request::check()`) passes the bodies share
 * of the worker budget is refused by `Decoder_HTTP2::dispatch()` with an h2
 * `:status 413` on its own stream — never by the HTTP/1.1 encoder refusal,
 * whose raw `HTTP/1.1 413` bytes would corrupt the h2 connection. The
 * connection survives: a later stream on it is routed normally.
 */
return new Test(
   description: 'It should refuse an oversized JSON body with :status 413 on its stream only',
   test: new Assertions(Case: function (): Generator {
      // ! `[[0],[0],…]` of ~1 MB: its real decode (~55 MiB) passes half of
      //   the default 64 MiB `maxWorkerPendingBytes` — so does every bound
      //   `Request::check()` takes — while the body itself fits every body
      //   ceiling (10 MiB)
      $items = str_repeat('[0],', 250_000);
      $body = "[{$items}[0]]";
      $length = strlen($body);

      $Client = new Client;
      $Client->preface();
      // ! One decoder for the whole connection: HEADERS blocks decode in order
      $HPACK = new HPACK(4096);
      $decode = static function (array $frame) use ($HPACK): array {
         $map = [];
         foreach ($HPACK->decode($frame['payload'], PHP_INT_MAX) ?? [] as [$name, $value]) {
            $map[$name] = $value;
         }
         return $map;
      };
      // ! Full write on the non-blocking socket (a partial fwrite must not
      //   truncate a frame)
      $write = static function (string $raw) use ($Client): bool {
         $deadline = microtime(true) + 5.0;
         while ($raw !== '') {
            $written = @fwrite($Client->Socket, $raw);
            if ($written === false || microtime(true) > $deadline) {
               return false;
            }
            $raw = substr($raw, $written);
            if ($written === 0) {
               usleep(1000);
            }
         }
         return true;
      };

      // @ Server SETTINGS: initial stream window and max frame size
      $window = 65535;      // connection send window
      $streamWindow = 65535; // stream 1 send window
      $frameSize = 16384;
      $goaways = 0;
      $Settings = $Client->expect(HTTP2::FRAME_SETTINGS, 5.0);
      $payload = $Settings['payload'] ?? '';
      for ($offset = 0; $offset + 6 <= strlen($payload); $offset += 6) {
         ['id' => $id, 'value' => $value] = unpack('nid/Nvalue', substr($payload, $offset, 6));
         if ($id === HTTP2::SETTINGS_INITIAL_WINDOW_SIZE) {
            $streamWindow = $value;
         }
         else if ($id === HTTP2::SETTINGS_MAX_FRAME_SIZE) {
            $frameSize = $value;
         }
      }
      $Client->send(Frame::pack(HTTP2::FRAME_SETTINGS, HTTP2::FLAG_ACK, 0));

      // @ Stream A (1): HEADERS, then the JSON body under flow control
      $write(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS,
         1,
         HPACK::encode([
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', '/json'],
            [':authority', 'localhost:8085'],
            ['content-type', 'application/json'],
            ['content-length', (string) $length]
         ])
      ));

      $Headers = null;
      $sent = 0;
      $stalled = false;
      // @@ Send while credit lasts; wait for WINDOW_UPDATE otherwise
      while ($sent < $length) {
         $chunk = min($frameSize, $window, $streamWindow, $length - $sent);
         if ($chunk > 0) {
            $last = $sent + $chunk === $length;
            if ($write(Frame::pack(
               HTTP2::FRAME_DATA,
               $last ? HTTP2::FLAG_END_STREAM : 0,
               1,
               substr($body, $sent, $chunk)
            )) === false) {
               $stalled = true;
               break;
            }
            $sent += $chunk;
            $window -= $chunk;
            $streamWindow -= $chunk;
            continue;
         }

         $frame = $Client->frame(5.0);
         if ($frame === null) {
            $stalled = true;
            break;
         }
         if ($frame['type'] === HTTP2::FRAME_WINDOW_UPDATE) {
            $increment = unpack('N', $frame['payload'])[1] & 0x7fffffff;
            if ($frame['stream'] === 0) {
               $window += $increment;
            }
            else if ($frame['stream'] === 1) {
               $streamWindow += $increment;
            }
         }
         else if ($frame['type'] === HTTP2::FRAME_GOAWAY) {
            $goaways++;
         }
         else if ($frame['type'] === HTTP2::FRAME_HEADERS) {
            $Headers = $frame;
            break;
         }
      }

      yield new Assertion(
         description: "The whole {$length}-byte JSON body is sent under flow control",
      )
         ->expect([$sent, $stalled, $Headers])
         ->to->be([$length, false, null])
         ->assert();

      // @ Stream A's answer: HEADERS :status 413 with END_STREAM, no DATA
      $Data = null;
      $deadline = microtime(true) + 5.0;
      while ($Headers === null && microtime(true) < $deadline) {
         $frame = $Client->frame($deadline - microtime(true));
         if ($frame === null) {
            break;
         }
         if ($frame['type'] === HTTP2::FRAME_GOAWAY) {
            $goaways++;
         }
         else if ($frame['type'] === HTTP2::FRAME_DATA && $frame['stream'] === 1) {
            $Data = $frame;
         }
         else if ($frame['type'] === HTTP2::FRAME_HEADERS) {
            $Headers = $frame;
         }
      }
      $map = $Headers === null ? [] : $decode($Headers);

      yield new Assertion(
         description: 'Stream A → HEADERS :status 413 with END_STREAM (no DATA)',
      )
         ->expect([
            $Headers['stream'] ?? 0,
            $map[':status'] ?? '',
            ($Headers['flags'] ?? 0) & HTTP2::FLAG_END_STREAM,
            $Data
         ])
         ->to->be([1, '413', HTTP2::FLAG_END_STREAM, null])
         ->assert();

      // @ Stream B (3): a small JSON body on the SAME connection is routed
      $small = '{"a":[1,2]}';
      $write(
         Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS,
            3,
            HPACK::encode([
               [':method', 'POST'],
               [':scheme', 'http'],
               [':path', '/json'],
               [':authority', 'localhost:8085'],
               ['content-type', 'application/json'],
               ['content-length', (string) strlen($small)]
            ])
         )
         . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 3, $small)
      );

      $Headers = null;
      $Data = null;
      $deadline = microtime(true) + 5.0;
      while ($Data === null && microtime(true) < $deadline) {
         $frame = $Client->frame($deadline - microtime(true));
         if ($frame === null) {
            break;
         }
         if ($frame['type'] === HTTP2::FRAME_GOAWAY) {
            $goaways++;
         }
         else if ($frame['type'] === HTTP2::FRAME_HEADERS) {
            $Headers = $frame;
         }
         else if ($frame['type'] === HTTP2::FRAME_DATA && $frame['stream'] === 3) {
            $Data = $frame;
         }
      }
      $map = $Headers === null ? [] : $decode($Headers);

      yield new Assertion(
         description: 'Stream B → :status 200 with the routed JSON body',
      )
         ->expect([$Headers['stream'] ?? 0, $map[':status'] ?? '', $Data['payload'] ?? ''])
         ->to->be([3, '200', "method=POST;uri=/json;protocol=HTTP/2;body={$small}"])
         ->assert();

      // @ The connection was never torn down: no GOAWAY, PING answered
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'json413!'));
      $Pong = null;
      $deadline = microtime(true) + 5.0;
      while ($Pong === null && microtime(true) < $deadline) {
         $frame = $Client->frame($deadline - microtime(true));
         if ($frame === null) {
            break;
         }
         if ($frame['type'] === HTTP2::FRAME_GOAWAY) {
            $goaways++;
         }
         else if ($frame['type'] === HTTP2::FRAME_PING && ($frame['flags'] & HTTP2::FLAG_ACK) !== 0) {
            $Pong = $frame;
         }
      }

      yield new Assertion(
         description: 'Connection alive after the refusal: no GOAWAY, PING acknowledged',
      )
         ->expect([$goaways, $Pong['payload'] ?? ''])
         ->to->be([0, 'json413!'])
         ->assert();

      $Client->close();
   })
);
