<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


// ! One tick event on the wire: `data: ` + 32768×`r` + `\n\n`
const SSE_EVENT = 32776;

return new Specification(
   description: 'It should never reset a progressing stream, even across fully drained backlogs',
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
      $credit = static fn (int $stream, int $bytes): string => Frame::pack(
         HTTP2::FRAME_WINDOW_UPDATE, 0, $stream, pack('N', $bytes)
      );

      // @ Read DATA payload bytes (stream 1) until `$bytes` accumulate or
      //   `$timeout` elapses; an RST_STREAM anywhere flips the flag
      $Client = new Client;
      $collect = static function (int $bytes, float $timeout) use ($Client): array {
         $got = 0;
         $reset = false;
         $until = microtime(true) + $timeout;

         while ($got < $bytes && microtime(true) < $until) {
            $frame = $Client->frame(0.25);
            if ($frame === null) {
               continue;
            }
            if ($frame['type'] === HTTP2::FRAME_DATA && $frame['stream'] === 1) {
               $got += strlen($frame['payload']);
            }
            if ($frame['type'] === HTTP2::FRAME_RST_STREAM) {
               $reset = true;
               break;
            }
         }

         return [$got, $reset];
      };

      // @ Zero send window; the route ticks one 32 KiB event per second
      //   with the stall deadline shrunk to 2s (suite handler). Each
      //   generation: a 1-byte stream credit is the PARK PROBE — its byte
      //   arrives the moment the tick parks (park → 1 byte drains, rest
      //   stays parked) — then the remainder credit drains the generation.
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, pack('nN', 0x4, 0))
         . $request(1, '/sse-repark')
      );

      $headers = $Client->expect(HTTP2::FRAME_HEADERS, 5.0);

      yield new Assertion(
         description: 'Producer stream opens sustained (no END_STREAM)',
      )
         ->expect(
            $headers !== null
            && $headers['stream'] === 1
            && ($headers['flags'] & HTTP2::FLAG_END_STREAM) === 0
         )
         ->to->be(true)
         ->assert();

      // @ Generation 1 — park, then drain fully (arms the old-model stale
      //   timestamp: with net-size sampling this generation's clock would
      //   survive the full drain and poison generation 2)
      $Client->send($credit(0, SSE_EVENT) . $credit(1, 1));
      [$probe1, $reset1] = $collect(1, 6.0);
      $Client->send($credit(1, SSE_EVENT - 1));
      [$rest1, $restReset1] = $collect(SSE_EVENT - 1, 5.0);

      yield new Assertion(
         description: 'Generation 1 parks and fully drains without a reset',
      )
         ->expect(
            $probe1 === 1 && $rest1 === SSE_EVENT - 1
            && $reset1 === false && $restReset1 === false
         )
         ->to->be(true)
         ->assert();

      // @ Generation 2 — park again, then HOLD through the discriminating
      //   window: a stale generation-1 timestamp is past the deadline by
      //   now (stale clock → RST here); the fresh park's clock is not.
      $Client->send($credit(0, SSE_EVENT) . $credit(1, 1));
      [$probe2, $reset2] = $collect(1, 6.0);
      [$noise, $resetHold] = $collect(SSE_EVENT, 2.3);

      yield new Assertion(
         description: 'A fresh park right after a full drain is NOT treated as the old stall',
      )
         ->expect($probe2 === 1 && $noise === 0 && $reset2 === false && $resetHold === false)
         ->to->be(true)
         ->assert();

      // @ ... and the FIRST generation-2 event still drains once credit
      //   arrives. Ticks kept producing during the hold, so later events
      //   remain parked behind it — deliberately: the withheld-credit
      //   phase below needs that residual backlog to hit the deadline.
      $Client->send($credit(1, SSE_EVENT - 1));
      [$rest2, $restReset2] = $collect(SSE_EVENT - 1, 5.0);

      yield new Assertion(
         description: 'The first generation-2 event drains after the hold',
      )
         ->expect($rest2 === SSE_EVENT - 1 && $restReset2 === false)
         ->to->be(true)
         ->assert();

      // @ Credit stops for good — the next parked tick must hit the
      //   deadline and end in RST_STREAM(CANCEL)
      $reset = $Client->expect(HTTP2::FRAME_RST_STREAM, 8.0);

      yield new Assertion(
         description: 'Withheld credit still ends in RST_STREAM(CANCEL)',
      )
         ->expect(
            $reset !== null
            && $reset['stream'] === 1
            && $reset['payload'] === pack('N', Errors::Cancel->value)
         )
         ->to->be(true)
         ->assert();

      // @ Restore the suite-wide deadline (window first — see 6.4/6.8)
      $Client->send(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, pack('nN', 0x4, 65535)));
      $Client->send($request(3, '/sse-repark-restore'));

      $report = $Client->expect(HTTP2::FRAME_DATA, 5.0);

      yield new Assertion(
         description: 'Deadline restored; the connection still serves requests',
      )
         ->expect($report !== null && $report['stream'] === 3 && $report['payload'] === 'restored')
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
