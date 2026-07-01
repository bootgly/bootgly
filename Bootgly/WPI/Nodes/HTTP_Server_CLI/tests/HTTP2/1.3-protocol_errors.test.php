<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should enforce SETTINGS/WINDOW_UPDATE/PING validity with the right error codes',
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

      // @ SETTINGS with ENABLE_PUSH=2 → PROTOCOL_ERROR
      $Client = new Client;
      $Client->preface(pack('nN', HTTP2::SETTINGS_ENABLE_PUSH, 2));
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'ENABLE_PUSH=2 → GOAWAY(PROTOCOL_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::Protocol->value)
         ->assert();
      $Client->close();

      // @ SETTINGS with a truncated pair → FRAME_SIZE_ERROR
      $Client = new Client;
      $Client->preface("\x00\x04\x00");
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'SETTINGS length % 6 != 0 → GOAWAY(FRAME_SIZE_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::FrameSize->value)
         ->assert();
      $Client->close();

      // @ Connection-level WINDOW_UPDATE with zero increment → PROTOCOL_ERROR
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(Frame::pack(HTTP2::FRAME_WINDOW_UPDATE, 0, 0, pack('N', 0)));
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'Zero WINDOW_UPDATE on stream 0 → GOAWAY(PROTOCOL_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::Protocol->value)
         ->assert();
      $Client->close();

      // @ PING with wrong payload size → FRAME_SIZE_ERROR
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'short'));
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'PING with 5-byte payload → GOAWAY(FRAME_SIZE_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::FrameSize->value)
         ->assert();
      $Client->close();

      // @ SETTINGS on a non-zero stream → PROTOCOL_ERROR
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 1, ''));
      $goaway = $Client->expect(HTTP2::FRAME_GOAWAY);
      yield new Assertion(
         description: 'SETTINGS on stream 1 → GOAWAY(PROTOCOL_ERROR)',
      )
         ->expect($code($goaway))
         ->to->be(Errors::Protocol->value)
         ->assert();
      $Client->close();

      // @ WINDOW_UPDATE on stream 0 stays fine with a real increment
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(Frame::pack(HTTP2::FRAME_WINDOW_UPDATE, 0, 0, pack('N', 65535)));
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'stillok!'));
      $pong = $Client->expect(HTTP2::FRAME_PING);
      yield new Assertion(
         description: 'Valid WINDOW_UPDATE keeps the connection healthy',
      )
         ->expect($pong['payload'] ?? '')
         ->to->be('stillok!')
         ->assert();
      $Client->close();
   })
);
