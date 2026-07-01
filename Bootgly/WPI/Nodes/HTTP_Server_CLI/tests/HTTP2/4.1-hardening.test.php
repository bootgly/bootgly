<?php


use function pack;
use function str_repeat;
use function strlen;
use function substr;
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
   description: 'It should survive adversarial framing (splits, padding, floods, interleave, tiny windows)',
   test: new Assertions(Case: function (): Generator {
      $request = static fn (string $path, null|string $extra = null): string => HPACK::encode(
         $extra === null
            ? [
               [':method', 'GET'],
               [':scheme', 'http'],
               [':path', $path],
               [':authority', 'localhost:8085']
            ]
            : [
               [':method', 'POST'],
               [':scheme', 'http'],
               [':path', $path],
               [':authority', 'localhost:8085'],
               ['content-length', $extra]
            ]
      );

      // @ Frame split across two TCP writes (partial-frame carry)
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $frame = Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         1,
         $request('/split')
      );
      $Client->send(substr($frame, 0, 7));
      usleep(50000);
      $Client->send(substr($frame, 7));
      $data = $Client->expect(HTTP2::FRAME_DATA);
      yield new Assertion(
         description: 'Frame split mid-header across writes → still dispatched',
      )
         ->expect($data['payload'] ?? '')
         ->to->be('method=GET;uri=/split;protocol=HTTP/2;body=')
         ->assert();

      // @ Padded HEADERS + padded DATA (valid padding)
      $block = $request('/padded', '3');
      $padded = "\x04" . $block . "\x00\x00\x00\x00"; // 4 pad octets
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_PADDED,
         3,
         $padded
      ));
      $Client->send(Frame::pack(
         HTTP2::FRAME_DATA,
         HTTP2::FLAG_END_STREAM | HTTP2::FLAG_PADDED,
         3,
         "\x02abc\x00\x00"
      ));
      $data = $Client->expect(HTTP2::FRAME_DATA);
      yield new Assertion(
         description: 'Padded HEADERS + padded DATA → body decoded without padding',
      )
         ->expect($data['payload'] ?? '')
         ->to->be('method=POST;uri=/padded;protocol=HTTP/2;body=abc')
         ->assert();

      // @ Unknown frame type is ignored
      $Client->send(pack('NcN', (4 << 8) | 0xbb, 0, 0) . 'junk');
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'ignored!'));
      $pong = $Client->expect(HTTP2::FRAME_PING);
      yield new Assertion(
         description: 'Unknown frame type 0xbb skipped; connection healthy',
      )
         ->expect($pong['payload'] ?? '')
         ->to->be('ignored!')
         ->assert();
      $Client->close();

      // @ DATA padding larger than the payload → connection error
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 1, $request('/x', '10')));
      $Client->send(Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_PADDED, 1, "\x09abc"));
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'DATA pad length 9 on 4-octet payload → GOAWAY',
      )
         ->expect($goaway !== null)
         ->to->be(true)
         ->assert();
      $Client->close();

      // @ CONTINUATION interleave from another stream → connection error
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $block = $request('/frag');
      $Client->send(
         Frame::pack(HTTP2::FRAME_HEADERS, 0, 1, substr($block, 0, 4))
         . Frame::pack(HTTP2::FRAME_CONTINUATION, HTTP2::FLAG_END_HEADERS, 3, substr($block, 4))
      );
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'CONTINUATION for stream 3 inside stream 1 block → GOAWAY',
      )
         ->expect($goaway !== null)
         ->to->be(true)
         ->assert();
      $Client->close();

      // @ Fragmented header block (HEADERS + 2×CONTINUATION) decodes fine
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $block = $request('/fragmented');
      $third = (int) (strlen($block) / 3) + 1;
      $Client->send(
         Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_STREAM, 1, substr($block, 0, $third))
         . Frame::pack(HTTP2::FRAME_CONTINUATION, 0, 1, substr($block, $third, $third))
         . Frame::pack(HTTP2::FRAME_CONTINUATION, HTTP2::FLAG_END_HEADERS, 1, substr($block, 2 * $third))
      );
      $data = $Client->expect(HTTP2::FRAME_DATA);
      yield new Assertion(
         description: 'HEADERS + 2×CONTINUATION reassembled → dispatched',
      )
         ->expect($data['payload'] ?? '')
         ->to->be('method=GET;uri=/fragmented;protocol=HTTP/2;body=')
         ->assert();
      $Client->close();

      // @ Trailers after DATA complete the stream
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(
         Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 1, $request('/trailered', '5'))
         . Frame::pack(HTTP2::FRAME_DATA, 0, 1, 'hello')
         . Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            HPACK::encode([['x-checksum', 'abc123']])
         )
      );
      $data = $Client->expect(HTTP2::FRAME_DATA);
      yield new Assertion(
         description: 'Request with trailers → dispatched with full body',
      )
         ->expect($data['payload'] ?? '')
         ->to->be('method=POST;uri=/trailered;protocol=HTTP/2;body=hello')
         ->assert();
      $Client->close();

      // @ Tiny send window: response tail parks and drains via WINDOW_UPDATE
      $Client = new Client;
      // ! INITIAL_WINDOW_SIZE = 8 before any stream exists
      $Client->preface(pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, 8));
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         1,
         $request('/windowed')
      ));

      $first = $Client->expect(HTTP2::FRAME_DATA);
      yield new Assertion(
         description: '8-octet stream window → first DATA capped at 8 octets, no END_STREAM',
      )
         ->expect([strlen($first['payload'] ?? ''), ($first['flags'] ?? 0) & HTTP2::FLAG_END_STREAM])
         ->to->be([8, 0])
         ->assert();

      // @ Feed credit in 8-octet steps until the response completes
      $body = $first['payload'] ?? '';
      for ($i = 0; $i < 16; $i++) {
         $Client->send(Frame::pack(HTTP2::FRAME_WINDOW_UPDATE, 0, 1, pack('N', 8)));
         $data = $Client->expect(HTTP2::FRAME_DATA, 1.0);
         if ($data === null) {
            break;
         }
         $body .= $data['payload'];
         if ((($data['flags'] ?? 0) & HTTP2::FLAG_END_STREAM) !== 0) {
            break;
         }
      }
      yield new Assertion(
         description: 'WINDOW_UPDATE credit drains the parked tail to completion',
      )
         ->expect($body)
         ->to->be('method=GET;uri=/windowed;protocol=HTTP/2;body=')
         ->assert();
      $Client->close();

      // @ Rapid reset flood → GOAWAY(ENHANCE_YOUR_CALM)
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $burst = '';
      for ($stream = 1; $stream <= 141; $stream += 2) {
         $burst .= Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, $stream, $request('/flood', '1'))
            . Frame::pack(HTTP2::FRAME_RST_STREAM, 0, $stream, pack('N', Errors::Cancel->value));
      }
      $Client->send($burst);
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY, 3.0);
      $error = null;
      if (strlen($goaway['payload'] ?? '') >= 8) {
         /** @var array{last: int, error: int} $parts */
         $parts = unpack('Nlast/Nerror', $goaway['payload']);
         $error = $parts['error'];
      }
      yield new Assertion(
         description: '71 open+reset cycles → GOAWAY(ENHANCE_YOUR_CALM)',
      )
         ->expect($error)
         ->to->be(Errors::EnhanceYourCalm->value)
         ->assert();
      $Client->close();

      // @ Header block above the list cap → GOAWAY (compression/list guard)
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $huge = HPACK::encode([
         [':method', 'GET'],
         [':scheme', 'http'],
         [':path', '/'],
         [':authority', 'localhost:8085'],
         ['x-flood', str_repeat('a', 15000)],
         ['x-flood-2', str_repeat('b', 15000)]
      ]);
      $Client->send(Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, $huge));
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: '30KB decoded header list (cap 16KB) → GOAWAY',
      )
         ->expect($goaway !== null)
         ->to->be(true)
         ->assert();
      $Client->close();

      // @ Client GOAWAY with no open streams → server closes cleanly
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(Frame::pack(HTTP2::FRAME_GOAWAY, 0, 0, pack('NN', 0, 0)));
      yield new Assertion(
         description: 'Client GOAWAY (idle) → connection closed',
      )
         ->expect($Client->closed())
         ->to->be(true)
         ->assert();
      $Client->close();
   })
);
