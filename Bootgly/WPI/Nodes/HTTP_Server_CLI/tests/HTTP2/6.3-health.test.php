<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should serve the built-in health endpoint over HTTP/2',
   test: new Assertions(Case: function (): Generator {
      // @ Preface + GET /health on stream 1
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/health'],
            [':authority', 'localhost:8085']
         ]))
      );

      // @ The health guard answers through the h2 serializer
      $headers = $Client->expect(HTTP2::FRAME_HEADERS);
      $data = $Client->expect(HTTP2::FRAME_DATA);

      // ! Decode the response header block (context-free on the server side)
      $fields = [];
      if ($headers !== null) {
         $HPACK = new HPACK(4096);
         foreach ($HPACK->decode($headers['payload'], PHP_INT_MAX) ?? [] as [$name, $value]) {
            $fields[$name] = $value;
         }
      }

      yield new Assertion(
         description: 'HEADERS carries :status 200 + no-store + application/json',
      )
         ->expect(
            $headers !== null && $headers['stream'] === 1
            && ($fields[':status'] ?? null) === '200'
            && ($fields['cache-control'] ?? null) === 'no-store'
            && ($fields['content-type'] ?? null) === 'application/json'
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'DATA carries the minimal probe body with END_STREAM',
      )
         ->expect(
            $data !== null
            && $data['stream'] === 1
            && $data['payload'] === '{"status":"ok"}'
            && ($data['flags'] & HTTP2::FLAG_END_STREAM) !== 0
         )
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
