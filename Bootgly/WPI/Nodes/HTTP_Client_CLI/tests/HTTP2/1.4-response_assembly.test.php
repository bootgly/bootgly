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
   description: 'It should assemble responses (head + body + trailers + interim + CONTINUATION) into completion records',
   test: new Assertions(Case: function (): Generator {
      // ! One settled session serves every scenario (streams 1, 3, 5, ...)
      $Session = new Session;
      $Server = new Settings;
      $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, $Server->pack()));
      $Session->outbox = '';

      // @ Plain exchange: HEADERS (multi-value fields) + DATA + END_STREAM
      $id = $Session->open('GET', 'https', 'example.com', '/', []); // 1
      $block = HPACK::encode([
         [':status', '200'],
         ['content-length', '5'],
         ['x-multi', 'a'],
         ['x-multi', 'b']
      ]);
      $fed = $Session->feed(
         Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, $id, $block)
         . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, $id, 'hello')
      );
      $done = $Session->done[$id] ?? null;
      yield new Assertion(
         description: '200 + content-length 5 + body "hello" → completion record, stream released',
      )
         ->expect([$fed, $done['code'] ?? 0, $done['body'] ?? '', ($done !== null && $done['error'] === null), $Session->opened])
         ->to->be([true, 200, 'hello', true, 0])
         ->assert();

      yield new Assertion(
         description: 'headerRaw keeps both x-multi values as separate lines',
      )
         ->expect([
            str_contains($done['headerRaw'] ?? '', "x-multi: a\r\n"),
            str_contains($done['headerRaw'] ?? '', "x-multi: b\r\n"),
            str_contains($done['headerRaw'] ?? '', "content-length: 5\r\n")
         ])
         ->to->be([true, true, true])
         ->assert();

      // @ Interim 1xx head is discarded; the final head completes the stream
      $id = $Session->open('GET', 'https', 'example.com', '/', []); // 3
      $Session->feed(Frame::pack(
         HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, $id,
         HPACK::encode([[':status', '100']])
      ));
      yield new Assertion(
         description: ':status 100 → no completion record, stream stays open',
      )
         ->expect([isSet($Session->done[$id]), isSet($Session->Streams[$id])])
         ->to->be([false, true])
         ->assert();

      $Session->feed(Frame::pack(
         HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, $id,
         HPACK::encode([[':status', '200']])
      ));
      yield new Assertion(
         description: 'The following final head → single completion record with code 200',
      )
         ->expect([$Session->done[$id]['code'] ?? 0, (isSet($Session->done[$id]) && $Session->done[$id]['error'] === null)])
         ->to->be([200, true])
         ->assert();

      // @ HEAD request: declared content-length without a body is fine
      $id = $Session->open('HEAD', 'https', 'example.com', '/h', []); // 5
      $Session->feed(Frame::pack(
         HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, $id,
         HPACK::encode([[':status', '200'], ['content-length', '10']])
      ));
      yield new Assertion(
         description: 'HEAD + content-length 10 + empty body → completes without error',
      )
         ->expect([$Session->done[$id]['code'] ?? 0, (isSet($Session->done[$id]) && $Session->done[$id]['error'] === null), $Session->done[$id]['body'] ?? null])
         ->to->be([200, true, ''])
         ->assert();

      // @ 204: same content-length exemption (RFC 9113 §8.1.1)
      $id = $Session->open('GET', 'https', 'example.com', '/', []); // 7
      $Session->feed(Frame::pack(
         HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, $id,
         HPACK::encode([[':status', '204'], ['content-length', '10']])
      ));
      yield new Assertion(
         description: '204 + content-length without body → completes without error',
      )
         ->expect([$Session->done[$id]['code'] ?? 0, (isSet($Session->done[$id]) && $Session->done[$id]['error'] === null)])
         ->to->be([204, true])
         ->assert();

      // @ Trailers: HEADERS after the head + body, END_STREAM, content discarded
      $id = $Session->open('GET', 'https', 'example.com', '/', []); // 9
      $Session->feed(
         Frame::pack(
            HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, $id,
            HPACK::encode([[':status', '200'], ['content-length', '3']])
         )
         . Frame::pack(HTTP2::FRAME_DATA, 0, $id, 'abc')
         . Frame::pack(
            HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, $id,
            HPACK::encode([['x-trailer', 't']])
         )
      );
      yield new Assertion(
         description: 'Trailers finish the stream; trailer fields stay out of headerRaw',
      )
         ->expect([
            $Session->done[$id]['code'] ?? 0,
            $Session->done[$id]['body'] ?? '',
            str_contains($Session->done[$id]['headerRaw'] ?? 'x-trailer', 'x-trailer'),
            (isSet($Session->done[$id]) && $Session->done[$id]['error'] === null)
         ])
         ->to->be([200, 'abc', false, true])
         ->assert();

      // @ Inbound CONTINUATION: one head split across HEADERS + 2 CONTINUATIONs
      $id = $Session->open('GET', 'https', 'example.com', '/', []); // 11
      $big = str_repeat('b', 300);
      $block = HPACK::encode([[':status', '200'], ['x-big', $big]]);
      $Session->feed(
         Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_STREAM, $id, substr($block, 0, 10))
         . Frame::pack(HTTP2::FRAME_CONTINUATION, 0, $id, substr($block, 10, 150))
         . Frame::pack(HTTP2::FRAME_CONTINUATION, HTTP2::FLAG_END_HEADERS, $id, substr($block, 160))
      );
      yield new Assertion(
         description: 'Fragmented head assembles: code 200 + reassembled x-big value',
      )
         ->expect([
            $Session->done[$id]['code'] ?? 0,
            str_contains($Session->done[$id]['headerRaw'] ?? '', "x-big: $big\r\n")
         ])
         ->to->be([200, true])
         ->assert();

      // @ Declared content-length must match the received body
      $id = $Session->open('GET', 'https', 'example.com', '/', []); // 13
      $Session->outbox = '';
      $fed = $Session->feed(
         Frame::pack(
            HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, $id,
            HPACK::encode([[':status', '200'], ['content-length', '5']])
         )
         . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, $id, 'ab')
      );
      /** @var array{word: int, flags: int, stream: int} $head */
      $head = unpack('Nword/Cflags/Nstream', $Session->outbox);
      $reason = unpack('N', substr($Session->outbox, 9, 4));
      yield new Assertion(
         description: 'content-length 5 vs 2-byte body → stream error: RST_STREAM(Protocol) + failed record',
      )
         ->expect([
            $fed,
            $Session->done[$id]['error'] ?? null,
            $Session->done[$id]['retryable'] ?? true,
            $head['word'] & 0xff,
            $head['stream'] & 0x7fffffff,
            $reason[1]
         ])
         ->to->be([true, Errors::Protocol, false, HTTP2::FRAME_RST_STREAM, $id, Errors::Protocol->value])
         ->assert();

      yield new Assertion(
         description: 'A stream error never kills the connection',
      )
         ->expect($Session->error === null)
         ->to->be(true)
         ->assert();
   })
);
