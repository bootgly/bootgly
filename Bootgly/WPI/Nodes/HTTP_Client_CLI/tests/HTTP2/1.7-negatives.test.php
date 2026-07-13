<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Modules\HTTP2\Settings;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Session;


return new Specification(
   description: 'It should reject protocol abuse (PUSH_PROMISE, oversized frames, bad HPACK, dirty ACKs) and cap responses',
   test: new Assertions(Case: function (): Generator {
      // ! Outbox frame parser — the inline unpack idiom, under test control
      $parse = static function (string $raw): array {
         $frames = [];
         $length = strlen($raw);
         $offset = 0;
         while ($length - $offset >= 9) {
            $head = unpack('Nword/Cflags/Nstream', $raw, $offset);
            $size = $head['word'] >> 8;
            $frames[] = [
               'type' => $head['word'] & 0xff,
               'flags' => $head['flags'],
               'stream' => $head['stream'] & 0x7fffffff,
               'payload' => substr($raw, $offset + 9, $size)
            ];
            $offset += 9 + $size;
         }
         return $frames;
      };
      $settle = static function (Session $Session): void {
         $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, (new Settings)->pack()));
         $Session->outbox = '';
      };

      // @ PUSH_PROMISE — we advertise ENABLE_PUSH=0 (RFC 9113 §6.6)
      $Session = new Session;
      $settle($Session);
      $fed = $Session->feed(Frame::pack(
         HTTP2::FRAME_PUSH_PROMISE, HTTP2::FLAG_END_HEADERS, 1,
         pack('N', 2) . HPACK::encode([[':method', 'GET']])
      ));
      yield new Assertion(
         description: 'PUSH_PROMISE → feed() false + Errors::Protocol',
      )
         ->expect([$fed, $Session->error])
         ->to->be([false, Errors::Protocol])
         ->assert();

      // @ A frame header claiming more than our SETTINGS_MAX_FRAME_SIZE
      $Session = new Session;
      $fed = $Session->feed(pack('NcN', (16385 << 8) | HTTP2::FRAME_DATA, 0, 1));
      yield new Assertion(
         description: 'Declared frame length 16385 > 16384 → Errors::FrameSize (no payload needed)',
      )
         ->expect([$fed, $Session->error])
         ->to->be([false, Errors::FrameSize])
         ->assert();

      // @ Garbage HPACK block — compression state is connection state
      $Session = new Session;
      $settle($Session);
      $id = $Session->open('GET', 'https', 'example.com', '/', []);
      $fed = $Session->feed(Frame::pack(
         HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, $id, "\x80"
      ));
      yield new Assertion(
         description: 'Undecodable HEADERS block → Errors::Compression; the unheaded stream fails retryable',
      )
         ->expect([
            $fed,
            $Session->error,
            $Session->done[$id]['error'] ?? null,
            $Session->done[$id]['retryable'] ?? false
         ])
         ->to->be([false, Errors::Compression, Errors::Compression, true])
         ->assert();

      // @ SETTINGS ACK must be empty (RFC 9113 §6.5)
      $Session = new Session;
      $settle($Session);
      $fed = $Session->feed(Frame::pack(
         HTTP2::FRAME_SETTINGS, HTTP2::FLAG_ACK, 0, str_repeat("\x00", 6)
      ));
      yield new Assertion(
         description: 'SETTINGS ACK with payload → Errors::FrameSize',
      )
         ->expect([$fed, $Session->error])
         ->to->be([false, Errors::FrameSize])
         ->assert();

      // @ PING → PING ACK with the same opaque 8 octets
      $Session = new Session;
      $settle($Session);
      $fed = $Session->feed(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'abcdefgh'));
      $frames = $parse($Session->outbox);
      yield new Assertion(
         description: 'PING is answered with ACK + identical payload',
      )
         ->expect([
            $fed,
            $frames[0]['type'] ?? 0,
            $frames[0]['flags'] ?? 0,
            $frames[0]['payload'] ?? ''
         ])
         ->to->be([true, HTTP2::FRAME_PING, HTTP2::FLAG_ACK, 'abcdefgh'])
         ->assert();

      // @ Per-stream response byte cap: $limit octets of head+body maximum
      $Session = new Session;
      $settle($Session);
      $Session->limit = 5;
      $id = $Session->open('GET', 'https', 'example.com', '/', []);
      $Session->feed(Frame::pack(
         HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, $id,
         HPACK::encode([[':status', '200']])
      ));
      $Session->outbox = '';
      $fed = $Session->feed(Frame::pack(HTTP2::FRAME_DATA, 0, $id, 'abcdefgh'));
      $frames = $parse($Session->outbox);
      $reason = unpack('N', $frames[0]['payload'] ?? pack('N', 255));
      yield new Assertion(
         description: '8-byte body over limit=5 → local RST_STREAM(Cancel) + Cancel record, connection alive',
      )
         ->expect([
            $fed,
            $Session->done[$id]['error'] ?? null,
            $Session->done[$id]['retryable'] ?? true,
            $frames[0]['type'] ?? 0,
            $frames[0]['stream'] ?? 0,
            $reason[1],
            $Session->error === null
         ])
         ->to->be([true, Errors::Cancel, false, HTTP2::FRAME_RST_STREAM, $id, Errors::Cancel->value, true])
         ->assert();
   })
);
