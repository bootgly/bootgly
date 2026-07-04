<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;


return new Specification(
   description: 'It should drive multiple clients concurrently on one shared loop, each routed to its own session',
   test: new Assertions(Case: function (): Generator {
      $received = ['A' => null, 'B' => null];

      // @ Build a client that greets with its tag and records exactly the echo it gets.
      $make = function (string $tag) use (&$received): WS_Client_CLI {
         $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
         $Client->configure(host: '127.0.0.1', port: 8094, compression: false);
         $Client->on(Events::Connected, function ($Session) use ($tag) {
            $Session->send("hello-{$tag}");
         });
         $Client->on(Events::MessageReceived, function ($Session, $Message) use (&$received, $tag) {
            $received[$tag] = $Message->payload;
            $Session->close();
         });
         return $Client;
      };

      // @ Construct ALL clients, THEN open each (the shared loop is the last-constructed),
      //   THEN run once. Both connections live on one loop simultaneously.
      $ClientA = $make('A');
      $ClientB = $make('B');
      $ClientA->open('/');
      $ClientB->open('/');
      WS_Client_CLI::run();

      // ? Each client received ONLY its own echo — proves per-connection routing
      //   (a shared/static dispatch would cross-fire B's echo into A or vice-versa).
      yield new Assertion(description: 'client A received exactly its own echo')
         ->expect($received['A'])
         ->to->be('hello-A')
         ->assert();
      yield new Assertion(description: 'client B received exactly its own echo')
         ->expect($received['B'])
         ->to->be('hello-B')
         ->assert();
   })
);
