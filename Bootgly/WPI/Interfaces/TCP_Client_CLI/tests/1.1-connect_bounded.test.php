<?php

use const STREAM_CLIENT_ASYNC_CONNECT;
use const STREAM_CLIENT_CONNECT;
use function microtime;
use function stream_socket_client;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections;


return new Specification(
   description: 'It should bound an async EVENT_CONNECT dial by the connect deadline instead of dialing forever',
   test: new Assertions(Case: function (): Generator {
      // ! 192.0.2.1 (RFC 5737 TEST-NET-1) never answers: the SYN either
      //   blackholes (socket never writable) or is rejected by a local ICMP
      //   error (dial resolves-but-fails inside the loop). Both paths must
      //   terminate the loop — the expire() deadline is the only guarantee.
      $Client = new class(TCP_Client_CLI::MODE_TEST) extends TCP_Client_CLI {
         /**
          * Expose the protected async-dial registration for the test.
          *
          * @param resource $Socket
          */
         public function open ($Socket): void
         {
            $this->await($Socket);
         }
      };
      $Client->configure(host: '192.0.2.1', port: 81);
      $Client->connectTimeout = 1;

      $Socket = stream_socket_client(
         'tcp://192.0.2.1:81',
         $errno,
         $errstr,
         timeout: 0,
         flags: STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
      );

      yield new Assertion(description: 'the async socket was created')
         ->expect($Socket !== false)
         ->to->be(true)
         ->assert();

      // @ Mimic the async-bind branch of connect(): register the dial + deadline
      $Client->Socket = $Socket;
      $Client->open($Socket);

      $started = microtime(true);
      TCP_Client_CLI::$Event->loop();
      $elapsed = microtime(true) - $started;

      yield new Assertion(description: 'the event loop terminated — the dial did not hang forever')
         ->expect($elapsed < 3.0)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'the loop ran until the dial deadline before halting')
         ->expect($elapsed >= 0.9)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'the in-flight dial accounting was released')
         ->expect($Client->dialing)
         ->to->be(0)
         ->assert();

      yield new Assertion(description: 'the failed dial was counted as a connection error')
         ->expect(Connections::$errors['connection'] >= 1)
         ->to->be(true)
         ->assert();
   })
);
