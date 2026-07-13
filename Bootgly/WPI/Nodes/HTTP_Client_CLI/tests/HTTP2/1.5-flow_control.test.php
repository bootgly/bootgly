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
   description: 'It should bound outbound DATA by both send windows and replenish/enforce the receive windows',
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

      // @ SEND flow: the server advertises INITIAL_WINDOW_SIZE=10 BEFORE the
      //   stream opens — a 25-byte body must stop after 10 framed octets
      $Session = new Session;
      $Server = new Settings;
      $Server->window = 10;
      $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, $Server->pack()));
      $Session->outbox = '';
      $id = $Session->open('POST', 'https', 'example.com', '/u', [], str_repeat('x', 25));
      $frames = $parse($Session->outbox);
      yield new Assertion(
         description: 'HEADERS + one DATA of exactly 10 octets (stream window), no END_STREAM',
      )
         ->expect([
            $id,
            count($frames),
            $frames[0]['type'],
            $frames[1]['type'],
            strlen($frames[1]['payload']),
            ($frames[1]['flags'] & HTTP2::FLAG_END_STREAM) !== 0
         ])
         ->to->be([1, 2, HTTP2::FRAME_HEADERS, HTTP2::FRAME_DATA, 10, false])
         ->assert();

      yield new Assertion(
         description: 'The unsent 15-byte tail is parked in the stream backlog',
      )
         ->expect($Session->Streams[$id]->backlog ?? '')
         ->to->be(str_repeat('x', 15))
         ->assert();

      // @ WINDOW_UPDATE credit (connection + stream) pumps the tail out
      $Session->outbox = '';
      $Session->feed(
         Frame::pack(HTTP2::FRAME_WINDOW_UPDATE, 0, 0, pack('N', 100))
         . Frame::pack(HTTP2::FRAME_WINDOW_UPDATE, 0, $id, pack('N', 100))
      );
      $frames = $parse($Session->outbox);
      yield new Assertion(
         description: 'Credit → one DATA with the remaining 15 octets + END_STREAM, backlog empty',
      )
         ->expect([
            count($frames),
            $frames[0]['type'] ?? 0,
            strlen($frames[0]['payload'] ?? ''),
            (($frames[0]['flags'] ?? 0) & HTTP2::FLAG_END_STREAM) !== 0,
            $Session->Streams[$id]->backlog ?? null
         ])
         ->to->be([1, HTTP2::FRAME_DATA, 15, true, ''])
         ->assert();

      // @ RECEIVE flow: 2 × 16384 DATA octets cross the 32768 replenish
      //   threshold → WINDOW_UPDATE for the connection AND the stream
      $Receiver = new Session;
      $Receiver->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, (new Settings)->pack()));
      $id = $Receiver->open('GET', 'https', 'example.com', '/', []);
      $Receiver->feed(Frame::pack(
         HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, $id,
         HPACK::encode([[':status', '200']])
      ));
      $Receiver->outbox = '';
      $chunk = str_repeat('d', 16384);
      $Receiver->feed(
         Frame::pack(HTTP2::FRAME_DATA, 0, $id, $chunk)
         . Frame::pack(HTTP2::FRAME_DATA, 0, $id, $chunk)
      );
      $updates = [];
      foreach ($parse($Receiver->outbox) as $frame) {
         if ($frame['type'] === HTTP2::FRAME_WINDOW_UPDATE) {
            $increment = unpack('N', $frame['payload']);
            $updates[$frame['stream']] = $increment[1];
         }
      }
      yield new Assertion(
         description: '32768 consumed octets → WINDOW_UPDATE(0, 32768) + WINDOW_UPDATE(stream, 32768)',
      )
         ->expect($updates)
         ->to->be([0 => 32768, $id => 32768])
         ->assert();

      // @ VIOLATION: with replenishment disabled the 65535-octet grant is
      //   finite — 4 × 16384 = 65536 octets exceed it by one → FlowControl
      $saved = Session::$replenish;
      Session::$replenish = PHP_INT_MAX;
      $Strict = new Session;
      $Strict->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, (new Settings)->pack()));
      $id = $Strict->open('GET', 'https', 'example.com', '/', []);
      $Strict->feed(Frame::pack(
         HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, $id,
         HPACK::encode([[':status', '200']])
      ));
      $fed = true;
      for ($i = 0; $i < 4 && $fed; $i++) {
         $fed = $Strict->feed(Frame::pack(HTTP2::FRAME_DATA, 0, $id, $chunk));
      }
      Session::$replenish = $saved;

      yield new Assertion(
         description: 'DATA beyond the granted window → feed() false + Errors::FlowControl',
      )
         ->expect([$fed, $Strict->error])
         ->to->be([false, Errors::FlowControl])
         ->assert();
   })
);
