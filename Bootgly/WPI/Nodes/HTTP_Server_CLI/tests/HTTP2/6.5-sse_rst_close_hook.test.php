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
   description: 'It should run the SSE Close hook deterministically when the peer resets the stream',
   test: new Assertions(Case: function (): Generator {
      $request = static fn (int $stream, string $path): string => Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         $stream,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', 'localhost:8085']
         ])
      );

      // @ Open a held SSE stream (its Close hook stamps a worker-side flag)
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
         . $request(1, '/sse-hold')
      );

      $headers = $Client->expect(HTTP2::FRAME_HEADERS);

      yield new Assertion(
         description: 'Held SSE stream opens without END_STREAM',
      )
         ->expect(
            $headers !== null
            && $headers['stream'] === 1
            && ($headers['flags'] & HTTP2::FLAG_END_STREAM) === 0
         )
         ->to->be(true)
         ->assert();

      // @ Reset the stream, then ask the worker whether the hook ran —
      //   frames are processed in order on the same connection, so the
      //   report request observes the post-RST state deterministically
      $Client->send(
         Frame::pack(HTTP2::FRAME_RST_STREAM, 0, 1, pack('N', Errors::Cancel->value))
         . $request(3, '/sse-hook')
      );

      $data = $Client->expect(HTTP2::FRAME_DATA);

      // ! The hook THROWS after stamping (see the suite handler): a count of
      //   exactly 1 plus a served report prove the exception was contained
      //   and the RST bookkeeping completed anyway
      yield new Assertion(
         description: 'RST_STREAM ran the throwing Close hook exactly once',
      )
         ->expect($data !== null && $data['stream'] === 3 && $data['payload'] === 'closed;count=1')
         ->to->be(true)
         ->assert();

      // @ The connection survives the throwing hook — PING is answered
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'rst-ping'));
      $pong = $Client->expect(HTTP2::FRAME_PING);

      yield new Assertion(
         description: 'Connection still answers PING after the contained hook failure',
      )
         ->expect($pong !== null && ($pong['flags'] & HTTP2::FLAG_ACK) !== 0 && $pong['payload'] === 'rst-ping')
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
