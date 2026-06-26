<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E\Client;

require_once __DIR__ . '/../E2E/Client.php';


return new Specification(
   description: 'It should run the HandshakeRequested predicate — an allowlisted Origin upgrades, others are rejected with 403',
   test: new Assertions(Case: function (): Generator {
      // @ Allowlisted Origin -> upgrades.
      $allowed = Client::open('', '', 'http://allowed');
      yield new Assertion(description: 'allowlisted Origin upgrades')
         ->expect($allowed !== false)
         ->to->be(true)
         ->assert();
      if ($allowed !== false) {
         fclose($allowed);
      }

      // @ Disallowed Origin -> rejected.
      yield new Assertion(description: 'disallowed Origin is rejected')
         ->expect(Client::open('', '', 'http://evil'))
         ->to->be(false)
         ->assert();

      // @ Missing Origin -> rejected.
      yield new Assertion(description: 'missing Origin is rejected')
         ->expect(Client::open())
         ->to->be(false)
         ->assert();

      // @ Rejection carries HTTP 403.
      $rejected = Client::raw(
         "GET /x HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
         . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nOrigin: http://evil\r\n\r\n"
      );
      yield new Assertion(description: 'rejection carries HTTP 403')
         ->expect(str_starts_with($rejected, 'HTTP/1.1 403'))
         ->to->be(true)
         ->assert();
   })
);
