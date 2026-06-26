<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E\Client;

require_once __DIR__ . '/Client.php';


return new Specification(
   description: 'It should broadcast a message to other members of a channel',
   test: new Assertions(Case: function (): Generator {
      // Two clients; both auto-join the lobby on connect.
      $A = Client::open();
      $B = Client::open();
      yield new Assertion(description: 'two clients connected')
         ->expect($A !== false && $B !== false)
         ->to->be(true)
         ->assert();

      usleep(100000);

      // @ A sends -> A gets the echo, B gets the broadcast payload.
      $message = 'room-msg';
      fwrite($A, Client::mask(0x1, $message));

      $echo = Client::read($A);
      yield new Assertion(description: 'sender receives its echo')
         ->expect($echo['payload'] ?? '')
         ->to->be("echo: {$message}")
         ->assert();

      $broadcast = Client::read($B);
      yield new Assertion(description: 'other member receives the broadcast')
         ->expect($broadcast['payload'] ?? '')
         ->to->be($message)
         ->assert();

      fclose($A);
      fclose($B);
   })
);
