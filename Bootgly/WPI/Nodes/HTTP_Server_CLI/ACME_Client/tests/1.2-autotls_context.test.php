<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Certificates;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;

return new Specification(
   description: 'AutoTLS: context resolution (bootstrap → installed), identity guard, credential validation, lock and backoff',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-acme-facade-' . getmypid() . '/';

      $clean = static function () use ($path): void {
         if (is_dir($path) === false) {
            return;
         }
         $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
         );
         foreach ($Iterator as $Entry) {
            $Entry->isDir() ? rmdir($Entry->getPathname()) : unlink($Entry->getPathname());
         }
         rmdir($path);
      };

      $forge = static function (string $CN, int $days = 90): array {
         $Key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
         $configuration = tempnam(sys_get_temp_dir(), 'bootgly-acme-test-');
         file_put_contents(
            $configuration,
            "[req]\ndistinguished_name = dn\nreq_extensions = v3_req\nx509_extensions = v3_req\n[dn]\n[v3_req]\nsubjectAltName = DNS:{$CN}\n"
         );
         $arguments = [
            'digest_alg' => 'sha256',
            'config' => $configuration,
            'req_extensions' => 'v3_req',
            'x509_extensions' => 'v3_req'
         ];
         $Request = openssl_csr_new(['commonName' => $CN], $Key, $arguments);
         $X509 = openssl_csr_sign($Request, null, $Key, $days, $arguments);
         $leaf = '';
         $private = '';
         openssl_x509_export($X509, $leaf);
         openssl_pkey_export($Key, $private);
         unlink($configuration);

         return [$leaf, $private];
      };

      try {
         $AutoTLS = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: $path
         );
         $store = $AutoTLS->Certificates->path;

         // @ Empty store
         yield assert(
            assertion: $AutoTLS->context === [] && $AutoTLS->check() === false,
            description: 'with no certificate the context is empty and check() fails'
         );

         // @ Bootstrap
         $AutoTLS->forge();

         yield assert(
            assertion: str_ends_with((string) ($AutoTLS->context['local_cert'] ?? ''), 'bootstrap.pem')
               && isset($AutoTLS->context['local_pk']) === false,
            description: 'after forge() the context serves the combined bootstrap PEM'
         );
         yield assert(
            assertion: $AutoTLS->check() === false,
            description: 'the bootstrap never satisfies check()'
         );

         $bootstrap = (string) file_get_contents("{$store}bootstrap.pem");
         $parsed = openssl_x509_parse($bootstrap);

         yield assert(
            assertion: is_array($parsed)
               && str_contains((string) ($parsed['extensions']['subjectAltName'] ?? ''), 'DNS:localhost'),
            description: 'the bootstrap certificate carries the SAN set'
         );

         $AutoTLS->forge();

         yield assert(
            assertion: (string) file_get_contents("{$store}bootstrap.pem") === $bootstrap,
            description: 'a second forge() reuses the unexpired bootstrap'
         );

         // @ Credential validation — a mismatched pair never commits
         [$leaf] = $forge('localhost');
         [, $orphan] = $forge('localhost');

         $rejected = false;
         try {
            $AutoTLS->Certificates->install($leaf, $orphan);
         }
         catch (RuntimeException) {
            $rejected = true;
         }

         yield assert(
            assertion: $rejected && $AutoTLS->check() === false,
            description: 'install() rejects a certificate that does not match the private key'
         );

         // @ SAN coverage — a certificate missing an ordered domain never commits
         [$foreign, $foreignKey] = $forge('other.test');

         $rejected = false;
         try {
            $AutoTLS->Certificates->install($foreign, $foreignKey, ['localhost']);
         }
         catch (RuntimeException) {
            $rejected = true;
         }

         yield assert(
            assertion: $rejected,
            description: 'install() rejects a certificate that does not cover the ordered domain'
         );

         // @ Install lands in the context without any cache invalidation
         [$issued, $issuedKey] = $forge('localhost');
         $AutoTLS->Certificates->install($issued, $issuedKey, ['localhost']);

         yield assert(
            assertion: str_ends_with((string) ($AutoTLS->context['local_cert'] ?? ''), 'fullchain.pem')
               && str_ends_with((string) ($AutoTLS->context['local_pk'] ?? ''), 'key.pem'),
            description: 'an install is visible in the context immediately (no caching)'
         );
         yield assert(
            assertion: $AutoTLS->check() === true,
            description: 'an installed unexpired certificate satisfies check()'
         );

         // @ Identity guard — a manifest written for another identity is
         //   never trusted, even when its files are readable
         $Foreign = new Certificates($store, 'another-identity');

         yield assert(
            assertion: $Foreign->check() === false
               && str_ends_with((string) $Foreign->read()['certificate'], 'bootstrap.pem'),
            description: 'an identity-mismatched manifest is ignored (bootstrap fallback only)'
         );

         // @ Options merge — non-managed options join the context
         $Options = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: $path,
            options: ['alpn_protocols' => 'h2']
         );

         yield assert(
            assertion: $Options->context['alpn_protocols'] === 'h2'
               && str_ends_with((string) ($Options->context['local_cert'] ?? ''), 'fullchain.pem'),
            description: 'non-managed options merge with the managed credential paths'
         );

         // @ Renewal lock — a held lock short-circuits renew() to false
         $lock = fopen("{$path}renew.lock", 'c');
         flock($lock, LOCK_EX);

         yield assert(
            assertion: $AutoTLS->renew() === false,
            description: 'renew() yields to a concurrent holder of renew.lock'
         );

         flock($lock, LOCK_UN);
         fclose($lock);

         // @ A planted final lock symlink must never be followed or chmodded.
         unlink("{$path}renew.lock");
         $victim = "{$path}renew-victim";
         file_put_contents($victim, 'keep');
         chmod($victim, 0644);
         symlink($victim, "{$path}renew.lock");
         $refused = false;
         try {
            $AutoTLS->renew();
         }
         catch (RuntimeException) {
            $refused = true;
         }
         yield assert(
            assertion: $refused
               && file_get_contents($victim) === 'keep'
               && (fileperms($victim) & 0777) === 0644,
            description: 'renew() refuses a final lock symlink without touching its target'
         );
         unlink("{$path}renew.lock");
         unlink($victim);

         // @ Threshold — a fresh certificate makes renew() a no-op
         yield assert(
            assertion: $AutoTLS->renew() === false,
            description: 'renew() does nothing while the certificate is far from expiry'
         );

         // @ Failure records a backoff; the next attempt short-circuits
         $Unreachable = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: "{$path}unreachable/",
            directory: 'https://127.0.0.1:1/dir',
            verify: false
         );

         $thrown = false;
         try {
            $Unreachable->renew();
         }
         catch (Throwable) {
            $thrown = true;
         }

         $order = json_decode(
            (string) file_get_contents("{$Unreachable->Certificates->path}order.json"),
            true
         );

         yield assert(
            assertion: $thrown
               && is_array($order)
               && $order['attempts'] === 1
               && $order['retry'] > time(),
            description: 'a failed renewal throws and records the backoff schedule'
         );
         yield assert(
            assertion: $Unreachable->renew() === false,
            description: 'renew() short-circuits to false while backing off'
         );

         // @ Credential snapshot is all-or-nothing — a readable issued
         //   certificate whose key was corrupted or lost is never exposed
         $Partial = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: "{$path}pairloss/"
         );
         $Partial->forge();
         [$pairLeaf, $pairKey] = $forge('localhost');
         $Partial->Certificates->install($pairLeaf, $pairKey, ['localhost']);
         $installed = (string) $Partial->context['local_pk'];

         [, $strangerKey] = $forge('localhost');
         file_put_contents($installed, $strangerKey);

         yield assert(
            assertion: $Partial->check() === false,
            description: 'check() revalidates the pair on disk — a swapped key file fails it'
         );

         unlink($installed);

         yield assert(
            assertion: str_ends_with((string) ($Partial->context['local_cert'] ?? ''), 'bootstrap.pem')
               && isset($Partial->context['local_pk']) === false
               && $Partial->check() === false,
            description: 'a lost private key drops the whole snapshot back to the bootstrap'
         );

         // @ Generations are collision-proof — two installs in the same
         //   second land in distinct directories, and prune() never deletes
         //   the generation the manifest references
         $Versions = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: "{$path}versions/"
         );
         [$firstLeaf, $firstKey] = $forge('localhost');
         [$secondLeaf, $secondKey] = $forge('localhost');
         $Versions->Certificates->install($firstLeaf, $firstKey, ['localhost']);
         $Versions->Certificates->install($secondLeaf, $secondKey, ['localhost']);

         $generations = [];
         foreach (scandir($Versions->Certificates->path) ?: [] as $entry) {
            if (preg_match('/^\d+(-\d+)?$/', $entry) === 1) {
               $generations[] = $entry;
            }
         }

         yield assert(
            assertion: count($generations) === 2,
            description: 'two same-second installs occupy two distinct generation directories'
         );

         // ! Two stale future-dated versions outrank the current one in the
         //   prune sort — the manifest target must survive anyway
         $future = $Versions->Certificates->path;
         mkdir("{$future}9999999999/", 0700);
         mkdir("{$future}9999999998/", 0700);
         [$thirdLeaf, $thirdKey] = $forge('localhost');
         $Versions->Certificates->install($thirdLeaf, $thirdKey, ['localhost']);

         $current = (string) $Versions->context['local_cert'];

         yield assert(
            assertion: is_file($current) && $Versions->check() === true,
            description: 'prune() never deletes the generation the manifest references'
         );

         // @ Bootstrap identity — a bootstrap left with a foreign SAN set
         //   (shared path / reconfiguration) is regenerated, never served
         $Rebooted = new AutoTLS(
            domains: ['san.test'],
            email: 'admin@example.com',
            path: "{$path}foreignboot/"
         );
         $Rebooted->forge();

         [$foreignBoot, $foreignBootKey] = $forge('other.test');
         file_put_contents(
            "{$Rebooted->Certificates->path}bootstrap.pem",
            "{$foreignBoot}{$foreignBootKey}"
         );

         yield assert(
            assertion: $Rebooted->context === [],
            description: 'a wrong-SAN bootstrap is never exposed through the raw server context'
         );

         $Rebooted->forge();
         $regenerated = openssl_x509_parse(
            (string) file_get_contents("{$Rebooted->Certificates->path}bootstrap.pem")
         );

         yield assert(
            assertion: is_array($regenerated)
               && str_contains((string) ($regenerated['extensions']['subjectAltName'] ?? ''), 'DNS:san.test'),
            description: 'forge() regenerates a bootstrap whose SAN set is not this configuration'
         );

         // @ A cryptographically matching pair issued for OTHER names never
         //   passes — SAN identity is validated on the disk, not the manifest
         $Foreign2 = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: "{$path}foreignpair/"
         );
         $Foreign2->forge();
         [$okLeaf, $okKey] = $forge('localhost');
         $Foreign2->Certificates->install($okLeaf, $okKey, ['localhost']);

         [$foreignLeaf2, $foreignKey2] = $forge('other.test');
         $foreignContext = $Foreign2->context;
         file_put_contents((string) $foreignContext['local_cert'], $foreignLeaf2);
         file_put_contents((string) $foreignContext['local_pk'], $foreignKey2);

         yield assert(
            assertion: $Foreign2->check() === false,
            description: 'a matching replacement pair issued for other names fails check()'
         );

         $Foreign2->forge();

         yield assert(
            assertion: str_ends_with((string) ($Foreign2->context['local_cert'] ?? ''), 'bootstrap.pem'),
            description: 'forge() drops a wrong-SAN store back to a fresh bootstrap'
         );

         // @ An on-disk EXPIRED replacement fails check() even while the
         //   manifest still records a future expiry
         $Expired = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: "{$path}expired/"
         );
         $Expired->forge();
         [$validLeaf, $validKey] = $forge('localhost');
         $Expired->Certificates->install($validLeaf, $validKey, ['localhost']);

         [$expiredLeaf, $expiredKey] = $forge('localhost', 0);
         $expiredContext = $Expired->context;
         file_put_contents((string) $expiredContext['local_cert'], $expiredLeaf);
         file_put_contents((string) $expiredContext['local_pk'], $expiredKey);

         yield assert(
            assertion: $Expired->check() === false,
            description: 'an expired on-disk replacement fails check() despite the manifest expiry'
         );

         // @ A bootstrap whose embedded key block was stripped is treated
         //   as absent — a keyless listener would refuse every handshake
         $Keyless = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: "{$path}keyless/"
         );
         $Keyless->forge();
         $boot = "{$Keyless->Certificates->path}bootstrap.pem";
         $whole = (string) file_get_contents($boot);
         $stripped = (string) preg_replace(
            '/-----BEGIN[A-Z ]*PRIVATE KEY-----.*?-----END[A-Z ]*PRIVATE KEY-----\n?/s',
            '',
            $whole
         );
         file_put_contents($boot, $stripped);

         yield assert(
            assertion: $Keyless->context === [],
            description: 'a bootstrap missing its embedded private key is never served'
         );

         $Keyless->forge();

         yield assert(
            assertion: ($Keyless->context['local_cert'] ?? null) !== null,
            description: 'forge() regenerates a keyless bootstrap'
         );

         // @ An EXPIRED same-SAN bootstrap replacement is treated as absent
         //   — the certificate's OWN dates decide, never the manifest's
         $Stale = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: "{$path}staleboot/"
         );
         $Stale->forge();
         [$staleLeaf, $staleKey] = $forge('localhost', 0); // already outside its window
         file_put_contents(
            "{$Stale->Certificates->path}bootstrap.pem",
            "{$staleLeaf}{$staleKey}"
         );

         yield assert(
            assertion: $Stale->context === [],
            description: 'an expired bootstrap replacement is never served despite the manifest expiry'
         );

         $Stale->forge();

         yield assert(
            assertion: ($Stale->context['local_cert'] ?? null) !== null,
            description: 'forge() regenerates an expired bootstrap'
         );

         // @ inspect() derives days from the certificate ON DISK — a
         //   tampered future manifest expiry cannot suppress renewal
         $Tampered = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: "{$path}tampered/"
         );
         $Tampered->forge();
         [$ninetyLeaf, $ninetyKey] = $forge('localhost'); // ~90 days
         $Tampered->Certificates->install($ninetyLeaf, $ninetyKey, ['localhost']);

         $manifest = "{$Tampered->Certificates->path}current.json";
         $decoded = json_decode((string) file_get_contents($manifest), true);
         $decoded['expires'] = time() + (10 * 365 * 86400); // 10 years of lies
         file_put_contents($manifest, json_encode($decoded));

         $days = $Tampered->Certificates->inspect();

         yield assert(
            assertion: $days !== null && $days <= 90,
            description: 'inspect() reports the on-disk expiry, not a tampered manifest one'
         );

         // @ An EXISTING symlinked store is never read through — reads are
         //   as contained as writes
         $Real = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: "{$path}realstore/"
         );
         $Real->forge();
         symlink(
            rtrim($Real->Certificates->path, '/'),
            "{$path}linkstore"
         );
         $Symlinked = new Certificates("{$path}linkstore/", $Real->identity);

         yield assert(
            assertion: $Symlinked->read()['certificate'] === null
               && $Symlinked->check() === false,
            description: 'an existing symlinked store is never read through'
         );
      }
      finally {
         Challenges::configure(null);
         if (is_link("{$path}linkstore")) {
            unlink("{$path}linkstore");
         }
         $clean();
      }
   }
);
