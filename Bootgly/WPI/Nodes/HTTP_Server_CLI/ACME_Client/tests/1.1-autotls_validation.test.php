<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;

return new Specification(
   description: 'AutoTLS: defaults, directory resolution and fail-fast validation',
   test: function () {
      // @ Defaults
      $AutoTLS = new AutoTLS(
         domains: ['Example.COM', 'www.example.com'],
         email: 'admin@example.com'
      );

      yield assert(
         assertion: $AutoTLS->domains === ['example.com', 'www.example.com'],
         description: 'domains are normalized to lowercase, order preserved'
      );
      yield assert(
         assertion: $AutoTLS->directory === AutoTLS::DIRECTORY,
         description: 'directory defaults to the Let\'s Encrypt production endpoint'
      );
      yield assert(
         assertion: $AutoTLS->staging === false,
         description: 'staging defaults to false'
      );
      yield assert(
         assertion: str_ends_with($AutoTLS->path, 'security/tls/'),
         description: 'path defaults to the storage security/tls/ directory'
      );
      yield assert(
         assertion: $AutoTLS->threshold === AutoTLS::DEFAULT_THRESHOLD,
         description: 'threshold defaults to 30 days'
      );
      yield assert(
         assertion: $AutoTLS->bits === AutoTLS::DEFAULT_BITS,
         description: 'bits defaults to 2048'
      );
      yield assert(
         assertion: $AutoTLS->port === AutoTLS::DEFAULT_PORT,
         description: 'validation port defaults to 80'
      );
      yield assert(
         assertion: $AutoTLS->verify === true,
         description: 'directory peer verification defaults to true (fail-closed)'
      );
      yield assert(
         assertion: $AutoTLS->agreement === true,
         description: 'terms agreement defaults to true'
      );
      yield assert(
         assertion: $AutoTLS->options === [],
         description: 'extra SSL context options default to empty'
      );

      // @ Directory resolution
      $Staging = new AutoTLS(
         domains: ['example.com'],
         email: 'admin@example.com',
         staging: true
      );
      yield assert(
         assertion: $Staging->directory === AutoTLS::STAGING,
         description: 'staging: true selects the staging directory'
      );

      $Pebble = new AutoTLS(
         domains: ['localhost'],
         email: 'admin@example.com',
         staging: true,
         directory: 'https://localhost:14000/dir'
      );
      yield assert(
         assertion: $Pebble->directory === 'https://localhost:14000/dir',
         description: 'an explicit directory wins over staging'
      );
      yield assert(
         assertion: $Pebble->domains === ['localhost'],
         description: 'a single-label hostname (localhost) is accepted'
      );

      // @ Path normalization
      $Pathed = new AutoTLS(
         domains: ['example.com'],
         email: 'admin@example.com',
         path: '/tmp/acme-test'
      );
      yield assert(
         assertion: $Pathed->path === '/tmp/acme-test/',
         description: 'a custom path gains a trailing slash'
      );

      // @ Fail-fast validation
      $failures = [
         'empty domains' => fn () => new AutoTLS(
            domains: [],
            email: 'admin@example.com'
         ),
         'empty domain entry' => fn () => new AutoTLS(
            domains: [''],
            email: 'admin@example.com'
         ),
         'non-string domain entry' => fn () => new AutoTLS(
            domains: [42],
            email: 'admin@example.com'
         ),
         'wildcard domain' => fn () => new AutoTLS(
            domains: ['*.example.com'],
            email: 'admin@example.com'
         ),
         'malformed hostname' => fn () => new AutoTLS(
            domains: ['-bad.example.com'],
            email: 'admin@example.com'
         ),
         'hostname with spaces' => fn () => new AutoTLS(
            domains: ['exa mple.com'],
            email: 'admin@example.com'
         ),
         'invalid email' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'not-an-email'
         ),
         'agreement refused' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            agreement: false
         ),
         'threshold too low' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            threshold: 0
         ),
         'threshold too high' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            threshold: 90
         ),
         'weak key size' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            bits: 1024
         ),
         'invalid port' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            port: 0
         ),
         'non-https directory' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            directory: 'http://localhost:14000/dir'
         ),
         'directory credentials' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            directory: 'https://user:secret@localhost:14000/dir'
         ),
         'empty path' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            path: ''
         ),
         'root path' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            path: '/'
         ),
         'shallow path' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            path: '/tls'
         ),
         'relative path' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            path: 'storage/tls'
         ),
         'traversal path' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            path: '/var/tls/../../etc'
         ),
         'control byte in path' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            path: "/tmp/acme\nstate"
         ),
         'managed option local_cert' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            options: ['local_cert' => '/custom/cert.pem']
         ),
         'managed option local_pk' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            options: ['local_pk' => '/custom/key.pem']
         ),
         'managed option set to null (suppression bypass)' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            options: ['local_cert' => null]
         ),
         'managed option passphrase set to null' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            options: ['passphrase' => null]
         ),
         'SNI certificate map (second credential selector)' => fn () => new AutoTLS(
            domains: ['example.com'],
            email: 'admin@example.com',
            options: ['SNI_server_certs' => ['example.com' => '/other/cert.pem']]
         ),
      ];

      foreach ($failures as $case => $construct) {
         $thrown = false;
         try {
            $construct();
         }
         catch (InvalidArgumentException) {
            $thrown = true;
         }

         yield assert(
            assertion: $thrown,
            description: "construction fails fast: {$case}"
         );
      }

      // @ Wildcard rejection names the deferred path
      try {
         new AutoTLS(domains: ['*.example.com'], email: 'admin@example.com');
      }
      catch (InvalidArgumentException $Exception) {
         yield assert(
            assertion: str_contains($Exception->getMessage(), 'DNS-01'),
            description: 'the wildcard rejection message names DNS-01 as the deferred path'
         );
      }

      // @ Configuration identity — SAN-set and CA changes never collide
      $Primary = new AutoTLS(domains: ['example.com', 'www.example.com'], email: 'admin@example.com');
      $Reordered = new AutoTLS(domains: ['www.example.com', 'example.com'], email: 'admin@example.com');
      $Extended = new AutoTLS(domains: ['example.com', 'api.example.com'], email: 'admin@example.com');
      $Custom = new AutoTLS(
         domains: ['example.com', 'www.example.com'],
         email: 'admin@example.com',
         directory: 'https://ca.internal.test/dir'
      );

      yield assert(
         assertion: $Primary->identity === $Reordered->identity,
         description: 'the identity is order-insensitive over the SAN set'
      );
      yield assert(
         assertion: $Primary->identity !== $Extended->identity
            && $Primary->identity !== $Custom->identity,
         description: 'a different SAN set or CA directory yields a different identity'
      );
      yield assert(
         assertion: $Primary->Certificates->path !== $Extended->Certificates->path
            && $Primary->Certificates->path !== $Custom->Certificates->path,
         description: 'distinct identities resolve to distinct certificate stores'
      );

      // @ SAN canonicalization — duplicates collapse, primary stays first
      $Duplicated = new AutoTLS(
         domains: ['example.com', 'EXAMPLE.com', 'www.example.com'],
         email: 'admin@example.com'
      );
      yield assert(
         assertion: $Duplicated->domains === ['example.com', 'www.example.com'],
         description: 'duplicate domains collapse after normalization, order preserved'
      );

      // @ Store-prefix collision vector (found against the 8-hex suffix):
      //   both SAN sets share the first 32 identity bits — the 128-bit
      //   directory suffix must still keep the stores apart
      $CollisionA = new AutoTLS(
         domains: ['example.com', 'h26421.example.com'],
         email: 'admin@example.com'
      );
      $CollisionB = new AutoTLS(
         domains: ['example.com', 'h74260.example.com'],
         email: 'admin@example.com'
      );
      yield assert(
         assertion: $CollisionA->Certificates->path !== $CollisionB->Certificates->path,
         description: 'the 8-hex chosen-collision vector resolves to distinct stores'
      );

      // @ Filesystem component limits — a maximal 253-byte hostname must
      //   never overflow NAME_MAX (255) in the store directory name
      $longest = implode('.', [
         str_repeat('a', 63),
         str_repeat('b', 63),
         str_repeat('c', 63),
         str_repeat('d', 57),
         'com'
      ]); // 253 bytes — the RFC 1035 maximum
      $Longest = new AutoTLS(
         domains: [$longest],
         email: 'admin@example.com',
         staging: true
      );
      $component = basename(rtrim($Longest->Certificates->path, '/'));
      yield assert(
         assertion: strlen($longest) === 253 && strlen($component) <= 255,
         description: 'a 253-byte primary hostname yields a store component within NAME_MAX'
      );

      // @ Account scoping — two ACME services under the same authority but
      //   different directory paths never share an account (key/kid)
      $ServiceA = new AutoTLS(
         domains: ['example.com'],
         email: 'admin@example.com',
         directory: 'https://ca.internal.test/acme-a/directory'
      );
      $ServiceB = new AutoTLS(
         domains: ['example.com'],
         email: 'admin@example.com',
         directory: 'https://ca.internal.test/acme-b/directory'
      );
      yield assert(
         assertion: $ServiceA->Account->path !== $ServiceB->Account->path,
         description: 'same-authority ACME services with different paths get distinct accounts'
      );
   }
);
