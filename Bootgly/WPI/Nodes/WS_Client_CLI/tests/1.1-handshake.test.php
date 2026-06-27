<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Client_CLI\Handshake;


return new Specification(
   description: 'It should generate a key, verify the accept hash, and parse the deflate response',
   test: new Assertions(Case: function (): Generator {
      // @ generate() — a fresh 16-byte base64 nonce.
      $key = Handshake::generate();
      yield new Assertion(description: 'generate() yields a 16-byte base64 nonce')
         ->expect(strlen((string) base64_decode($key, true)))
         ->to->be(16)
         ->assert();

      // @ accept() — RFC 6455 §1.3 vector (used to verify the server response).
      yield new Assertion(description: 'accept() matches the RFC 6455 vector')
         ->expect(Handshake::accept('dGhlIHNhbXBsZSBub25jZQ=='))
         ->to->be('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=')
         ->assert();

      // @ resolve() — parse the server's negotiated permessage-deflate params.
      $extensions = Handshake::resolve('permessage-deflate; server_no_context_takeover');
      yield new Assertion(description: 'resolve() accepts a permessage-deflate response')
         ->expect($extensions['permessage-deflate'] ?? false)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'resolve() honors server_no_context_takeover')
         ->expect($extensions['server_no_context_takeover'] ?? false)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'resolve() ignores a non-deflate response')
         ->expect(Handshake::resolve('x-custom-extension'))
         ->to->be([])
         ->assert();

      yield new Assertion(description: 'resolve() of an empty value is []')
         ->expect(Handshake::resolve(''))
         ->to->be([])
         ->assert();

      // @ offer() — the client request extension value.
      $offer = Handshake::offer();
      yield new Assertion(description: 'offer() advertises permessage-deflate')
         ->expect(str_contains($offer, 'permessage-deflate'))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'offer() advertises the client window capability')
         ->expect(str_contains($offer, 'client_max_window_bits'))
         ->to->be(true)
         ->assert();

      // @ offer() with explicit params — no-context-takeover + a bounded window.
      $custom = Handshake::offer([
         'client_no_context_takeover' => true,
         'client_max_window_bits' => 12,
      ]);
      yield new Assertion(description: 'offer() includes client_no_context_takeover when requested')
         ->expect(str_contains($custom, 'client_no_context_takeover'))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'offer() bounds the client window when given a value')
         ->expect(str_contains($custom, 'client_max_window_bits=12'))
         ->to->be(true)
         ->assert();

      // @ check() — case-insensitive comma-list token match (used to validate the
      //   response Upgrade / Connection headers).
      yield new Assertion(description: 'check() matches a token case-insensitively')
         ->expect(Handshake::check('WebSocket', 'websocket'))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'check() finds a token within a comma list')
         ->expect(Handshake::check('keep-alive, Upgrade', 'upgrade'))
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'check() rejects a missing token')
         ->expect(Handshake::check('keep-alive', 'upgrade'))
         ->to->be(false)
         ->assert();
   })
);
