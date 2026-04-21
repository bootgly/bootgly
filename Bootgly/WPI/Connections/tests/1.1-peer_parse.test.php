<?php

use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Connections\Peer;


return new Specification(
   description: 'It should parse stream_socket_get_name() peer strings into [ip, port]',
   test: new Assertions(Case: function (): Generator {
      // @ IPv4 — canonical form from stream_socket_get_name()
      yield new Assertion(
         description: 'IPv4 "127.0.0.1:8080" → ["127.0.0.1", 8080]',
      )
         ->expect(Peer::parse('127.0.0.1:8080'))
         ->to->be(['127.0.0.1', 8080])
         ->assert();

      yield new Assertion(
         description: 'IPv4 with high ephemeral port',
      )
         ->expect(Peer::parse('192.168.1.42:54321'))
         ->to->be(['192.168.1.42', 54321])
         ->assert();

      // @ IPv6 — bracketed form; brackets must be unwrapped so the IP
      //   matches the canonical unbracketed literal used by TrustedProxy,
      //   RateLimit, blacklist, and logs. Regression guard for the original
      //   `explode(':', $peer, 2)` bug which yielded ["[::1", 0].
      yield new Assertion(
         description: 'IPv6 loopback "[::1]:8080" → ["::1", 8080] (brackets unwrapped)',
      )
         ->expect(Peer::parse('[::1]:8080'))
         ->to->be(['::1', 8080])
         ->assert();

      yield new Assertion(
         description: 'IPv6 full address "[2001:db8::1]:443" → ["2001:db8::1", 443]',
      )
         ->expect(Peer::parse('[2001:db8::1]:443'))
         ->to->be(['2001:db8::1', 443])
         ->assert();

      yield new Assertion(
         description: 'IPv6 with many segments',
      )
         ->expect(Peer::parse('[2001:db8:85a3:8d3:1319:8a2e:370:7348]:65535'))
         ->to->be(['2001:db8:85a3:8d3:1319:8a2e:370:7348', 65535])
         ->assert();

      yield new Assertion(
         description: 'IPv6 unspecified "[::]:80"',
      )
         ->expect(Peer::parse('[::]:80'))
         ->to->be(['::', 80])
         ->assert();

      // @ IPv6 — IPv4-mapped (RFC 4291 §2.5.5.2)
      yield new Assertion(
         description: 'IPv6 IPv4-mapped "[::ffff:192.0.2.1]:8080"',
      )
         ->expect(Peer::parse('[::ffff:192.0.2.1]:8080'))
         ->to->be(['::ffff:192.0.2.1', 8080])
         ->assert();

      // @ Malformed — no separator at all
      yield new Assertion(
         description: 'Missing port → port is 0',
      )
         ->expect(Peer::parse('127.0.0.1'))
         ->to->be(['127.0.0.1', 0])
         ->assert();

      // @ Malformed — bracket opens but never closes
      yield new Assertion(
         description: 'Unterminated IPv6 bracket → passthrough, port is 0',
      )
         ->expect(Peer::parse('[::1'))
         ->to->be(['[::1', 0])
         ->assert();

      // @ Empty peer — should not blow up
      yield new Assertion(
         description: 'Empty peer string → ["", 0]',
      )
         ->expect(Peer::parse(''))
         ->to->be(['', 0])
         ->assert();

      // @ Regression — the exact symptom that the original bug produced.
      //   Previous `explode(':', $peer, 2)` on "[::1]:8080" returned
      //   ["[::1", "8080]" → (int)0]. Ensure the parser NEVER produces
      //   a bracketed IP or a zero port for a well-formed IPv6 peer.
      $result = Peer::parse('[::1]:8080');
      yield new Assertion(
         description: 'Regression: IPv6 IP must not retain the leading "["',
      )
         ->expect($result[0][0] ?? '')
         ->not->to->be('[')
         ->assert();

      yield new Assertion(
         description: 'Regression: IPv6 port must not be 0 for "[::1]:8080"',
      )
         ->expect($result[1])
         ->not->to->be(0)
         ->assert();
   })
);
