<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should serve routed GET/POST/HEAD requests over h2c prior knowledge',
   test: new Assertions(Case: function (): Generator {
      // @ Helper: decode a response HEADERS payload into a fields map
      $decode = static function (null|array $frame): array {
         $pairs = (new HPACK)->decode($frame['payload'] ?? '', PHP_INT_MAX) ?? [];
         $map = [];
         foreach ($pairs as [$name, $value]) {
            $map[$name] = $value;
         }
         return $map;
      };

      // @ GET / — HEADERS with END_STREAM (bodyless request)
      $Client = new Client;
      $Client->preface();
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         1,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'localhost:8085']
         ])
      ));

      $headers = $Client->expect(HTTP2::FRAME_HEADERS);
      $map = $decode($headers);
      yield new Assertion(
         description: 'GET / → HEADERS on stream 1 with :status 200',
      )
         ->expect([$headers['stream'] ?? 0, $map[':status'] ?? ''])
         ->to->be([1, '200'])
         ->assert();

      $data = $Client->expect(HTTP2::FRAME_DATA);
      yield new Assertion(
         description: 'GET / → DATA carries the routed handler payload + END_STREAM',
      )
         ->expect([$data['payload'] ?? '', ($data['flags'] ?? 0) & HTTP2::FLAG_END_STREAM])
         ->to->be(['method=GET;uri=/;protocol=HTTP/2;body=', HTTP2::FLAG_END_STREAM])
         ->assert();

      yield new Assertion(
         description: 'GET / → content-length matches the DATA payload',
      )
         ->expect($map['content-length'] ?? '')
         ->to->be((string) strlen($data['payload'] ?? ''))
         ->assert();

      // @ POST with body — HEADERS + DATA(END_STREAM)
      $Client->send(
         Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS,
            3,
            HPACK::encode([
               [':method', 'POST'],
               [':scheme', 'http'],
               [':path', '/echo?x=1'],
               [':authority', 'localhost:8085'],
               ['content-type', 'application/x-www-form-urlencoded'],
               ['content-length', '7']
            ])
         )
         . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 3, 'a=1&b=2')
      );

      $headers = $Client->expect(HTTP2::FRAME_HEADERS);
      $data = $Client->expect(HTTP2::FRAME_DATA);
      yield new Assertion(
         description: 'POST /echo?x=1 with 7-byte body → routed with body available',
      )
         ->expect([$headers['stream'] ?? 0, $data['payload'] ?? ''])
         ->to->be([3, 'method=POST;uri=/echo?x=1;protocol=HTTP/2;body=a=1&b=2'])
         ->assert();

      // @ HEAD — headers only, END_STREAM on HEADERS, no DATA frame
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         5,
         HPACK::encode([
            [':method', 'HEAD'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'localhost:8085']
         ])
      ));

      $headers = $Client->expect(HTTP2::FRAME_HEADERS);
      $map = $decode($headers);
      yield new Assertion(
         description: 'HEAD / → HEADERS carries END_STREAM (no DATA) with content-length intact',
      )
         ->expect([
            ($headers['flags'] ?? 0) & HTTP2::FLAG_END_STREAM,
            ($map['content-length'] ?? '') !== ''
         ])
         ->to->be([HTTP2::FLAG_END_STREAM, true])
         ->assert();

      // @ Connection still healthy after three streams
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'healthy!'));
      $pong = $Client->expect(HTTP2::FRAME_PING);
      yield new Assertion(
         description: 'Connection alive after GET/POST/HEAD streams',
      )
         ->expect($pong['payload'] ?? '')
         ->to->be('healthy!')
         ->assert();

      $Client->close();
   })
);
