<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should reply GOAWAY + close on malformed input (bad preface, oversized frame, client PUSH_PROMISE)',
   test: new Assertions(Case: function (): Generator {
      // @ Helper: last 4 bytes of NN payload = error code
      $code = static function (null|array $frame): null|int {
         $payload = $frame['payload'] ?? '';
         if (strlen($payload) < 8) {
            return null;
         }
         /** @var array{last: int, error: int} $parts */
         $parts = unpack('Nlast/Nerror', $payload);
         return $parts['error'];
      };

      // @ Oversized frame (declared length > 16384) → FRAME_SIZE_ERROR
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      // ! Hand-craft a header declaring 16385 payload bytes
      $Client->send(pack('NcN', (16385 << 8) | HTTP2::FRAME_PING, 0, 0));
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'Oversized frame → GOAWAY(FRAME_SIZE_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::FrameSize->value)
         ->assert();
      yield new Assertion(
         description: '...and the connection is closed',
      )
         ->expect($Client->closed())
         ->to->be(true)
         ->assert();
      $Client->close();

      // @ Invalid preface on an h2c connection: handled by the HTTP/1.1
      //   parser (405/400), not by the h2 decoder — send a PRI-like but
      //   broken preface and expect the connection to just close.
      $Client = new Client;
      $Client->send("PRI * HTTP/2.0\r\n\r\nXX\r\n\r\n");
      yield new Assertion(
         description: 'Corrupted preface tail → rejected (connection closed)',
      )
         ->expect($Client->closed())
         ->to->be(true)
         ->assert();
      $Client->close();

      // @ PUSH_PROMISE from the client → PROTOCOL_ERROR
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(Frame::pack(HTTP2::FRAME_PUSH_PROMISE, HTTP2::FLAG_END_HEADERS, 1, pack('N', 2)));
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'Client PUSH_PROMISE → GOAWAY(PROTOCOL_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::Protocol->value)
         ->assert();
      $Client->close();

      // @ CONTINUATION without a header block in progress → PROTOCOL_ERROR
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(Frame::pack(HTTP2::FRAME_CONTINUATION, HTTP2::FLAG_END_HEADERS, 1, ''));
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'Stray CONTINUATION → GOAWAY(PROTOCOL_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::Protocol->value)
         ->assert();
      $Client->close();
   })
);
