<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E\Client;

require_once __DIR__ . '/Client.php';


return new Specification(
   description: 'It should complete a valid handshake and reject malformed upgrades',
   test: new Assertions(Case: function (): Generator {
      // @ Valid upgrade -> 101 + the RFC 6455 §1.3 accept vector.
      $valid = "GET /e2e HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
         . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n";
      $response = Client::raw($valid);

      yield new Assertion(description: 'valid upgrade returns 101')
         ->expect(str_starts_with($response, 'HTTP/1.1 101'))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: '101 carries the correct Sec-WebSocket-Accept')
         ->expect(str_contains($response, 'Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo='))
         ->to->be(true)
         ->assert();

      // @ Missing Upgrade header -> 400.
      $missing = "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\n"
         . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n";
      yield new Assertion(description: 'missing Upgrade returns 400')
         ->expect(str_starts_with(Client::raw($missing), 'HTTP/1.1 400'))
         ->to->be(true)
         ->assert();

      // @ Unsupported version -> 426.
      $version = "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
         . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 8\r\n\r\n";
      yield new Assertion(description: 'unsupported version returns 426')
         ->expect(str_starts_with(Client::raw($version), 'HTTP/1.1 426'))
         ->to->be(true)
         ->assert();
   })
);
