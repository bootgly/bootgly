<?php

use function microtime;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;


return new Specification(
   description: 'It should give up reconnecting to a dead port once the wall-clock budget expires (unlimited attempts)',
   test: new Assertions(Case: function (): Generator {
      // ! A dead port: nothing listens on 8199, so every dial is refused (RST). With
      //   reconnectAttempts:0 (unlimited) the ONLY thing that can end the loop is the
      //   wall-clock budget — if connect() returns, the reconnectTimeout guard worked.
      $disconnected = 0;

      $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
      $Client->configure(
         host: '127.0.0.1',
         port: 8199,
         compression: false,
         reconnect: true,
         reconnectAttempts: 0,   // unlimited — only the budget below can stop the loop
         reconnectDelay: 1,
         reconnectTimeout: 2,    // total campaign budget (seconds)
         handshakeTimeout: 1
      );
      $Client->on(Events::Disconnected, function ($Session) use (&$disconnected) {
         $disconnected++;
      });

      $started = microtime(true);
      $Socket = $Client->connect('/');
      $elapsed = microtime(true) - $started;

      yield new Assertion(description: 'connect() returned — the reconnect loop did not hang')
         ->expect($elapsed < 10.0)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'the campaign ran at least its budget before giving up')
         ->expect($elapsed >= 2.0)
         ->to->be(true)
         ->assert();

      // ! A refused dial never establishes a connection, so it never opens a
      //   Session: connect() reports the failure by returning false, and no
      //   Disconnected hook fires for a peer that was never connected.
      yield new Assertion(description: 'the campaign gave up and reported the failure to the caller')
         ->expect($Socket)
         ->to->be(false)
         ->assert();

      yield new Assertion(description: 'a dial that never connected fires no Disconnected hook')
         ->expect($disconnected)
         ->to->be(0)
         ->assert();
   })
);
