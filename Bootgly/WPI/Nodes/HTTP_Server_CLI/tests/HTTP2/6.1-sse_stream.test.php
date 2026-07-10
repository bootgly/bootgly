<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should sustain an SSE stream: HEADERS + DATA without END_STREAM, then a final END_STREAM',
   test: new Assertions(Case: function (): Generator {
      // @ Preface + GET /sse on stream 1
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/sse'],
            [':authority', 'localhost:8085']
         ]))
      );

      // @ Interim-free sustained head: HEADERS carries END_HEADERS, no END_STREAM
      $headers = $Client->expect(HTTP2::FRAME_HEADERS);

      yield new Assertion(
         description: 'Response HEADERS arrives without END_STREAM (stream stays open)',
      )
         ->expect(
            $headers !== null
            && $headers['stream'] === 1
            && ($headers['flags'] & HTTP2::FLAG_END_HEADERS) !== 0
            && ($headers['flags'] & HTTP2::FLAG_END_STREAM) === 0
         )
         ->to->be(true)
         ->assert();

      // @ Event bytes ride in DATA without END_STREAM
      $event = $Client->expect(HTTP2::FRAME_DATA);

      yield new Assertion(
         description: 'Event DATA frame carries the serialized event, no END_STREAM',
      )
         ->expect(
            $event !== null
            && $event['stream'] === 1
            && $event['payload'] === "event: tick\nid: 1\ndata: h2\n\n"
            && ($event['flags'] & HTTP2::FLAG_END_STREAM) === 0
         )
         ->to->be(true)
         ->assert();

      // @ close() ends the stream with an empty DATA + END_STREAM
      $final = $Client->expect(HTTP2::FRAME_DATA);

      yield new Assertion(
         description: 'Final empty DATA frame carries END_STREAM',
      )
         ->expect(
            $final !== null
            && $final['stream'] === 1
            && $final['payload'] === ''
            && ($final['flags'] & HTTP2::FLAG_END_STREAM) !== 0
         )
         ->to->be(true)
         ->assert();

      // @ The connection survives the stream: PING is answered...
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'sse-ping'));
      $pong = $Client->expect(HTTP2::FRAME_PING);

      yield new Assertion(
         description: 'Connection still answers PING after the SSE stream ended',
      )
         ->expect($pong !== null && ($pong['flags'] & HTTP2::FLAG_ACK) !== 0 && $pong['payload'] === 'sse-ping')
         ->to->be(true)
         ->assert();

      // @ ...and a new stream dispatches normally (stream 1 was released)
      $Client->send(
         Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 3, HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/after'],
            [':authority', 'localhost:8085']
         ]))
      );
      $data = $Client->expect(HTTP2::FRAME_DATA);

      yield new Assertion(
         description: 'A later stream on the same connection routes normally',
      )
         ->expect($data !== null && $data['stream'] === 3 && $data['payload'] === 'method=GET;uri=/after;protocol=HTTP/2;body=')
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
