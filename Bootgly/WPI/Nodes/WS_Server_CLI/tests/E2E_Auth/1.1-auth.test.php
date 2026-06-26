<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E\Client;

require_once __DIR__ . '/../E2E/Client.php';


return new Specification(
   description: 'It should authenticate the handshake with Bearer/Basic guards, propagate identity/claims, and challenge on denial',
   test: new Assertions(Case: function (): Generator {
      // @ Unauthenticated upgrade -> 401 with a WWW-Authenticate challenge.
      $unauth = Client::raw(
         "GET /a HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
         . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n"
      );
      yield new Assertion(description: 'unauthenticated upgrade returns 401')
         ->expect(str_starts_with($unauth, 'HTTP/1.1 401'))
         ->to->be(true)
         ->assert();
      yield new Assertion(description: '401 carries a WWW-Authenticate challenge')
         ->expect(str_contains($unauth, 'WWW-Authenticate: Bearer'))
         ->to->be(true)
         ->assert();

      // @ Wrong Bearer token -> rejected.
      yield new Assertion(description: 'wrong Bearer token is rejected')
         ->expect(Client::open('', 'Bearer wrong'))
         ->to->be(false)
         ->assert();

      // @ Valid Bearer -> upgrades; identity + claims reach the session.
      $bearer = Client::open('', 'Bearer tok');
      yield new Assertion(description: 'valid Bearer upgrades')
         ->expect($bearer !== false)
         ->to->be(true)
         ->assert();
      fwrite($bearer, Client::mask(0x1, 'who'));
      $reply = Client::read($bearer);
      yield new Assertion(description: 'Bearer identity + claims propagated to the session')
         ->expect($reply['payload'] ?? '')
         ->to->be('id=user-42;claims={"role":"admin"}')
         ->assert();
      fclose($bearer);

      // @ Valid Basic -> upgrades; identity reaches the session.
      $basic = Client::open('', 'Basic ' . base64_encode('alice:secret'));
      yield new Assertion(description: 'valid Basic upgrades')
         ->expect($basic !== false)
         ->to->be(true)
         ->assert();
      fwrite($basic, Client::mask(0x1, 'who'));
      $reply = Client::read($basic);
      yield new Assertion(description: 'Basic identity propagated to the session')
         ->expect($reply['payload'] ?? '')
         ->to->be('id=alice;claims=null')
         ->assert();
      fclose($basic);
   })
);
