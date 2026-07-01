<?php


use function microtime;
use function number_format;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should sustain multiplexed throughput (smoke — guards against catastrophic h2 regressions)',
   test: new Assertions(Case: function (): Generator {
      // ! One connection, batches of 100 concurrent streams (cap is 128)
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);

      $block = HPACK::encode([
         [':method', 'GET'],
         [':scheme', 'http'],
         [':path', '/t'],
         [':authority', 'localhost:8085']
      ]);

      $target = 2000;
      $served = 0;
      $stream = 1;
      $started = microtime(true);

      // @@ Send 100 HEADERS per flight, then drain the 100 responses.
      //   Real clients replenish the connection send window — do the same,
      //   or the server rightfully parks DATA at the 65,535-octet default.
      while ($served < $target) {
         $flight = '';
         for ($i = 0; $i < 100; $i++) {
            $flight .= Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
               $stream,
               $block
            );
            $stream += 2;
         }
         $Client->send($flight);

         $consumed = 0;
         for ($i = 0; $i < 100; $i++) {
            $data = $Client->expect(HTTP2::FRAME_DATA, 5.0);
            if ($data === null) {
               break 2;
            }
            $consumed += strlen($data['payload']);
            $served++;
         }

         $Client->send(Frame::pack(HTTP2::FRAME_WINDOW_UPDATE, 0, 0, pack('N', $consumed)));
      }

      $elapsed = microtime(true) - $started;
      $rate = (int) ($served / $elapsed);

      yield new Assertion(
         description: "All {$target} multiplexed streams served — " . number_format($rate) . ' req/s on 1 worker/1 connection',
      )
         ->expect($served)
         ->to->be($target)
         ->assert();

      // @ Smoke floor: an order of magnitude below any sane result — trips
      //   only on catastrophic regressions (busy loops, per-frame stalls).
      yield new Assertion(
         description: 'Throughput above the 1,000 req/s smoke floor',
      )
         ->expect($rate > 1000)
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
