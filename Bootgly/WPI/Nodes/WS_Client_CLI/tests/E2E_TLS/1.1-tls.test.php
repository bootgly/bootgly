<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;


return new Specification(
   description: 'It should connect over wss:// and round-trip a message',
   test: new Assertions(Case: function (): Generator {
      $connected = false;
      $received = null;
      $disconnected = false;

      $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
      $Client->configure(
         host: '127.0.0.1',
         port: 8087,
         secure: ['verify_peer' => false, 'verify_peer_name' => false]
      );
      $Client->on(Events::Connected, function ($Session) use (&$connected) {
         $connected = true;
         $Session->send('over tls 🔒');
      });
      $Client->on(Events::MessageReceived, function ($Session, $Message) use (&$received) {
         $received = $Message->payload;
         $Session->close();
      });
      $Client->on(Events::Disconnected, function ($Session) use (&$disconnected) {
         $disconnected = true;
      });
      $Client->connect('/');

      yield new Assertion(description: 'connected over wss://')
         ->expect($connected)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'echo matches over TLS (multibyte intact)')
         ->expect($received ?? '')
         ->to->be('over tls 🔒')
         ->assert();

      yield new Assertion(description: 'disconnected on close')
         ->expect($disconnected)
         ->to->be(true)
         ->assert();
   })
);
