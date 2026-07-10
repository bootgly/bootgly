<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should bound the SSE backlog per connection, not per stream',
   test: new Assertions(Case: function (): Generator {
      $request = static fn (int $stream): string => Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         $stream,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/sse-agg'],
            [':authority', 'localhost:8085']
         ])
      );

      // @ Zero send window: each `/sse-agg` event (3 MiB) parks in its
      //   stream backlog. One stream fits the 4 MiB connection budget; the
      //   second one must breach it — 128 slow streams cannot multiply the
      //   cap into hundreds of MiB per connection.
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, pack('nN', 0x4, 0))
         . $request(1)
      );

      $first = $Client->expect(HTTP2::FRAME_HEADERS, 5.0);

      yield new Assertion(
         description: 'First starved stream opens and parks below the budget',
      )
         ->expect(
            $first !== null
            && $first['stream'] === 1
            && ($first['flags'] & HTTP2::FLAG_END_STREAM) === 0
         )
         ->to->be(true)
         ->assert();

      // @ Second stream: its event alone fits, the aggregate does not
      $Client->send($request(3));

      $reset = $Client->expect(HTTP2::FRAME_RST_STREAM, 5.0);

      yield new Assertion(
         description: 'Aggregate breach resets the SECOND stream with CANCEL',
      )
         ->expect(
            $reset !== null
            && $reset['stream'] === 3
            && $reset['payload'] === pack('N', Errors::Cancel->value)
         )
         ->to->be(true)
         ->assert();

      // @ The first stream and the connection survive — PING is answered
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'agg-ping'));
      $pong = $Client->expect(HTTP2::FRAME_PING);

      yield new Assertion(
         description: 'Connection still answers PING after the aggregate reset',
      )
         ->expect($pong !== null && ($pong['flags'] & HTTP2::FLAG_ACK) !== 0 && $pong['payload'] === 'agg-ping')
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
