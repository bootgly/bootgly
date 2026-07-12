<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Certificates;

return new Specification(
   description: 'ACME Certificates: bootstrap forge, inspection, versioned install and manifest',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-acme-certs-' . getmypid() . '/';

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

      try {
         $Certificates = new Certificates($path);

         // @ Empty store
         yield assert(
            assertion: $Certificates->certificate === null
               && $Certificates->key === null
               && $Certificates->check() === false
               && $Certificates->inspect() === null,
            description: 'an empty store exposes no certificate and fails check()'
         );

         // @ Bootstrap
         $Certificates->forge(['localhost'], 2048);

         yield assert(
            assertion: $Certificates->certificate === "{$path}bootstrap.pem"
               && $Certificates->key === null,
            description: 'after forge() the certificate is the combined bootstrap PEM (no separate key)'
         );
         yield assert(
            assertion: $Certificates->check() === false,
            description: 'the bootstrap never satisfies check() (self-signed)'
         );

         $days = $Certificates->inspect();

         yield assert(
            assertion: $days !== null && $days >= 28 && $days <= 30,
            description: 'the bootstrap expires in ~30 days'
         );

         $parsed = openssl_x509_parse((string) file_get_contents("{$path}bootstrap.pem"));

         yield assert(
            assertion: is_array($parsed)
               && ($parsed['subject']['CN'] ?? null) === 'localhost'
               && $parsed['subject'] === $parsed['issuer'],
            description: 'the bootstrap is self-signed with the primary domain as CN'
         );

         // @ Versioned install — a REAL two-certificate chain (a test root
         //   CA plus a leaf it signed), so the leaf/chain split is provably
         //   distinct blocks, not the leaf repeated
         $CAKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
         $CARequest = openssl_csr_new(['commonName' => 'Bootgly Test Root'], $CAKey, ['digest_alg' => 'sha256']);
         $CA = openssl_csr_sign($CARequest, null, $CAKey, 120, ['digest_alg' => 'sha256']);
         $root = '';
         openssl_x509_export($CA, $root);

         $Key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
         $Request = openssl_csr_new(['commonName' => 'issued.localhost'], $Key, ['digest_alg' => 'sha256']);
         $X509 = openssl_csr_sign($Request, $CA, $CAKey, 90, ['digest_alg' => 'sha256']);
         $leaf = '';
         $private = '';
         openssl_x509_export($X509, $leaf);
         openssl_pkey_export($Key, $private);

         $Certificates->install("{$leaf}{$root}", $private);

         yield assert(
            assertion: $Certificates->check() === true,
            description: 'an installed unexpired certificate satisfies check()'
         );
         yield assert(
            assertion: str_ends_with((string) $Certificates->certificate, '/fullchain.pem')
               && str_ends_with((string) $Certificates->key, '/key.pem'),
            description: 'the manifest points at the versioned fullchain and key'
         );
         yield assert(
            assertion: (fileperms((string) $Certificates->key) & 0777) === 0600,
            description: 'the installed key has 0600 permissions'
         );

         $days = $Certificates->inspect();

         yield assert(
            assertion: $days !== null && $days >= 88 && $days <= 90,
            description: 'inspect() reports the installed leaf expiry (~90 days)'
         );

         // @ Leaf/chain split — provably distinct blocks
         $directory = dirname((string) $Certificates->certificate);
         $chain = (string) file_get_contents("{$directory}/chain.pem");
         $split = (string) file_get_contents("{$directory}/certificate.pem");
         $chainParsed = openssl_x509_parse($chain);
         $splitParsed = openssl_x509_parse($split);

         yield assert(
            assertion: substr_count($split, 'BEGIN CERTIFICATE') === 1
               && substr_count($chain, 'BEGIN CERTIFICATE') === 1
               && is_array($splitParsed) && ($splitParsed['subject']['CN'] ?? null) === 'issued.localhost'
               && is_array($chainParsed) && ($chainParsed['subject']['CN'] ?? null) === 'Bootgly Test Root',
            description: 'a 2-block fullchain splits into the leaf and the DISTINCT chain certificate'
         );

         // @ Signature linkage — the stored leaf is actually signed by the
         //   stored chain certificate, not merely adjacent to it
         yield assert(
            assertion: openssl_x509_verify($split, $chain) === 1,
            description: 'the leaf signature verifies against the chain certificate'
         );

         // @ Every chain block must parse — trailing garbage or a corrupt
         //   intermediate is rejected before anything is persisted
         $rejected = false;
         try {
            $Certificates->install(
               "{$leaf}-----BEGIN CERTIFICATE-----\ngarbage\n-----END CERTIFICATE-----\n",
               $private
            );
         }
         catch (RuntimeException) {
            $rejected = true;
         }

         yield assert(
            assertion: $rejected,
            description: 'install() rejects a fullchain carrying an unparseable block'
         );

         $rejected = false;
         try {
            $Certificates->install("{$leaf}{$root}not-a-certificate", $private);
         }
         catch (RuntimeException) {
            $rejected = true;
         }

         yield assert(
            assertion: $rejected,
            description: 'install() rejects non-PEM bytes trailing a valid chain'
         );

         // @ Every adjacent pair must LINK — a parseable but UNRELATED
         //   certificate riding along as "the chain" is rejected
         $StrangerKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
         $StrangerRequest = openssl_csr_new(['commonName' => 'Unrelated Root'], $StrangerKey, ['digest_alg' => 'sha256']);
         $Stranger = openssl_csr_sign($StrangerRequest, null, $StrangerKey, 120, ['digest_alg' => 'sha256']);
         $unrelated = '';
         openssl_x509_export($Stranger, $unrelated);

         $rejected = false;
         try {
            $Certificates->install("{$leaf}{$unrelated}", $private);
         }
         catch (RuntimeException) {
            $rejected = true;
         }

         yield assert(
            assertion: $rejected,
            description: 'install() rejects a chain whose blocks do not actually link'
         );

         // @ Post-commit integrity — later mutation cannot bypass the
         //   install-time chain validation because manifest hashes bind bytes.
         $committed = (string) $Certificates->certificate;
         $originalFullchain = (string) file_get_contents($committed);
         file_put_contents($committed, "{$leaf}{$unrelated}");

         yield assert(
            assertion: $Certificates->check() === false
               && $Certificates->snapshot(allowBootstrap: false) === null,
            description: 'a post-commit fullchain mutation fails the generation hash and health check'
         );
         $manifestFile = "{$path}current.json";
         $originalManifest = (string) file_get_contents($manifestFile);
         $manifest = json_decode($originalManifest, true);
         $manifest['certificateHash'] = hash('sha256', "{$leaf}{$unrelated}");
         file_put_contents($manifestFile, json_encode($manifest));

         yield assert(
            assertion: $Certificates->check() === false
               && $Certificates->snapshot(allowBootstrap: false) === null,
            description: 'matching a tampered manifest hash cannot bypass fullchain linkage validation'
         );
         file_put_contents($committed, $originalFullchain);
         file_put_contents($manifestFile, $originalManifest);

         yield assert(
            assertion: $Certificates->check() === true,
            description: 'restoring the exact committed bytes restores the validated generation'
         );

         $Previous = $Certificates->snapshot(allowBootstrap: false);
         $Certificates->install("{$leaf}{$root}", $private);
         $Current = $Certificates->snapshot(allowBootstrap: false);
         yield assert(
            assertion: $Previous !== null
               && $Current !== null
               && $Current->generation !== $Previous->generation
               && $Certificates->snapshot(generation: $Previous->generation, allowBootstrap: false) === null,
            description: 'an exact stale generation never falls through to a newer manifest selection'
         );

         // @ Relative paths are rejected — the containment walk resolves
         //   from the filesystem root
         $rejected = false;
         try {
            new Certificates('relative/store/');
         }
         catch (InvalidArgumentException) {
            $rejected = true;
         }

         yield assert(
            assertion: $rejected,
            description: 'a relative store path is rejected at construction'
         );

         // @ Low-level validation — forge() interpolates domains into an
         //   OpenSSL configuration, so it validates on its own
         $rejected = false;
         try {
            $Certificates->forge(["evil.test\nsubjectAltName = DNS:injected.test"]);
         }
         catch (RuntimeException) {
            $rejected = true;
         }

         yield assert(
            assertion: $rejected,
            description: 'forge() rejects a domain carrying configuration-injection bytes'
         );

         $rejected = false;
         try {
            $Certificates->forge(['localhost'], 1024);
         }
         catch (RuntimeException) {
            $rejected = true;
         }

         yield assert(
            assertion: $rejected,
            description: 'the low-level bootstrap helper rejects RSA keys below 2048 bits'
         );

         // @ Prune containment — a numeric symlink inside the store is
         //   never followed: nothing outside the tree is deleted and the
         //   install that triggered the prune still commits
         $victim = "{$path}victim/";
         mkdir($victim, 0700);
         file_put_contents("{$victim}precious.txt", 'keep');
         symlink(rtrim($victim, '/'), "{$path}1");

         $Certificates->install("{$leaf}{$root}", $private);
         $Certificates->install("{$leaf}{$root}", $private);

         yield assert(
            assertion: is_file("{$victim}precious.txt")
               && $Certificates->check() === true,
            description: 'prune skips a symlinked version — the outside target survives and the install commits'
         );

         // @ Symlink containment — a store path crossing a symlinked
         //   component is refused before any write
         mkdir("{$path}real-target/", 0700);
         symlink("{$path}real-target", "{$path}linked");
         $Linked = new Certificates("{$path}linked/store/");

         $refused = false;
         try {
            $Linked->forge(['localhost']);
         }
         catch (RuntimeException $Exception) {
            $refused = str_contains($Exception->getMessage(), 'symlink');
         }

         yield assert(
            assertion: $refused,
            description: 'a store path crossing a symlinked component is refused before any write'
         );
      }
      finally {
         if (is_link("{$path}linked")) {
            unlink("{$path}linked");
         }
         if (is_link("{$path}1")) {
            unlink("{$path}1");
         }
         $clean();
      }
   }
);
