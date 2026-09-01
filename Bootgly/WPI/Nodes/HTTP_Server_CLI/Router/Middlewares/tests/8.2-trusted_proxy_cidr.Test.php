<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;


return new Test(
   description: 'It should trust proxies by CIDR range',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };

      // @ Test 1: Peer inside an IPv4 CIDR range is trusted
      //   172.64.0.0/13 covers 172.64.0.0–172.71.255.255 (a Cloudflare range);
      //   the peer is a real edge address seen in production.
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.50'],
         requestProps: ['address' => '172.71.10.116', 'peer' => '172.71.10.116']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['172.64.0.0/13']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Peer inside an IPv4 CIDR range should be trusted',
      )
         ->expect($Request->address)
         ->to->be('203.0.113.50')
         ->assert();

      // @ Test 2: Peer outside the range stays untrusted
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.50'],
         requestProps: ['address' => '172.72.0.1', 'peer' => '172.72.0.1']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['172.64.0.0/13']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Peer outside the CIDR range should not rewrite address',
      )
         ->expect($Request->address)
         ->to->be('172.72.0.1')
         ->assert();

      // @ Test 3: X-Forwarded-For chain hops are skipped by RANGE too
      //   XFF: `<client>, <edge-2>` — both edges belong to the same CIDR
      //   range; the walk must skip the in-chain edge by prefix match, not by
      //   literal equality, to land on the real client.
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.50, 172.69.39.10'],
         requestProps: ['address' => '172.71.10.116', 'peer' => '172.71.10.116']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['172.64.0.0/13']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Chain walk should skip trusted hops by CIDR match',
      )
         ->expect($Request->address)
         ->to->be('203.0.113.50')
         ->assert();

      // @ Test 4: IPv6 CIDR range
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Real-IP' => '198.51.100.10'],
         requestProps: ['address' => '2400:cb00:12:34::5', 'peer' => '2400:cb00:12:34::5']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['2400:cb00::/32']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Peer inside an IPv6 CIDR range should be trusted',
      )
         ->expect($Request->address)
         ->to->be('198.51.100.10')
         ->assert();

      // @ Test 5: IP literals keep working — and textual IPv6 variants now
      //   normalize (binary comparison replaced literal string equality)
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.99'],
         requestProps: ['address' => '::1', 'peer' => '::1']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['0:0:0:0:0:0:0:1']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'IPv6 literal in expanded form should match the compressed peer',
      )
         ->expect($Request->address)
         ->to->be('203.0.113.99')
         ->assert();

      // @ Test 6: IPv4-mapped IPv6 peer (dual-stack listener) matches an IPv4
      //   range through its embedded address
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.50'],
         requestProps: ['address' => '::ffff:10.0.0.2', 'peer' => '::ffff:10.0.0.2']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['10.0.0.0/8']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'IPv4-mapped IPv6 peer should match an IPv4 CIDR range',
      )
         ->expect($Request->address)
         ->to->be('203.0.113.50')
         ->assert();

      // @ Test 7: /0 trusts any peer of the family (explicit operator choice).
      //   Proven via X-Real-IP: under /0 every X-Forwarded-For hop is a
      //   trusted proxy too, so the chain walk correctly yields no client.
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Real-IP' => '203.0.113.50'],
         requestProps: ['address' => '198.18.0.1', 'peer' => '198.18.0.1']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['0.0.0.0/0']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'A /0 range should trust any IPv4 peer',
      )
         ->expect($Request->address)
         ->to->be('203.0.113.50')
         ->assert();

      // @ Test 8: Malformed entries fail at construction — including `/00`
      //   (would compile to `/0` and trust the whole family) and a NUL byte
      //   (`inet_pton()` raises a ValueError instead of returning false)
      $invalids = [
         'not-an-ip',
         '10.0.0.0/33',
         '10.0.0.0/',
         '2400:cb00::/129',
         '10.0.0.0/00',
         '10.0.0.0/032',
         "10.0.0.0\x00/8",
      ];
      $rejected = 0;
      foreach ($invalids as $invalid) {
         try {
            new TrustedProxy(proxies: [$invalid]);
         }
         catch (InvalidArgumentException) {
            $rejected++;
         }
      }
      yield new Assertion(
         description: 'Malformed trust-list entries should throw InvalidArgumentException at boot',
      )
         ->expect($rejected)
         ->to->be(7)
         ->assert();

      // @ Test 9: A NUL byte in a chain candidate is screened before
      //   `inet_pton()`, which raises a ValueError instead of returning false
      //   — the walk must end without a rewrite, never with a fatal
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => "203.0.113.9, 10.0.0\x00.5"],
         requestProps: ['address' => '10.0.0.1', 'peer' => '10.0.0.1']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['10.0.0.0/8']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'A NUL-bearing chain candidate should be rejected without raising',
      )
         ->expect($Request->address)
         ->to->be('10.0.0.1')
         ->assert();

      // @ Test 10: Cross-family candidates never match — PHP's string `&`
      //   truncates to the shorter operand, so without the family-length guard
      //   an IPv6 peer whose FIRST 4 bytes equal an IPv4 network (`ac40::1`
      //   vs 172.64.0.0/13) would be trusted. That is the direction the guard
      //   defends; the converse (IPv4 peer vs IPv6 range, asserted right
      //   after) is safe by construction — a 4-byte AND result can never
      //   equal a 16-byte network — and is pinned so a future refactor that
      //   pads networks cannot open it.
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.50'],
         requestProps: ['address' => 'ac40::1', 'peer' => 'ac40::1']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['172.64.0.0/13']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'IPv6 peer should never match an IPv4 range (family guard)',
      )
         ->expect($Request->address)
         ->to->be('ac40::1')
         ->assert();

      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.50'],
         requestProps: ['address' => '10.0.0.5', 'peer' => '10.0.0.5']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['::/0']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'IPv4 peer should never match an IPv6 range (family guard)',
      )
         ->expect($Request->address)
         ->to->be('10.0.0.5')
         ->assert();

      // @ Test 11: A non-canonical entry (host bits set, `10.0.0.8/8`) trusts
      //   its whole network — the stored network must be masked at compile
      //   time, or the range silently trusts nobody.
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.50'],
         requestProps: ['address' => '10.1.2.3', 'peer' => '10.1.2.3']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['10.0.0.8/8']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'A CIDR entry with host bits set should trust its whole network',
      )
         ->expect($Request->address)
         ->to->be('203.0.113.50')
         ->assert();

      // @ Test 12: IPv4-mapped ENTRIES unwrap to their embedded IPv4 — the
      //   socket peer arrives canonicalized to the dotted quad (Peer::parse),
      //   so `::ffff:10.0.0.2` in the list must trust a `10.0.0.2` peer.
      //   Entries also tolerate surrounding whitespace (file-loaded lists).
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.50'],
         requestProps: ['address' => '10.0.0.2', 'peer' => '10.0.0.2']
      );
      $TrustedProxy = new TrustedProxy(proxies: ["::ffff:10.0.0.2", " 192.0.2.1\n"]);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'An IPv4-mapped entry should trust the canonicalized IPv4 peer',
      )
         ->expect($Request->address)
         ->to->be('203.0.113.50')
         ->assert();

      // @ Test 13: An IPv4-mapped RANGE (`::ffff:10.0.0.0/104` = 10.0.0.0/8)
      //   rebases its prefix on unwrap
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Forwarded-For' => '203.0.113.50'],
         requestProps: ['address' => '10.9.9.9', 'peer' => '10.9.9.9']
      );
      $TrustedProxy = new TrustedProxy(proxies: ['::ffff:10.0.0.0/104']);
      $TrustedProxy->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'An IPv4-mapped range should rebase to its embedded IPv4 network',
      )
         ->expect($Request->address)
         ->to->be('203.0.113.50')
         ->assert();
   })
);
