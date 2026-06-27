<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;


return new Specification(
   description: 'It should connect, round-trip text + binary (permessage-deflate), and close',
   test: new Assertions(Case: function (): Generator {
      $connected = false;
      $deflate = false;
      $received = [];
      $binary = [];
      $disconnected = false;
      $binPayload = pack('N', 0xCAFEBABE) . "\x00\xFF\x10";

      // @ MODE_TEST skips the Process/state-lock setup (the live server already
      //   holds the project lock in this same process).
      $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
      $Client->configure(host: '127.0.0.1', port: 8085);
      $Client->on(Events::Connected, function ($Session) use (&$connected, &$deflate) {
         $connected = true;
         $deflate = $Session->Deflator !== null;
         $Session->send('hello 你好 🌍');
      });
      $Client->on(Events::MessageReceived, function ($Session, $Message) use (&$received, &$binary, $binPayload) {
         $received[] = $Message->payload;
         $binary[] = $Message->binary;
         if (count($received) === 1) {
            $Session->send($binPayload, true);
         }
         else {
            $Session->close();
         }
      });
      $Client->on(Events::Disconnected, function ($Session) use (&$disconnected) {
         $disconnected = true;
      });
      $Client->connect('/');

      yield new Assertion(description: 'fired Connected on the 101')
         ->expect($connected)->to->be(true)->assert();

      yield new Assertion(description: 'negotiated permessage-deflate')
         ->expect($deflate)->to->be(true)->assert();

      yield new Assertion(description: 'received both echoes')
         ->expect(count($received))->to->be(2)->assert();

      yield new Assertion(description: 'text echo matches (multibyte intact)')
         ->expect($received[0] ?? '')->to->be('hello 你好 🌍')->assert();

      yield new Assertion(description: 'the text echo is not flagged binary')
         ->expect($binary[0] ?? true)->to->be(false)->assert();

      yield new Assertion(description: 'binary echo matches byte-for-byte')
         ->expect($received[1] ?? '')->to->be($binPayload)->assert();

      yield new Assertion(description: 'the binary echo is flagged binary')
         ->expect($binary[1] ?? false)->to->be(true)->assert();

      yield new Assertion(description: 'fired Disconnected on close')
         ->expect($disconnected)->to->be(true)->assert();
   })
);
