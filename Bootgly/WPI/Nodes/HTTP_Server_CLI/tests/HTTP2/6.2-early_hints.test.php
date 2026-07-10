<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should emit an interim :status 103 HEADERS before the final HEADERS',
   test: new Assertions(Case: function (): Generator {
      // @ Preface + GET /hints on stream 1
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/hints'],
            [':authority', 'localhost:8085']
         ]))
      );

      // @ First HEADERS: the interim 103 — END_HEADERS, no END_STREAM
      $interim = $Client->expect(HTTP2::FRAME_HEADERS);

      yield new Assertion(
         description: 'Interim HEADERS carries :status 103 + link, without END_STREAM',
      )
         ->expect(
            $interim !== null
            && $interim['stream'] === 1
            && ($interim['flags'] & HTTP2::FLAG_END_STREAM) === 0
            && $interim['payload'] === HPACK::encode([
               [':status', '103'],
               ['link', '</app.css>; rel=preload; as=style']
            ])
         )
         ->to->be(true)
         ->assert();

      // @ Second HEADERS: the final 200
      $final = $Client->expect(HTTP2::FRAME_HEADERS);

      yield new Assertion(
         description: 'Final HEADERS follows the interim response',
      )
         ->expect($final !== null && $final['stream'] === 1)
         ->to->be(true)
         ->assert();

      // @ DATA: the routed body
      $data = $Client->expect(HTTP2::FRAME_DATA);

      yield new Assertion(
         description: 'Final DATA carries the routed body with END_STREAM',
      )
         ->expect(
            $data !== null
            && $data['stream'] === 1
            && $data['payload'] === 'method=GET;uri=/hints;protocol=HTTP/2;body='
            && ($data['flags'] & HTTP2::FLAG_END_STREAM) !== 0
         )
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
