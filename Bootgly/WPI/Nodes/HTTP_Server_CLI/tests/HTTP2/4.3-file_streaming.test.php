<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should stream large Response::upload() bodies over HTTP/2 without materializing them',
   test: new Assertions(Case: function (): Generator {
      $decode = static function (null|array $frame): array {
         $pairs = (new HPACK)->decode($frame['payload'] ?? '', PHP_INT_MAX) ?? [];
         $map = [];
         foreach ($pairs as [$name, $value]) {
            $map[$name] = $value;
         }
         return $map;
      };

      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);

      // @ Real clients (Chrome/Firefox/nghttp2) pre-expand the CONNECTION
      //   window at startup and then replenish only the STREAM window while
      //   consuming — parked file tails must resume on stream credit alone.
      $Client->send(Frame::pack(
         HTTP2::FRAME_WINDOW_UPDATE, 0, 0, pack('N', 2147418112)
      ));

      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         1,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/h2-s4-large'],
            [':authority', 'localhost:8085']
         ])
      ));

      $headers = $Client->expect(HTTP2::FRAME_HEADERS, 5.0);
      $map = $decode($headers);
      $expected = 17 * 1024 * 1024;

      yield new Assertion(
         description: 'Large Response::upload() over HTTP/2 keeps :status 200',
      )
         ->expect($map[':status'] ?? '')
         ->to->be('200')
         ->assert();

      yield new Assertion(
         description: 'Large Response::upload() over HTTP/2 advertises the full content-length',
      )
         ->expect($map['content-length'] ?? '')
         ->to->be((string) $expected)
         ->assert();

      $received = 0;
      $closed = false;
      while ($received < $expected) {
         $frame = $Client->expect(HTTP2::FRAME_DATA, 5.0);
         if ($frame === null) {
            break;
         }

         $chunk = strlen($frame['payload'] ?? '');
         $received += $chunk;
         $closed = (($frame['flags'] ?? 0) & HTTP2::FLAG_END_STREAM) !== 0;

         if ($chunk > 0) {
            $Client->send(
               Frame::pack(HTTP2::FRAME_WINDOW_UPDATE, 0, 1, pack('N', $chunk))
            );
         }
      }

      yield new Assertion(
         description: 'Large Response::upload() over HTTP/2 streams the complete file',
      )
         ->expect([$received, $closed])
         ->to->be([$expected, true])
         ->assert();

      $Client->close();
   })
);
