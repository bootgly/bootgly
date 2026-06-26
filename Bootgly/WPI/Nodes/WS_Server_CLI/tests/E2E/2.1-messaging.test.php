<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E\Client;

require_once __DIR__ . '/Client.php';


return new Specification(
   description: 'It should echo messages, answer pings, reassemble fragments, and echo close',
   test: new Assertions(Case: function (): Generator {
      $Socket = Client::open();
      yield new Assertion(description: 'handshake established')
         ->expect($Socket !== false)
         ->to->be(true)
         ->assert();

      // @ Text message -> echo reply.
      fwrite($Socket, Client::mask(0x1, 'hello'));
      $reply = Client::read($Socket);
      yield new Assertion(description: 'text message returns a text frame')
         ->expect($reply['opcode'] ?? -1)
         ->to->be(0x1)
         ->assert();
      yield new Assertion(description: 'reply is the echo of the message')
         ->expect($reply['payload'] ?? '')
         ->to->be('echo: hello')
         ->assert();

      // @ Ping -> auto pong with the same payload.
      fwrite($Socket, Client::mask(0x9, 'pq'));
      $pong = Client::read($Socket);
      yield new Assertion(description: 'ping is answered with a pong')
         ->expect($pong['opcode'] ?? -1)
         ->to->be(0xA)
         ->assert();
      yield new Assertion(description: 'pong echoes the ping payload')
         ->expect($pong['payload'] ?? '')
         ->to->be('pq')
         ->assert();

      // @ Fragmented text ("Hel" + continuation "lo") -> echo of "Hello".
      fwrite($Socket, Client::mask(0x1, 'Hel', false, false));
      fwrite($Socket, Client::mask(0x0, 'lo', false, true));
      $fragmented = Client::read($Socket);
      yield new Assertion(description: 'fragmented message is reassembled and echoed')
         ->expect($fragmented['payload'] ?? '')
         ->to->be('echo: Hello')
         ->assert();

      // @ Close -> close echo.
      fwrite($Socket, Client::mask(0x8, pack('n', 1000)));
      $close = Client::read($Socket);
      yield new Assertion(description: 'close frame is echoed')
         ->expect($close['opcode'] ?? -1)
         ->to->be(0x8)
         ->assert();

      fclose($Socket);
   })
);
