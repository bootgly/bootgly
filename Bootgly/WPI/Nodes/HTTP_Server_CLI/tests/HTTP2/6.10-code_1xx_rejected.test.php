<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should never let a 1xx code terminate an HTTP/2 exchange',
   test: new Assertions(Case: function (): Generator {
      // @ Preface + GET /code-1xx on stream 1 — the handler calls code(103)
      //   and sends a body; the guard must keep the final status non-1xx
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/code-1xx'],
            [':authority', 'localhost:8085']
         ]))
      );

      $headers = $Client->expect(HTTP2::FRAME_HEADERS, 5.0);

      // ! Decode the block: `:status` must be the untouched final 200
      $fields = [];
      if ($headers !== null) {
         $HPACK = new HPACK(4096);
         foreach ($HPACK->decode($headers['payload'], PHP_INT_MAX) ?? [] as [$name, $value]) {
            $fields[$name] = $value;
         }
      }

      yield new Assertion(
         description: 'Final HEADERS carries :status 200, never the interim 103',
      )
         ->expect(
            $headers !== null
            && $headers['stream'] === 1
            && ($fields[':status'] ?? '') === '200'
         )
         ->to->be(true)
         ->assert();

      // @ DATA: the body terminates the stream normally
      $data = $Client->expect(HTTP2::FRAME_DATA, 5.0);

      yield new Assertion(
         description: 'DATA carries the body with END_STREAM',
      )
         ->expect(
            $data !== null
            && $data['stream'] === 1
            && $data['payload'] === 'final'
            && ($data['flags'] & HTTP2::FLAG_END_STREAM) !== 0
         )
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
