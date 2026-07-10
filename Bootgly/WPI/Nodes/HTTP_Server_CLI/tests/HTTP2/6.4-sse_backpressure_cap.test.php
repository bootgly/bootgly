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
   description: 'It should reset a flow-control-starved SSE stream instead of growing the backlog',
   test: new Assertions(Case: function (): Generator {
      // @ Preface with SETTINGS_INITIAL_WINDOW_SIZE = 0 — the server can
      //   never send DATA on any stream; the handler pushes an event bigger
      //   than the backlog cap, which must RST the stream (CANCEL)
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, pack('nN', 0x4, 0))
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/sse-cap'],
            [':authority', 'localhost:8085']
         ]))
      );

      // @ The head still goes out (HEADERS are not flow-controlled)
      $headers = $Client->expect(HTTP2::FRAME_HEADERS, 5.0);

      yield new Assertion(
         description: 'Sustained HEADERS arrives without END_STREAM',
      )
         ->expect(
            $headers !== null
            && $headers['stream'] === 1
            && ($headers['flags'] & HTTP2::FLAG_END_STREAM) === 0
         )
         ->to->be(true)
         ->assert();

      // @ The oversized event breaches the backlog cap → RST_STREAM(CANCEL)
      $reset = $Client->expect(HTTP2::FRAME_RST_STREAM, 5.0);

      yield new Assertion(
         description: 'Backlog cap breach resets the stream with CANCEL',
      )
         ->expect(
            $reset !== null
            && $reset['stream'] === 1
            && $reset['payload'] === pack('N', Errors::Cancel->value)
         )
         ->to->be(true)
         ->assert();

      // @ The connection survives — PING is answered
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'cap-ping'));
      $pong = $Client->expect(HTTP2::FRAME_PING);

      yield new Assertion(
         description: 'Connection still answers PING after the stream reset',
      )
         ->expect($pong !== null && ($pong['flags'] & HTTP2::FLAG_ACK) !== 0 && $pong['payload'] === 'cap-ping')
         ->to->be(true)
         ->assert();

      // @ The breach contract: the rejected send() returned false and the
      //   Close hook ran exactly once (same worker reports its own state).
      //   Restore the send window first — the preface pinned it at 0, which
      //   would park the report DATA in the backlog forever.
      $Client->send(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, pack('nN', 0x4, 65535)));
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         3,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/sse-cap-report'],
            [':authority', 'localhost:8085']
         ])
      ));
      $report = $Client->expect(HTTP2::FRAME_DATA, 5.0);

      yield new Assertion(
         description: 'Cap breach: send() === false and Close ran exactly once',
      )
         ->expect($report !== null && $report['stream'] === 3 && $report['payload'] === 'sent=false;hooks=1')
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
