<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy\Strategies;


return new Test(
   description: 'It should resolve the client address by strategy and relay, failing closed on spoofed or malformed values',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };
      /**
       * Run one request through a TrustedProxy and return the resolved address.
       *
       * @param array<string,string> $headers
       */
      $resolve = function (TrustedProxy $TrustedProxy, array $headers, string $peer = '127.0.0.1') use ($createMocks, $passthrough): string {
         [$Request, $Response] = $createMocks(
            requestHeaders: $headers,
            requestProps: ['address' => $peer, 'peer' => $peer]
         );
         $TrustedProxy->process($Request, $Response, $passthrough);

         return $Request->address;
      };
      // ! Snapshot the route-cache registry: constructing with `header`/`relay`
      //   registers fields worker-wide, and other specs share this process
      $registry = Cache::$forwarded;
      $CF = ['127.0.0.1', '173.245.48.0/20', '2400:cb00::/32'];

      // ! Restored even when the run stops this generator early
      try {

         // # Config
         $Default = new TrustedProxy(proxies: ['127.0.0.1']);
         yield new Assertion(
            description: 'Default instance keeps the right-most strategy with no header and no relay',
         )
            ->expect([$Default->strategy, $Default->header, $Default->relay])
            ->to->be([Strategies::Rightmost, null, null])
            ->assert();

         // # Rightmost + relay
         $Rightmost = new TrustedProxy(proxies: $CF, relay: 'CF-Connecting-IP');
         yield new Assertion(
            description: 'Rightmost: a chain of trusted hops only hands over to the relay',
         )
            ->expect($resolve($Rightmost, [
               'X-Forwarded-For' => '173.245.48.5',
               'CF-Connecting-IP' => '203.0.113.9',
            ]))
            ->to->be('203.0.113.9')
            ->assert();

         yield new Assertion(
            description: 'Rightmost: an untrusted hop is the client — the relay is not read',
         )
            ->expect($resolve($Rightmost, [
               'X-Forwarded-For' => '198.51.100.7, 173.245.48.5',
               'CF-Connecting-IP' => '203.0.113.9',
            ]))
            ->to->be('198.51.100.7')
            ->assert();

         yield new Assertion(
            description: 'Relay is never read on the socket-peer fallback (no forwarded data, client-written relay header)',
         )
            ->expect($resolve($Rightmost, [
               'CF-Connecting-IP' => '203.0.113.66',
            ]))
            ->to->be('127.0.0.1')
            ->assert();

         yield new Assertion(
            description: 'Relay is not read past an invalid untrusted hop (fail closed)',
         )
            ->expect($resolve($Rightmost, [
               'X-Forwarded-For' => 'garbage, 173.245.48.5',
               'CF-Connecting-IP' => '203.0.113.66',
            ]))
            ->to->be('127.0.0.1')
            ->assert();

         yield new Assertion(
            description: 'Rightmost: a trusted X-Real-IP hands over to the relay',
         )
            ->expect($resolve($Rightmost, [
               'X-Real-IP' => '173.245.48.5',
               'CF-Connecting-IP' => '203.0.113.9',
            ]))
            ->to->be('203.0.113.9')
            ->assert();

         yield new Assertion(
            description: 'Rightmost: an IPv4-mapped trusted hop hands over to the relay',
         )
            ->expect($resolve($Rightmost, [
               'X-Forwarded-For' => '::ffff:173.245.48.5',
               'CF-Connecting-IP' => '203.0.113.9',
            ]))
            ->to->be('203.0.113.9')
            ->assert();

         $internal = $resolve($Rightmost, [
            'X-Forwarded-For' => '173.245.48.5',
            'CF-Connecting-IP' => '173.245.48.9',
         ]);
         $loopback = $resolve($Rightmost, [
            'X-Forwarded-For' => '173.245.48.5',
            'CF-Connecting-IP' => '127.0.0.1',
         ]);
         yield new Assertion(
            description: 'A relay visitor inside the trust list is refused — a front proxy names the client, never another proxy',
         )
            ->expect([$internal, $loopback])
            ->to->be(['127.0.0.1', '127.0.0.1'])
            ->assert();

         $missing = $resolve($Rightmost, ['X-Forwarded-For' => '173.245.48.5']);
         $joined = $resolve($Rightmost, [
            'X-Forwarded-For' => '173.245.48.5',
            'CF-Connecting-IP' => '203.0.113.9, 198.51.100.1',
         ]);
         $garbage = $resolve($Rightmost, [
            'X-Forwarded-For' => '173.245.48.5',
            'CF-Connecting-IP' => "203.0.\x00113.9",
         ]);
         yield new Assertion(
            description: 'A missing, repeated or malformed relay value never replaces the address',
         )
            ->expect([$missing, $joined, $garbage])
            ->to->be(['127.0.0.1', '127.0.0.1', '127.0.0.1'])
            ->assert();

         yield new Assertion(
            description: 'Without a relay, a chain of trusted hops leaves the address alone (default parity)',
         )
            ->expect($resolve(new TrustedProxy(proxies: $CF), [
               'X-Forwarded-For' => '173.245.48.5',
               'CF-Connecting-IP' => '203.0.113.9',
            ]))
            ->to->be('127.0.0.1')
            ->assert();

         // # Leftmost
         $Leftmost = new TrustedProxy(proxies: ['127.0.0.1'], strategy: Strategies::Leftmost);
         yield new Assertion(
            description: 'Leftmost: the left-most X-Forwarded-For entry is the client',
         )
            ->expect($resolve($Leftmost, ['X-Forwarded-For' => ' 203.0.113.5 , 10.1.1.1, 10.2.2.2']))
            ->to->be('203.0.113.5')
            ->assert();

         $skipped = $resolve($Leftmost, ['X-Forwarded-For' => 'garbage, 203.0.113.5']);
         $empty = $resolve($Leftmost, ['X-Forwarded-For' => ', 203.0.113.5']);
         yield new Assertion(
            description: 'Leftmost: an invalid or empty first entry is never skipped to reach a later one',
         )
            ->expect([$skipped, $empty])
            ->to->be(['127.0.0.1', '127.0.0.1'])
            ->assert();

         yield new Assertion(
            description: 'Leftmost: no X-Real-IP fallback without X-Forwarded-For',
         )
            ->expect($resolve($Leftmost, ['X-Real-IP' => '203.0.113.5']))
            ->to->be('127.0.0.1')
            ->assert();

         yield new Assertion(
            description: 'Leftmost: an untrusted peer is never rewritten (forged X-Forwarded-For)',
         )
            ->expect($resolve($Leftmost, ['X-Forwarded-For' => '203.0.113.5'], peer: '198.51.100.20'))
            ->to->be('198.51.100.20')
            ->assert();

         // ! Operator precondition, pinned: behind an edge that APPENDS to a
         //   client-written X-Forwarded-For, Leftmost returns the forged entry
         //   while Rightmost resolves the real client from the same header
         $appended = ['X-Forwarded-For' => '6.6.6.6, 198.51.100.7'];
         yield new Assertion(
            description: 'Leftmost is spoofable behind an appending edge; Rightmost is not (documented precondition)',
         )
            ->expect([
               $resolve($Leftmost, $appended),
               $resolve(new TrustedProxy(proxies: ['127.0.0.1']), $appended),
            ])
            ->to->be(['6.6.6.6', '198.51.100.7'])
            ->assert();

         $LeftmostRelay = new TrustedProxy(proxies: $CF, strategy: Strategies::Leftmost, relay: 'CF-Connecting-IP');
         $relayed4 = $resolve($LeftmostRelay, [
            'X-Forwarded-For' => '173.245.48.5, 10.0.0.2',
            'CF-Connecting-IP' => '203.0.113.9',
         ]);
         $relayed6 = $resolve($LeftmostRelay, [
            'X-Forwarded-For' => '2400:cb00:1::5',
            'CF-Connecting-IP' => '2001:db8::9',
         ]);
         $direct = $resolve($LeftmostRelay, [
            'X-Forwarded-For' => '198.51.100.7',
            'CF-Connecting-IP' => '203.0.113.66',
         ]);
         yield new Assertion(
            description: 'Leftmost + relay: a trusted front proxy (IPv4 or IPv6) hands over to its visitor header; a direct visitor does not',
         )
            ->expect([$relayed4, $relayed6, $direct])
            ->to->be(['203.0.113.9', '2001:db8::9', '198.51.100.7'])
            ->assert();

         // # Header
         $RealIP = new TrustedProxy(proxies: ['127.0.0.1'], strategy: Strategies::Header, header: 'X-Real-IP');
         yield new Assertion(
            description: 'Header: an X-Real-IP-only edge resolves its own header, ignoring a client-written X-Forwarded-For',
         )
            ->expect($resolve($RealIP, [
               'X-Forwarded-For' => '10.0.0.1',
               'X-Real-IP' => '203.0.113.77',
            ]))
            ->to->be('203.0.113.77')
            ->assert();

         yield new Assertion(
            description: 'Header: a header from an untrusted peer is never read',
         )
            ->expect($resolve($RealIP, ['X-Real-IP' => '203.0.113.77'], peer: '198.51.100.20'))
            ->to->be('198.51.100.20')
            ->assert();

         $repeated = $resolve($RealIP, ['X-Real-IP' => '203.0.113.77, 10.0.0.1']);
         $invalid = $resolve($RealIP, ['X-Real-IP' => 'not-an-ip']);
         $absent = $resolve($RealIP, ['X-Forwarded-For' => '203.0.113.77']);
         yield new Assertion(
            description: 'Header: a repeated, malformed or missing header never replaces the address',
         )
            ->expect([$repeated, $invalid, $absent])
            ->to->be(['127.0.0.1', '127.0.0.1', '127.0.0.1'])
            ->assert();

         $Connecting = new TrustedProxy(
            proxies: $CF,
            strategy: Strategies::Header,
            header: 'X-Real-IP',
            relay: 'CF-Connecting-IP'
         );
         yield new Assertion(
            description: 'Header + relay: a trusted front proxy in the header hands over to the relay',
         )
            ->expect($resolve($Connecting, [
               'X-Real-IP' => '173.245.48.5',
               'CF-Connecting-IP' => '2001:db8::9',
            ]))
            ->to->be('2001:db8::9')
            ->assert();

         [$Request, $Response] = $createMocks(
            requestHeaders: ['X-Real-IP' => '203.0.113.77', 'X-Forwarded-Proto' => 'https'],
            requestProps: ['address' => '127.0.0.1', 'peer' => '127.0.0.1', 'scheme' => 'http']
         );
         $RealIP->process($Request, $Response, $passthrough);
         $schemes = [$Request->scheme];
         foreach ([$Leftmost, new TrustedProxy(proxies: ['127.0.0.1'])] as $Strategy) {
            [$Request, $Response] = $createMocks(
               requestHeaders: ['X-Forwarded-For' => '203.0.113.77', 'X-Forwarded-Proto' => 'https'],
               requestProps: ['address' => '127.0.0.1', 'peer' => '127.0.0.1', 'scheme' => 'http']
            );
            $Strategy->process($Request, $Response, $passthrough);
            $schemes[] = $Request->scheme;
         }
         yield new Assertion(
            description: 'X-Forwarded-Proto is honoured under every strategy (Header, Leftmost, Rightmost)',
         )
            ->expect($schemes)
            ->to->be(['https', 'https', 'https'])
            ->assert();

         // # Constructor rejections
         $configs = [
            ['strategy' => Strategies::Header],
            ['strategy' => Strategies::Rightmost, 'header' => 'X-Real-IP'],
            ['strategy' => Strategies::Leftmost, 'header' => 'X-Real-IP'],
            ['strategy' => Strategies::Header, 'header' => ''],
            ['strategy' => Strategies::Header, 'header' => 'X Real'],
            ['strategy' => Strategies::Header, 'header' => "X-Real\r\nIP"],
            ['strategy' => Strategies::Header, 'header' => "X-Real-IP\n"],
            ['strategy' => Strategies::Header, 'header' => "X-Real-IP\x00"],
            ['strategy' => Strategies::Header, 'header' => 'X:Real'],
            ['strategy' => Strategies::Header, 'header' => 'X-Réal'],
            ['relay' => ''],
            ['relay' => "CF-Connecting-IP\r\nX: y"],
            ['relay' => 'CF Connecting'],
         ];
         $rejected = 0;
         foreach ($configs as $config) {
            try {
               new TrustedProxy(['127.0.0.1'], ...$config);
            }
            catch (InvalidArgumentException) {
               $rejected++;
            }
         }
         yield new Assertion(
            description: 'Invalid strategy/header/relay combinations fail at construction',
         )
            ->expect($rejected)
            ->to->be(count($configs))
            ->assert();

         // # Route-cache registry
         Cache::$forwarded = [];
         new TrustedProxy(['127.0.0.1'], Strategies::Header, 'CF-Connecting-IP', 'True-Client-IP');
         yield new Assertion(
            description: 'A configured header and relay join the route-cache forwarding fields (lowercase)',
         )
            ->expect(Cache::$forwarded)
            ->to->be(['cf-connecting-ip' => true, 'true-client-ip' => true])
            ->assert();

         // ! Real Requests for Cache::compose()
         $URIProperty = new ReflectionProperty(Request::class, 'URI');
         /** @param array<string,string> $fields */
         $compose = static function (array $fields) use ($URIProperty): string {
            $Request = new Request;
            $Request->method = 'GET';
            $Request->protocol = 'HTTP/1.1';
            $URIProperty->setValue($Request, '/resource');
            $Request->Header->adopt(['host' => 'localhost'] + $fields);

            return Cache::compose($Request);
         };

         $visitor1 = $compose(['cf-connecting-ip' => '203.0.113.1']);
         $visitor2 = $compose(['cf-connecting-ip' => '203.0.113.2']);
         $plain = $compose([]);
         Cache::$forwarded = [];
         $unregistered1 = $compose(['cf-connecting-ip' => '203.0.113.1']);
         $unregistered2 = $compose(['cf-connecting-ip' => '203.0.113.2']);
         $unregisteredPlain = $compose([]);
         yield new Assertion(
            description: 'A registered field is framed into the route-cache key; an empty registry keeps the built-in key shape',
         )
            ->expect([
               $visitor1 !== $visitor2,
               $visitor1 !== $plain,
               $unregistered1 === $unregistered2,
               $unregisteredPlain === $unregistered1,
               str_contains($plain, 'FS16:cf-connecting-ipN'),
               str_contains($unregisteredPlain, 'FS'),
            ])
            ->to->be([true, true, true, true, true, false])
            ->assert();

         // ? A numeric token is a valid field name and arrives as an int key
         new TrustedProxy(['127.0.0.1'], relay: '123');
         yield new Assertion(
            description: 'A numeric field name registers and frames like any other',
         )
            ->expect([
               Cache::$forwarded,
               $compose(['123' => '203.0.113.1']) !== $compose([]),
            ])
            ->to->be([[123 => true], true])
            ->assert();
      }
      finally {
         Cache::$forwarded = $registry;
      }
   })
);
