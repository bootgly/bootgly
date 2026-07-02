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
   description: 'It should validate HTTP/2 request semantics (RFC 9113 §8) with stream-level errors',
   test: new Assertions(Case: function (): Generator {
      $status = static function (null|array $frame): string {
         $pairs = (new HPACK)->decode($frame['payload'] ?? '', PHP_INT_MAX) ?? [];
         foreach ($pairs as [$name, $value]) {
            if ($name === ':status') {
               return $value;
            }
         }
         return '';
      };
      $code = static function (null|array $frame): null|int {
         $payload = $frame['payload'] ?? '';
         if (strlen($payload) < 4) {
            return null;
         }
         /** @var array{1: int} $parts */
         $parts = unpack('N', $payload);
         return $parts[1];
      };

      // @ Malformed requests are STREAM errors — RST_STREAM(PROTOCOL_ERROR),
      //   not a canned response (RFC 9113 §8.1.2 / §8.2.2; h2spec compliance).

      // @ Forbidden connection-specific field → RST(PROTOCOL_ERROR)
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
            [':authority', 'localhost:8085'],
            ['connection', 'keep-alive']
         ])
      ));
      $rst = $Client->expect(HTTP2::FRAME_RST_STREAM);
      yield new Assertion(
         description: 'connection header → RST_STREAM(PROTOCOL_ERROR)',
      )
         ->expect([$rst['stream'] ?? 0, $code($rst)])
         ->to->be([1, Errors::Protocol->value])
         ->assert();

      // @ Uppercase field name → RST(PROTOCOL_ERROR)
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         3,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'localhost:8085'],
            ['X-Custom', 'value']
         ])
      ));
      $rst = $Client->expect(HTTP2::FRAME_RST_STREAM);
      yield new Assertion(
         description: 'Uppercase field name → RST_STREAM(PROTOCOL_ERROR)',
      )
         ->expect([$rst['stream'] ?? 0, $code($rst)])
         ->to->be([3, Errors::Protocol->value])
         ->assert();

      // @ Missing :path → RST(PROTOCOL_ERROR)
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         5,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':authority', 'localhost:8085']
         ])
      ));
      $rst = $Client->expect(HTTP2::FRAME_RST_STREAM);
      yield new Assertion(
         description: 'Missing :path → RST_STREAM(PROTOCOL_ERROR)',
      )
         ->expect([$rst['stream'] ?? 0, $code($rst)])
         ->to->be([5, Errors::Protocol->value])
         ->assert();

      // @ te: gzip → RST(PROTOCOL_ERROR) (only "trailers" allowed)
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         7,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'localhost:8085'],
            ['te', 'gzip']
         ])
      ));
      $rst = $Client->expect(HTTP2::FRAME_RST_STREAM);
      yield new Assertion(
         description: 'te: gzip → RST_STREAM(PROTOCOL_ERROR)',
      )
         ->expect([$rst['stream'] ?? 0, $code($rst)])
         ->to->be([7, Errors::Protocol->value])
         ->assert();

      // @ content-length mismatch → RST_STREAM(PROTOCOL_ERROR)
      $Client->send(
         Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 9, HPACK::encode([
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'localhost:8085'],
            ['content-length', '10']
         ]))
         . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 9, 'short')
      );
      $rst = $Client->expect(HTTP2::FRAME_RST_STREAM);
      yield new Assertion(
         description: 'content-length 10 with 5-byte body → RST_STREAM(PROTOCOL_ERROR)',
      )
         ->expect([$rst['stream'] ?? 0, $code($rst)])
         ->to->be([9, Errors::Protocol->value])
         ->assert();

      // @ Declared content-length above the body cap → 413 without body upload
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS,
         11,
         HPACK::encode([
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'localhost:8085'],
            ['content-length', '20971520']
         ])
      ));
      $headers = $Client->expect(HTTP2::FRAME_HEADERS);
      yield new Assertion(
         description: 'content-length 20MB (over the 10MB cap) → canned :status 413',
      )
         ->expect($status($headers))
         ->to->be('413')
         ->assert();

      // @ TRACE mirrors HTTP/1.1 policy → 501 before routing
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         13,
         HPACK::encode([
            [':method', 'TRACE'],
            [':scheme', 'http'],
            [':path', '/trace'],
            [':authority', 'localhost:8085']
         ])
      ));
      $headers = $Client->expect(HTTP2::FRAME_HEADERS);
      yield new Assertion(
         description: 'TRACE over HTTP/2 → canned :status 501',
      )
         ->expect($status($headers))
         ->to->be('501')
         ->assert();

      // @ CONNECT mirrors HTTP/1.1 unsupported-method policy → 501
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         15,
         HPACK::encode([
            [':method', 'CONNECT'],
            [':scheme', 'http'],
            [':path', '/connect'],
            [':authority', 'localhost:8085']
         ])
      ));
      $headers = $Client->expect(HTTP2::FRAME_HEADERS);
      yield new Assertion(
         description: 'CONNECT over HTTP/2 → canned :status 501',
      )
         ->expect($status($headers))
         ->to->be('501')
         ->assert();

      // @ Unknown extension methods mirror HTTP/1.1 policy → 405
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         17,
         HPACK::encode([
            [':method', 'BREW'],
            [':scheme', 'http'],
            [':path', '/brew'],
            [':authority', 'localhost:8085']
         ])
      ));
      $headers = $Client->expect(HTTP2::FRAME_HEADERS);
      yield new Assertion(
         description: 'Unknown method over HTTP/2 → canned :status 405',
      )
         ->expect($status($headers))
         ->to->be('405')
         ->assert();

      // @ HPACK-decoded field names remain HTTP tokens — CR is invalid
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         19,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'localhost:8085'],
            ["x\rbad", 'value']
         ])
      ));
      $rst = $Client->expect(HTTP2::FRAME_RST_STREAM);
      yield new Assertion(
         description: 'CTL in HTTP/2 field name → RST_STREAM(PROTOCOL_ERROR)',
      )
         ->expect([$rst['stream'] ?? 0, $code($rst)])
         ->to->be([19, Errors::Protocol->value])
         ->assert();

      // @ HPACK-decoded field values must not inject raw header lines
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         21,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'localhost:8085'],
            ['x-safe', "ok\r\nx-evil: yes"]
         ])
      ));
      $rst = $Client->expect(HTTP2::FRAME_RST_STREAM);
      yield new Assertion(
         description: 'CRLF in HTTP/2 field value → RST_STREAM(PROTOCOL_ERROR)',
      )
         ->expect([$rst['stream'] ?? 0, $code($rst)])
         ->to->be([21, Errors::Protocol->value])
         ->assert();

      // @ Pseudo-header values are validated before Request::adopt()
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         23,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', "/ok\nx: y"],
            [':authority', 'localhost:8085']
         ])
      ));
      $rst = $Client->expect(HTTP2::FRAME_RST_STREAM);
      yield new Assertion(
         description: 'LF in HTTP/2 :path → RST_STREAM(PROTOCOL_ERROR)',
      )
         ->expect([$rst['stream'] ?? 0, $code($rst)])
         ->to->be([23, Errors::Protocol->value])
         ->assert();

      // @ NUL in :authority cannot reach host/allowlist handling
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         25,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', "local\0host:8085"]
         ])
      ));
      $rst = $Client->expect(HTTP2::FRAME_RST_STREAM);
      yield new Assertion(
         description: 'NUL in HTTP/2 :authority → RST_STREAM(PROTOCOL_ERROR)',
      )
         ->expect([$rst['stream'] ?? 0, $code($rst)])
         ->to->be([25, Errors::Protocol->value])
         ->assert();

      // @ HTTP/2 only accepts schemes served by this HTTP server
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         27,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'ftp'],
            [':path', '/invalid-scheme'],
            [':authority', 'localhost:8085']
         ])
      ));
      $rst = $Client->expect(HTTP2::FRAME_RST_STREAM);
      yield new Assertion(
         description: 'Invalid HTTP/2 :scheme → RST_STREAM(PROTOCOL_ERROR)',
      )
         ->expect([$rst['stream'] ?? 0, $code($rst)])
         ->to->be([27, Errors::Protocol->value])
         ->assert();

      // @ HTTP/2 :path follows the same 8192-byte target cap as HTTP/1.1
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         29,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/' . str_repeat('a', 8192)],
            [':authority', 'localhost:8085']
         ])
      ));
      $headers = $Client->expect(HTTP2::FRAME_HEADERS);
      yield new Assertion(
         description: 'Oversized HTTP/2 :path → canned :status 414',
      )
         ->expect($status($headers))
         ->to->be('414')
         ->assert();

      // @ Stream errors above never killed the connection
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'aliveyet'));
      $pong = $Client->expect(HTTP2::FRAME_PING);
      yield new Assertion(
         description: 'Connection survives all stream-level rejections',
      )
         ->expect($pong['payload'] ?? '')
         ->to->be('aliveyet')
         ->assert();

      // @ Non-monotonic stream id → connection error PROTOCOL_ERROR
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         3,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'localhost:8085']
         ])
      ));
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'Reused (lower) stream id → GOAWAY',
      )
         ->expect(($goaway['type'] ?? 0) === HTTP2::FRAME_GOAWAY)
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
