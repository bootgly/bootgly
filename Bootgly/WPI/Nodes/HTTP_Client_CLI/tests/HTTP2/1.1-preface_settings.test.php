<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\Settings;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Session;


return new Specification(
   description: 'It should lead with PREFACE + SETTINGS (push disabled) and demand the server preface first',
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

      // @ The constructor queues the client preface + our SETTINGS
      $Session = new Session;
      yield new Assertion(
         description: 'Outbox starts with the RFC 9113 §3.4 connection preface',
      )
         ->expect(substr($Session->outbox, 0, strlen(HTTP2::PREFACE)))
         ->to->be(HTTP2::PREFACE)
         ->assert();

      $frames = $parse(substr($Session->outbox, strlen(HTTP2::PREFACE)));
      yield new Assertion(
         description: 'The preface is followed by exactly one frame: non-ACK SETTINGS on stream 0',
      )
         ->expect([count($frames), $frames[0]['type'], $frames[0]['flags'], $frames[0]['stream']])
         ->to->be([1, HTTP2::FRAME_SETTINGS, 0, 0])
         ->assert();

      $Advertised = new Settings;
      $Advertised->parse($frames[0]['payload']);
      yield new Assertion(
         description: 'Our SETTINGS advertise ENABLE_PUSH=0, MAX_CONCURRENT_STREAMS=128, MAX_HEADER_LIST_SIZE=16384',
      )
         ->expect([$Advertised->push, $Advertised->streams, $Advertised->list])
         ->to->be([false, 128, 16384])
         ->assert();

      // @ The server preface (non-ACK SETTINGS) settles the connection + is ACKed
      $Session->outbox = '';
      $Server = new Settings;
      $fed = $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, $Server->pack()));
      $frames = $parse($Session->outbox);
      yield new Assertion(
         description: 'Server SETTINGS → feed() true, settled, SETTINGS+ACK (empty payload) queued',
      )
         ->expect([
            $fed,
            $Session->settled,
            $frames[0]['type'] ?? 0,
            $frames[0]['flags'] ?? 0,
            $frames[0]['payload'] ?? null
         ])
         ->to->be([true, true, HTTP2::FRAME_SETTINGS, HTTP2::FLAG_ACK, ''])
         ->assert();

      // @ Anything but SETTINGS first (here: a PING) is a Protocol connection error
      $Bad = new Session;
      $Bad->outbox = '';
      $fed = $Bad->feed(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'pingpong'));
      yield new Assertion(
         description: 'PING before the server SETTINGS → feed() false + Errors::Protocol',
      )
         ->expect([$fed, $Bad->error])
         ->to->be([false, Errors::Protocol])
         ->assert();

      $frames = $parse($Bad->outbox);
      $goaway = $frames[count($frames) - 1];
      $reason = unpack('Nlast/Ncode', $goaway['payload']);
      yield new Assertion(
         description: 'GOAWAY(Protocol) queued on stream 0',
      )
         ->expect([$goaway['type'], $goaway['stream'], $reason['code']])
         ->to->be([HTTP2::FRAME_GOAWAY, 0, Errors::Protocol->value])
         ->assert();

      // @ A dead engine refuses further bytes
      $fed = $Bad->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, $Server->pack()));
      yield new Assertion(
         description: 'feed() after a connection error keeps returning false',
      )
         ->expect($fed)
         ->to->be(false)
         ->assert();
   })
);
