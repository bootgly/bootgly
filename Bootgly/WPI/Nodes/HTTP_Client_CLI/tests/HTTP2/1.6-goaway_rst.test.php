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
   description: 'It should fail streams above the GOAWAY watermark as retryable, honor RST_STREAM codes and close gracefully',
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

      // @ GOAWAY(last=1) with streams 1 and 3 in flight
      $Session = new Session;
      $settle($Session);
      $one = $Session->open('GET', 'https', 'example.com', '/', []);
      $three = $Session->open('GET', 'https', 'example.com', '/', []);
      $fed = $Session->feed(Frame::pack(
         HTTP2::FRAME_GOAWAY, 0, 0, pack('NN', 1, Errors::None->value)
      ));
      yield new Assertion(
         description: 'Stream 3 (above the watermark) fails as retryable RefusedStream with code 0',
      )
         ->expect([
            $fed,
            $Session->done[$three]['error'] ?? null,
            $Session->done[$three]['retryable'] ?? false,
            $Session->done[$three]['code'] ?? -1
         ])
         ->to->be([true, Errors::RefusedStream, true, 0])
         ->assert();

      yield new Assertion(
         description: 'Stream 1 (at/below the watermark) survives the GOAWAY',
      )
         ->expect([isSet($Session->Streams[$one]), isSet($Session->done[$one]), $Session->opened])
         ->to->be([true, false, 1])
         ->assert();

      // @ The surviving stream still completes normally
      $Session->feed(Frame::pack(
         HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, $one,
         HPACK::encode([[':status', '200']])
      ));
      yield new Assertion(
         description: 'Stream 1 completes with 200 after the GOAWAY',
      )
         ->expect([$Session->done[$one]['code'] ?? 0, (isSet($Session->done[$one]) && $Session->done[$one]['error'] === null)])
         ->to->be([200, true])
         ->assert();

      // @ RST_STREAM: only REFUSED_STREAM guarantees no processing (§8.7)
      $Reset = new Session;
      $settle($Reset);
      $a = $Reset->open('GET', 'https', 'example.com', '/', []); // 1
      $b = $Reset->open('GET', 'https', 'example.com', '/', []); // 3
      $Reset->feed(
         Frame::pack(HTTP2::FRAME_RST_STREAM, 0, $a, pack('N', Errors::RefusedStream->value))
         . Frame::pack(HTTP2::FRAME_RST_STREAM, 0, $b, pack('N', Errors::Cancel->value))
      );
      yield new Assertion(
         description: 'RST_STREAM(RefusedStream) → retryable true; RST_STREAM(Cancel) → retryable false',
      )
         ->expect([
            $Reset->done[$a]['error'] ?? null,
            $Reset->done[$a]['retryable'] ?? false,
            $Reset->done[$b]['error'] ?? null,
            $Reset->done[$b]['retryable'] ?? true
         ])
         ->to->be([Errors::RefusedStream, true, Errors::Cancel, false])
         ->assert();

      yield new Assertion(
         description: 'RST_STREAM is a stream event — the connection stays alive',
      )
         ->expect([$Reset->error === null, $Reset->opened])
         ->to->be([true, 0])
         ->assert();

      // @ Local graceful close: GOAWAY(None) out + no more opens
      $Closer = new Session;
      $settle($Closer);
      $Closer->close();
      $frames = $parse($Closer->outbox);
      $reason = unpack('Nlast/Ncode', $frames[0]['payload'] ?? pack('NN', 255, 255));
      yield new Assertion(
         description: 'close() queues GOAWAY(None) on stream 0 and flags closing',
      )
         ->expect([
            count($frames),
            $frames[0]['type'] ?? 0,
            $frames[0]['stream'] ?? -1,
            $reason['code'],
            $Closer->closing
         ])
         ->to->be([1, HTTP2::FRAME_GOAWAY, 0, Errors::None->value, true])
         ->assert();

      yield new Assertion(
         description: 'open() after close() returns 0',
      )
         ->expect($Closer->open('GET', 'https', 'example.com', '/', []))
         ->to->be(0)
         ->assert();
   })
);
