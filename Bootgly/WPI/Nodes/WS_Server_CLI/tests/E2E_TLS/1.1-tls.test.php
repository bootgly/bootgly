<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E\Client;

require_once __DIR__ . '/../E2E/Client.php';


return new Specification(
   description: 'It should complete a wss:// (TLS) handshake and echo a message over TLS',
   test: new Assertions(Case: function (): Generator {
      $Socket = Client::open();
      yield new Assertion(description: 'TLS handshake established (101 over wss)')
         ->expect($Socket !== false)
         ->to->be(true)
         ->assert();

      fwrite($Socket, Client::mask(0x1, 'tls-hello'));
      $reply = Client::read($Socket);
      yield new Assertion(description: 'message is echoed back over the TLS connection')
         ->expect($reply['payload'] ?? '')
         ->to->be('echo: tls-hello')
         ->assert();

      fclose($Socket);
   })
);
