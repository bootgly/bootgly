<?php


use function strlen;
use function substr;
use function unpack;
use function usleep;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should enforce RFC 9113 connection-setup rules (split preface, mandatory SETTINGS, GOAWAY shape)',
   test: new Assertions(Case: function (): Generator {
      $code = static function (null|array $frame): null|int {
         $payload = $frame['payload'] ?? '';
         if (strlen($payload) < 8) {
            return null;
         }
         /** @var array{last: int, error: int} $parts */
         $parts = unpack('Nlast/Nerror', $payload);
         return $parts['error'];
      };

      // @ Preface split across TCP writes (byte 10 boundary) — the probe
      //   installs the h2 decoder on the prefix; the decoder completes the
      //   preface from the second write.
      $Client = new Client;
      $Client->send(substr(HTTP2::PREFACE, 0, 10));
      usleep(60000);
      $Client->send(substr(HTTP2::PREFACE, 10) . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0));
      $settings = $Client->expect(HTTP2::FRAME_SETTINGS);
      yield new Assertion(
         description: 'Split preface (10 + 14 bytes) → connection still negotiates h2',
      )
         ->expect($settings['type'] ?? null)
         ->to->be(HTTP2::FRAME_SETTINGS)
         ->assert();

      // @ ...and the split connection serves a request end-to-end
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         1,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/split-preface'],
            [':authority', 'localhost:8085']
         ])
      ));
      $data = $Client->expect(HTTP2::FRAME_DATA);
      yield new Assertion(
         description: 'Request over the split-preface connection is dispatched',
      )
         ->expect($data['payload'] ?? '')
         ->to->be('method=GET;uri=/split-preface;protocol=HTTP/2;body=')
         ->assert();
      $Client->close();

      // @ The preface must be followed by SETTINGS (RFC 9113 §3.4)
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_PING, 0, 0, '12345678')
      );
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'PING before the initial SETTINGS → GOAWAY(PROTOCOL_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::Protocol->value)
         ->assert();
      $Client->close();

      // @ HEADERS before the initial SETTINGS → PROTOCOL_ERROR
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'localhost:8085']
         ]))
      );
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'HEADERS before the initial SETTINGS → GOAWAY(PROTOCOL_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::Protocol->value)
         ->assert();
      $Client->close();

      // @ GOAWAY payload below 8 octets → FRAME_SIZE_ERROR (RFC 9113 §6.8)
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(Frame::pack(HTTP2::FRAME_GOAWAY, 0, 0, pack('N', 0)));
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: '4-octet GOAWAY → GOAWAY(FRAME_SIZE_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::FrameSize->value)
         ->assert();
      $Client->close();

      // @ SETTINGS ACK as the very first frame also violates §3.4
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, HTTP2::FLAG_ACK, 0)
      );
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'SETTINGS ACK before the initial SETTINGS → GOAWAY(PROTOCOL_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::Protocol->value)
         ->assert();
      $Client->close();
   })
);
