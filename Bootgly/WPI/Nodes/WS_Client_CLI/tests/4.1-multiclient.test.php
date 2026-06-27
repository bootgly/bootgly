<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;


return new Specification(
   description: 'It should keep callbacks and policy instance-scoped so multiple clients never clobber each other',
   test: new Assertions(Case: function (): Generator {
      // @ Two independent clients, configured + handled with DIFFERENT policy/handlers.
      //   Order matters: A is configured first, B second — a process-wide static would
      //   leak B's values back into A.
      $ClientA = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
      $ClientB = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);

      $ClientA->configure(host: '127.0.0.1', port: 1, heartbeatInterval: 5, maxFrameSize: 1000, maxMessageSize: 100000);
      $ClientB->configure(host: '127.0.0.1', port: 2, heartbeatInterval: 9, maxFrameSize: 2000, maxMessageSize: 200000);

      $cbA = function () { return 'A'; };
      $cbB = function () { return 'B'; };
      $ClientA->on(Events::MessageReceived, $cbA);
      $ClientB->on(Events::MessageReceived, $cbB);

      // ? Policy is per-instance — A keeps 1000 though B set 2000 afterwards.
      yield new Assertion(description: 'client A keeps its own maxFrameSize')
         ->expect($ClientA->maxFrameSize)
         ->to->be(1000)
         ->assert();
      yield new Assertion(description: 'client B keeps its own maxFrameSize')
         ->expect($ClientB->maxFrameSize)
         ->to->be(2000)
         ->assert();
      yield new Assertion(description: 'client A keeps its own heartbeatInterval')
         ->expect($ClientA->heartbeatInterval)
         ->to->be(5)
         ->assert();
      yield new Assertion(description: 'client B keeps its own heartbeatInterval')
         ->expect($ClientB->heartbeatInterval)
         ->to->be(9)
         ->assert();

      // ? Handlers are per-instance — each client holds exactly the closure it was given.
      yield new Assertion(description: 'client A holds its own MessageReceived handler')
         ->expect($ClientA->onMessageReceived === $cbA)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'client B holds its own MessageReceived handler')
         ->expect($ClientB->onMessageReceived === $cbB)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'the two clients do not share a handler')
         ->expect($ClientA->onMessageReceived === $ClientB->onMessageReceived)
         ->to->be(false)
         ->assert();

      // ? Teardown is callable and clears the session slot.
      $ClientA->reset();
      yield new Assertion(description: 'reset() clears the session slot')
         ->expect($ClientA->Session === null)
         ->to->be(true)
         ->assert();
   })
);
