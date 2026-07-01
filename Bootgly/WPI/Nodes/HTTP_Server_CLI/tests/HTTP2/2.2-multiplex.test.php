<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should dispatch multiple streams sent in one TCP flight (multiplexing)',
   test: new Assertions(Case: function (): Generator {
      $request = static fn (string $path): array => [
         [':method', 'GET'],
         [':scheme', 'http'],
         [':path', $path],
         [':authority', 'localhost:8085']
      ];

      // @ Preface + SETTINGS + three GETs in ONE write
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, HPACK::encode($request('/a')))
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 3, HPACK::encode($request('/b')))
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 5, HPACK::encode($request('/c')))
      );

      // @ Collect the three DATA frames (order must follow stream ids)
      $responses = [];
      for ($i = 0; $i < 3; $i++) {
         $data = $Client->expect(HTTP2::FRAME_DATA);
         if ($data === null) {
            break;
         }
         $responses[$data['stream']] = $data['payload'];
      }

      yield new Assertion(
         description: 'Three streams in one flight → three routed responses',
      )
         ->expect($responses)
         ->to->be([
            1 => 'method=GET;uri=/a;protocol=HTTP/2;body=',
            3 => 'method=GET;uri=/b;protocol=HTTP/2;body=',
            5 => 'method=GET;uri=/c;protocol=HTTP/2;body='
         ])
         ->assert();

      // @ Interleaved: HEADERS(7) + HEADERS(9) then DATA(7) + DATA(9)
      $Client->send(
         Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 7, HPACK::encode([
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', '/seven'],
            [':authority', 'localhost:8085']
         ]))
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 9, HPACK::encode([
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', '/nine'],
            [':authority', 'localhost:8085']
         ]))
         . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 7, 'seven')
         . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 9, 'nine!')
      );

      $responses = [];
      for ($i = 0; $i < 2; $i++) {
         $data = $Client->expect(HTTP2::FRAME_DATA);
         if ($data === null) {
            break;
         }
         $responses[$data['stream']] = $data['payload'];
      }

      yield new Assertion(
         description: 'Interleaved HEADERS/DATA across two streams → both dispatched with bodies',
      )
         ->expect($responses)
         ->to->be([
            7 => 'method=POST;uri=/seven;protocol=HTTP/2;body=seven',
            9 => 'method=POST;uri=/nine;protocol=HTTP/2;body=nine!'
         ])
         ->assert();

      $Client->close();
   })
);
