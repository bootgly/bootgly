<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Server_CLI\Handshake;


return new Specification(
   description: 'It should validate the upgrade and compute the handshake response',
   test: new Assertions(Case: function (): Generator {
      // @ A valid lowercased header map from Frame::parse().
      $valid = [
         'host' => '127.0.0.1:8080',
         'upgrade' => 'websocket',
         'connection' => 'Upgrade',
         'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
         'sec-websocket-version' => '13',
      ];

      // @ Sec-WebSocket-Accept — RFC 6455 §1.3 vector.
      yield new Assertion(description: 'accept() matches the RFC 6455 vector')
         ->expect(Handshake::accept('dGhlIHNhbXBsZSBub25jZQ=='))
         ->to->be('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=')
         ->assert();

      // @ Validation — happy path.
      yield new Assertion(description: 'validate() accepts a well-formed upgrade')
         ->expect(Handshake::validate('GET', 'HTTP/1.1', $valid) === null)
         ->to->be(true)
         ->assert();

      // @ Validation — failures.
      yield new Assertion(description: 'validate() rejects a non-GET method with 400')
         ->expect(Handshake::validate('POST', 'HTTP/1.1', $valid))
         ->to->be(400)
         ->assert();

      yield new Assertion(description: 'validate() rejects a missing Upgrade header with 400')
         ->expect(Handshake::validate('GET', 'HTTP/1.1', ['host' => 'x', 'connection' => 'Upgrade', 'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==', 'sec-websocket-version' => '13']))
         ->to->be(400)
         ->assert();

      yield new Assertion(description: 'validate() rejects a bad key length with 400')
         ->expect(Handshake::validate('GET', 'HTTP/1.1', ['host' => 'x', 'upgrade' => 'websocket', 'connection' => 'Upgrade', 'sec-websocket-key' => 'c2hvcnQ=', 'sec-websocket-version' => '13']))
         ->to->be(400)
         ->assert();

      yield new Assertion(description: 'validate() rejects an unsupported version with 426')
         ->expect(Handshake::validate('GET', 'HTTP/1.1', ['host' => 'x', 'upgrade' => 'websocket', 'connection' => 'Upgrade', 'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==', 'sec-websocket-version' => '8']))
         ->to->be(426)
         ->assert();

      // @ Connection token is matched case-insensitively within a list.
      yield new Assertion(description: 'validate() accepts "keep-alive, Upgrade"')
         ->expect(Handshake::validate('GET', 'HTTP/1.1', ['host' => 'x', 'upgrade' => 'WebSocket', 'connection' => 'keep-alive, Upgrade', 'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==', 'sec-websocket-version' => '13']) === null)
         ->to->be(true)
         ->assert();

      // @ Subprotocol negotiation — first mutually supported.
      yield new Assertion(description: 'negotiate() picks the first supported subprotocol')
         ->expect(Handshake::negotiate('chat, superchat', ['superchat', 'chat']))
         ->to->be('superchat')
         ->assert();

      yield new Assertion(description: 'negotiate() returns "" when none match')
         ->expect(Handshake::negotiate('chat', ['superchat']))
         ->to->be('')
         ->assert();

      // @ 101 response building.
      $response = Handshake::build('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', 'chat');
      yield new Assertion(description: 'build() starts with the 101 status line')
         ->expect(str_starts_with($response, "HTTP/1.1 101 Switching Protocols\r\n"))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'build() includes the accept header')
         ->expect(str_contains($response, "Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n"))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'build() includes the negotiated subprotocol')
         ->expect(str_contains($response, "Sec-WebSocket-Protocol: chat\r\n"))
         ->to->be(true)
         ->assert();

      // @ permessage-deflate negotiation.
      $extensions = Handshake::resolve('permessage-deflate; client_no_context_takeover');
      yield new Assertion(description: 'extensions() accepts a permessage-deflate offer')
         ->expect($extensions['permessage-deflate'] ?? false)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'extensions() honors client_no_context_takeover')
         ->expect($extensions['client_no_context_takeover'] ?? false)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'extend() echoes the negotiated extension')
         ->expect(str_contains(Handshake::extend($extensions), 'permessage-deflate'))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'extensions() ignores a non-deflate offer')
         ->expect(Handshake::resolve('x-custom-extension'))
         ->to->be([])
         ->assert();

      // @ Rejection responses.
      yield new Assertion(description: 'deny(426) carries the version header')
         ->expect(str_contains(Handshake::deny(426), "Sec-WebSocket-Version: 13\r\n"))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'deny(401) is an Unauthorized response')
         ->expect(str_starts_with(Handshake::deny(401), 'HTTP/1.1 401'))
         ->to->be(true)
         ->assert();
   })
);
