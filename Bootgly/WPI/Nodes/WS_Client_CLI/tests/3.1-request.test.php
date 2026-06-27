<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Client_CLI;


return new Specification(
   description: 'It should reject CR/LF injection and invalid tokens when building the upgrade request',
   test: new Assertions(Case: function (): Generator {
      // @ build() runs inside connect() before the dial, so bad input throws
      //   locally (InvalidArgumentException) without opening a socket.
      $rejects = function (callable $attempt): bool {
         try {
            $attempt();

            return false;
         }
         catch (InvalidArgumentException) {
            return true;
         }
      };
      $client = fn (): WS_Client_CLI => new WS_Client_CLI(WS_Client_CLI::MODE_TEST)
         ->configure(host: '127.0.0.1', port: 9);

      yield new Assertion(description: 'a CR/LF in the URI is rejected')
         ->expect($rejects(fn () => $client()->connect("/x\r\nX-Injected: 1")))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'a space in the URI is rejected')
         ->expect($rejects(fn () => $client()->connect('/a b')))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'a CR/LF in a header value is rejected')
         ->expect($rejects(fn () => $client()->connect('/', ['X-Foo' => "bar\r\nX-Injected: 1"])))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'an invalid header name is rejected')
         ->expect($rejects(fn () => $client()->connect('/', ['Bad Name' => 'v'])))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'an invalid subprotocol token is rejected')
         ->expect($rejects(fn () => $client()->connect('/', [], ['bad proto'])))
         ->to->be(true)
         ->assert();
   })
);
