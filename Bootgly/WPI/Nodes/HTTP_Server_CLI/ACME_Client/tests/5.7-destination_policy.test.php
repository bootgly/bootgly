<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Account;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\ProtocolException;

return new Specification(
   description: 'ACME destination policy: exact origins, global addresses and private-test opt-in',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-acme-destinations-' . getmypid() . '/';
      $blockedPath = sys_get_temp_dir() . '/bootgly-acme-blocked-destination-' . getmypid() . '/';
      $foreignPath = sys_get_temp_dir() . '/bootgly-acme-foreign-kid-' . getmypid() . '/';

      $Reject = static function (Closure $Action, string $class): bool {
         try {
            $Action();
         }
         catch (Throwable $Throwable) {
            return $Throwable instanceof $class;
         }

         return false;
      };

      try {
         $Account = new Account($path);
         $Client = new ACME_Client(
            $Account,
            'https://CA.EXAMPLE.TEST./directory',
            authorities: [
               'https://delegate.example.test:8443/',
               'https://DELEGATE.EXAMPLE.TEST:8443',
            ],
         );
         $Authorize = new ReflectionMethod($Client, 'authorize');
         $Resolve = new ReflectionMethod($Client, 'resolve');

         yield assert(
            assertion: $Client->authorities === [
               'https://ca.example.test',
               'https://delegate.example.test:8443',
            ] && $Client->allowPrivate === false,
            description: 'directory and delegated origins canonicalize by host case, trailing dot and effective port'
         );

         $same = $Authorize->invoke(
            $Client,
            'https://ca.example.test:443/order?status=pending',
         );
         $delegated = $Authorize->invoke(
            $Client,
            'https://DELEGATE.EXAMPLE.TEST:8443/new-account',
         );
         yield assert(
            assertion: ($same['origin'] ?? null) === 'https://ca.example.test'
               && ($same['lookup'] ?? null) === 'ca.example.test'
               && ($same['path'] ?? null) === '/order?status=pending'
               && ($delegated['origin'] ?? null) === 'https://delegate.example.test:8443',
            description: 'same-origin default-443 and an explicitly delegated exact-port origin are admitted'
         );
         $absolute = $Authorize->invoke(
            $Client,
            'https://CA.EXAMPLE.TEST./absolute',
         );
         yield assert(
            assertion: ($absolute['origin'] ?? null) === 'https://ca.example.test'
               && ($absolute['lookup'] ?? null) === 'ca.example.test.',
            description: 'terminal-dot origins canonicalize without losing absolute DNS lookup semantics'
         );

         $foreign = [
            'https://other.example.test/order',
            'https://ca.example.test:444/order',
            'http://ca.example.test/order',
            'https://user@ca.example.test/order',
            'https://ca.example.test/order#fragment',
            'https://ca.example.test\\@127.0.0.1/order',
            'https://tést.example/order',
         ];
         foreach ($foreign as $URL) {
            yield assert(
               assertion: $Reject(
                  static fn () => $Authorize->invoke($Client, $URL),
                  ProtocolException::class,
               ),
               description: "foreign or ambiguous ACME URL is rejected: {$URL}"
            );
         }

         $invalidAuthorities = [
            ['http://delegate.example.test'],
            ['https://delegate.example.test/acme'],
            ['https://delegate.example.test?tenant=1'],
            ['https://user@delegate.example.test'],
            ['https://delegate.example.test/#fragment'],
            ['https://delegate.example.test\\@127.0.0.1'],
            [42],
         ];
         foreach ($invalidAuthorities as $authorities) {
            yield assert(
               assertion: $Reject(
                  static fn () => new ACME_Client(
                     $Account,
                     'https://ca.example.test/directory',
                     authorities: $authorities,
                  ),
                  InvalidArgumentException::class,
               ),
               description: 'delegated authority configuration accepts origins only'
            );
         }

         $prohibited = [
            'localhost',
            '127.0.0.1',
            '10.0.0.1',
            '172.16.0.1',
            '192.168.0.1',
            '169.254.1.1',
            '100.64.0.1',
            '192.0.2.1',
            '192.88.99.1',
            '198.18.0.1',
            '224.0.0.1',
            '0.0.0.0',
            '::1',
            'fe80::1',
            'fc00::1',
            '2001:db8::1',
            '64:ff9b::7f00:1',
            '64:ff9b:1::a00:1',
            '2002:7f00:1::',
            'ff00::1',
            '::ffff:127.0.0.1',
         ];
         foreach ($prohibited as $IP) {
            yield assert(
               assertion: $Reject(
                  static fn () => $Resolve->invoke($Client, $IP),
                  ProtocolException::class,
               ),
               description: "default ACME egress rejects non-global/special address {$IP}"
            );
         }
         yield assert(
            assertion: $Resolve->invoke($Client, '93.184.216.34') === '93.184.216.34',
            description: 'a globally routable unicast literal remains an admissible dial target'
         );
         $pinned = $Resolve->invoke(
            $Client,
            '93.184.216.34',
            'https://ca.example.test',
         );
         $rebound = $Resolve->invoke(
            $Client,
            '127.0.0.1',
            'https://ca.example.test',
         );
         yield assert(
            assertion: $pinned === '93.184.216.34' && $rebound === $pinned,
            description: 'an approved origin reuses its first vetted numeric dial target instead of re-resolving'
         );

         $BlockedClient = new ACME_Client(
            new Account($blockedPath),
            'https://127.0.0.1:9/directory',
         );
         yield assert(
            assertion: $Reject(
               static fn () => $BlockedClient->register('blocked@example.test', true),
               ProtocolException::class,
            ),
            description: 'the public registration flow rejects a loopback directory before transport I/O'
         );

         $PrivateClient = new ACME_Client(
            $Account,
            'https://127.0.0.1:9443/directory',
            authorities: ['https://[::1]:9444'],
            allowPrivate: true,
         );
         $PrivateAuthorize = new ReflectionMethod($PrivateClient, 'authorize');
         $PrivateResolve = new ReflectionMethod($PrivateClient, 'resolve');
         $private = $PrivateAuthorize->invoke(
            $PrivateClient,
            'https://[0:0:0:0:0:0:0:1]:9444/new-account',
         );
         yield assert(
            assertion: ($private['origin'] ?? null) === 'https://[::1]:9444'
               && $PrivateResolve->invoke($PrivateClient, '127.0.0.1') === '127.0.0.1'
               && $PrivateResolve->invoke($PrivateClient, '10.0.0.1') === '10.0.0.1'
               && $PrivateResolve->invoke($PrivateClient, '::1') === '::1'
               && $PrivateResolve->invoke($PrivateClient, 'fc00::1') === 'fc00::1',
            description: 'explicit private-test mode admits private unicast address classes'
         );
         foreach (['0.0.0.0', '224.0.0.1', '255.255.255.255', '::', 'ff00::1'] as $IP) {
            yield assert(
               assertion: $Reject(
                  static fn () => $PrivateResolve->invoke($PrivateClient, $IP),
                  ProtocolException::class,
               ),
               description: "private-test mode still rejects unspecified/multicast/reserved address {$IP}"
            );
         }

         $ForeignAccount = new Account($foreignPath);
         $ForeignAccount->Key->derive();
         $ForeignAccount->save('https://foreign.example.test/acct/7');
         $ForeignAccount->update('m15@example.test');
         $ForeignClient = new ACME_Client(
            $ForeignAccount,
            'https://ca.example.test/directory',
         );
         $Post = new ReflectionMethod($ForeignClient, 'post');
         yield assert(
            assertion: $Reject(
               static fn () => $ForeignClient->register('m15@example.test', true),
               ProtocolException::class,
            ) && $Reject(
               static fn () => $Post->invoke(
                  $ForeignClient,
                  'https://ca.example.test/new-order',
                  [],
               ),
               ProtocolException::class,
            ) && $ForeignAccount->URL === 'https://foreign.example.test/acct/7',
            description: 'a persisted foreign account kid is rejected by register and before any direct signed POST'
         );
      }
      finally {
         foreach ([$path, $blockedPath, $foreignPath] as $directory) {
            foreach (glob("{$directory}*") ?: [] as $file) {
               @unlink($file);
            }
            @rmdir($directory);
         }
      }
   },
);
