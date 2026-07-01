<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\Settings;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should answer the connection preface with server SETTINGS, ACK client SETTINGS and PONG pings',
   test: new Assertions(Case: function (): Generator {
      $Client = new Client;
      $Client->preface();

      // @ Server SETTINGS lead the connection
      $frame = $Client->expect(HTTP2::FRAME_SETTINGS);
      yield new Assertion(
         description: 'Server sends SETTINGS after the preface',
      )
         ->expect($frame['type'] ?? null)
         ->to->be(HTTP2::FRAME_SETTINGS)
         ->assert();

      $Settings = new Settings;
      $Settings->parse($frame['payload'] ?? '');
      yield new Assertion(
         description: 'Server advertises MAX_CONCURRENT_STREAMS=128 + MAX_HEADER_LIST_SIZE=16384',
      )
         ->expect([$Settings->streams, $Settings->list])
         ->to->be([128, 16384])
         ->assert();

      // @ Our SETTINGS get acknowledged
      $ack = $Client->expect(HTTP2::FRAME_SETTINGS);
      yield new Assertion(
         description: 'Client SETTINGS are ACKed (flags & ACK, empty payload)',
      )
         ->expect([$ack['flags'] ?? 0, $ack['payload'] ?? null])
         ->to->be([HTTP2::FLAG_ACK, ''])
         ->assert();

      // @ PING → PONG with the same opaque payload
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'bootgly!'));
      $pong = $Client->expect(HTTP2::FRAME_PING);
      yield new Assertion(
         description: 'PING is answered with ACK + identical 8-byte payload',
      )
         ->expect([$pong['flags'] ?? 0, $pong['payload'] ?? ''])
         ->to->be([HTTP2::FLAG_ACK, 'bootgly!'])
         ->assert();

      // @ Connection stays open across control frames
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, '12345678'));
      $pong = $Client->expect(HTTP2::FRAME_PING);
      yield new Assertion(
         description: 'Second PING still answered (connection alive)',
      )
         ->expect($pong['payload'] ?? '')
         ->to->be('12345678')
         ->assert();

      $Client->close();

      // @ PING ACK from the client must NOT be echoed back
      $Client = new Client;
      $Client->preface();
      $Client->expect(HTTP2::FRAME_SETTINGS);
      $Client->send(Frame::pack(HTTP2::FRAME_PING, HTTP2::FLAG_ACK, 0, 'ackping!'));
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'realping'));
      $pong = $Client->expect(HTTP2::FRAME_PING);
      yield new Assertion(
         description: 'PING ACK is ignored; only the real PING is answered',
      )
         ->expect($pong['payload'] ?? '')
         ->to->be('realping')
         ->assert();

      $Client->close();
   })
);
