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
   description: 'It should reset a gracefully-closed stream whose parked tail never drains',
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

      // @ Zero send window: the event parks, the handler close()s — the
      //   producer detaches but the drain watchdog stays; with the deadline
      //   shrunk to 1s (suite handler), the withheld WINDOW_UPDATE must
      //   end in RST_STREAM(CANCEL) instead of an immortal parked tail
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, pack('nN', 0x4, 0))
         . $request(1, '/sse-drain')
      );

      $headers = $Client->expect(HTTP2::FRAME_HEADERS, 5.0);

      yield new Assertion(
         description: 'Stream opens sustained (no END_STREAM) with the tail parked',
      )
         ->expect(
            $headers !== null
            && $headers['stream'] === 1
            && ($headers['flags'] & HTTP2::FLAG_END_STREAM) === 0
         )
         ->to->be(true)
         ->assert();

      // @ No WINDOW_UPDATE is ever sent — the watchdog must reset
      $reset = $Client->expect(HTTP2::FRAME_RST_STREAM, 6.0);

      yield new Assertion(
         description: 'The stalled drain is reset with CANCEL past the deadline',
      )
         ->expect(
            $reset !== null
            && $reset['stream'] === 1
            && $reset['payload'] === pack('N', Errors::Cancel->value)
         )
         ->to->be(true)
         ->assert();

      // @ Restore the suite-wide deadline; the window must be restored
      //   first or the report DATA would park like the SSE tail did
      $Client->send(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, pack('nN', 0x4, 65535)));
      $Client->send($request(3, '/sse-drain-restore'));

      $data = $Client->expect(HTTP2::FRAME_DATA, 5.0);

      yield new Assertion(
         description: 'Deadline restored; the connection still serves requests',
      )
         ->expect($data !== null && $data['stream'] === 3 && $data['payload'] === 'restored')
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
