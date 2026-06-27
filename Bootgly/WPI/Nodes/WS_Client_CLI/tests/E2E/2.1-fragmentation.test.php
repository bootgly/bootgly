<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;


return new Specification(
   description: 'It should send a fragmented message the server reassembles and echoes',
   test: new Assertions(Case: function (): Generator {
      $received = null;
      $payload = str_repeat('fragment-', 12);   // 108 bytes

      // @ MODE_TEST skips the Process/state-lock setup (the live server already
      //   holds the project lock in this same process).
      $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
      $Client->configure(host: '127.0.0.1', port: 8085);
      $Client->on(Events::Connected, function ($Session) use ($payload) {
         // @ Split the (post-compression) payload into <=16-byte frames: a lead
         //   text frame (FIN=0) followed by continuation frames.
         $Session->send($payload, false, fragment: 16);
      });
      $Client->on(Events::MessageReceived, function ($Session, $Message) use (&$received) {
         $received = $Message->payload;
         $Session->close();
      });
      $Client->connect('/');

      yield new Assertion(description: 'a fragmented send is reassembled + echoed intact')
         ->expect($received ?? '')
         ->to->be($payload)
         ->assert();
   })
);
