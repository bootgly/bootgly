<?php

use function fclose;
use function microtime;
use function stream_socket_server;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;


return new Specification(
   description: 'It should give up when the peer accepts TCP but never answers the upgrade (handshake timeout)',
   test: new Assertions(Case: function (): Generator {
      // ! A mute peer: the listen backlog completes the TCP handshake, but no
      //   accept()/reply ever happens — the upgrade GET is never answered.
      $Mute = stream_socket_server('tcp://127.0.0.1:8097', $errno, $errstr);

      yield new Assertion(description: 'mute listener bound')
         ->expect($Mute !== false)
         ->to->be(true)
         ->assert();

      $connected = false;
      $disconnected = false;

      $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
      $Client->configure(
         host: '127.0.0.1',
         port: 8097,
         compression: false,
         handshakeTimeout: 1
      );
      $Client->on(Events::Connected, function ($Session) use (&$connected) {
         $connected = true;
      });
      $Client->on(Events::Disconnected, function ($Session) use (&$disconnected) {
         $disconnected = true;
      });

      $started = microtime(true);
      $Client->connect('/');
      $elapsed = microtime(true) - $started;

      fclose($Mute);

      yield new Assertion(description: 'connect() returned before the 5s guard')
         ->expect($elapsed < 5.0)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'the session was never established')
         ->expect($connected)
         ->to->be(false)
         ->assert();

      yield new Assertion(description: 'the client tore the connection down')
         ->expect($disconnected)
         ->to->be(true)
         ->assert();
   })
);
