<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Account;

return new Specification(
   description: 'ACME Account: key generation, persistence, permissions and JWK shape',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-acme-account-' . getmypid() . '/';

      try {
         // @ Generation + persistence
         $Account = new Account($path, 2048);

         yield assert(
            assertion: $Account->URL === null,
            description: 'a fresh account has no registered URL'
         );

         $JWK = $Account->JWK;

         yield assert(
            assertion: is_file("{$path}key.pem"),
            description: 'the account key is persisted at key.pem on first access'
         );
         yield assert(
            assertion: (fileperms("{$path}key.pem") & 0777) === 0600,
            description: 'the persisted account key has 0600 permissions'
         );
         yield assert(
            assertion: ($JWK['kty'] ?? null) === 'RSA',
            description: 'the JWK declares kty RSA'
         );
         yield assert(
            assertion: preg_match('/^[A-Za-z0-9_\-]+$/', $JWK['n'] ?? '') === 1
               && preg_match('/^[A-Za-z0-9_\-]+$/', $JWK['e'] ?? '') === 1,
            description: 'JWK n and e are base64url without padding'
         );
         yield assert(
            assertion: $Account->thumbprint !== ''
               && preg_match('/^[A-Za-z0-9_\-]{43}$/', $Account->thumbprint) === 1,
            description: 'the thumbprint is a 43-char base64url SHA-256'
         );

         // @ Load round-trip — a second instance reuses the same key
         $Loaded = new Account($path, 2048);

         yield assert(
            assertion: $Loaded->JWK === $JWK,
            description: 'a second instance loads the persisted key (same JWK)'
         );

         chmod("{$path}key.pem", 0644);
         $Resecured = new Account($path, 2048);
         yield assert(
            assertion: $Resecured->JWK === $JWK
               && (fileperms("{$path}key.pem") & 0777) === 0600,
            description: 'a reusable persisted key is resecured to 0600 before use'
         );

         // @ Account URL persistence (the RFC 8555 kid)
         $Account->save('https://ca.example.test/acct/42');
         $Account->update('first@example.test');

         yield assert(
            assertion: $Account->URL === 'https://ca.example.test/acct/42',
            description: 'save() exposes the registered account URL'
         );

         $Reloaded = new Account($path, 2048);

         yield assert(
            assertion: $Reloaded->URL === 'https://ca.example.test/acct/42'
               && $Reloaded->contact === 'first@example.test'
               && (fileperms("{$path}url") & 0777) === 0600
               && (fileperms("{$path}contact") & 0777) === 0600,
            description: 'the private URL/contact state survives reload with 0600 permissions'
         );

         $rejected = false;
         try {
            $Account->save('/relative-account');
         }
         catch (InvalidArgumentException) {
            $rejected = true;
         }
         yield assert(
            assertion: $rejected && $Account->URL === 'https://ca.example.test/acct/42',
            description: 'save() rejects a relative account URL without poisoning the persisted kid'
         );

         // @ Relative paths are rejected — the containment walk resolves
         //   from the filesystem root
         $rejected = false;
         try {
            new Account('relative/account/');
         }
         catch (InvalidArgumentException) {
            $rejected = true;
         }

         yield assert(
            assertion: $rejected,
            description: 'a relative account path is rejected at construction'
         );

         // @ A symlinked account directory never sources NOR deletes state
         //   outside the tree — construction and reset() are contained too
         symlink(rtrim($path, '/'), sys_get_temp_dir() . '/bootgly-acme-account-link-' . getmypid());
         $link = sys_get_temp_dir() . '/bootgly-acme-account-link-' . getmypid() . '/';

         $Symlinked = new Account($link, 2048);

         yield assert(
            assertion: $Symlinked->URL === null,
            description: 'a symlinked account directory never sources the persisted kid'
         );

         $Symlinked->reset();

         yield assert(
            assertion: is_file("{$path}url"),
            description: 'reset() through a symlinked directory never deletes the real url'
         );

         // @ A corrupt persisted key is QUARANTINED and regenerated with the
         //   stale kid dropped — it must not poison every signing attempt
         file_put_contents("{$path}key.pem", 'garbage, not a key');
         $Recovered = new Account($path, 2048);
         $regenerated = $Recovered->JWK;

         yield assert(
            assertion: $regenerated !== $JWK
               && $Recovered->URL === null
               && glob("{$path}key.pem.corrupt.*") !== []
               && (openssl_pkey_get_private((string) file_get_contents("{$path}key.pem")) !== false),
            description: 'a corrupt account key is quarantined, regenerated and the stale kid dropped'
         );

         // @ Parseable but incompatible keys follow the same recovery path.
         $EC = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1'
         ]);
         $ecPEM = '';
         openssl_pkey_export($EC, $ecPEM);
         file_put_contents("{$path}key.pem", $ecPEM);
         $ECRecovered = new Account($path, 2048);
         $ecJWK = $ECRecovered->JWK;

         yield assert(
            assertion: ($ecJWK['kty'] ?? null) === 'RSA'
               && glob("{$path}key.pem.corrupt.*") !== [],
            description: 'a persisted EC key is quarantined and replaced by RSA'
         );

         $Weak = openssl_pkey_new([
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA
         ]);
         $weakPEM = '';
         openssl_pkey_export($Weak, $weakPEM);
         file_put_contents("{$path}key.pem", $weakPEM);
         $WeakRecovered = new Account($path, 2048);
         $weakJWK = $WeakRecovered->JWK;
         $details = openssl_pkey_get_details(
            openssl_pkey_get_private((string) file_get_contents("{$path}key.pem"))
         );

         yield assert(
            assertion: ($weakJWK['kty'] ?? null) === 'RSA'
               && is_array($details) && ($details['bits'] ?? 0) >= 2048,
            description: 'an undersized persisted RSA key is quarantined and replaced at the configured floor'
         );

         $rejected = false;
         try {
            new Account($path, 1024);
         }
         catch (InvalidArgumentException) {
            $rejected = true;
         }
         yield assert(
            assertion: $rejected,
            description: 'the public low-level account constructor rejects RSA sizes below 2048 bits'
         );
      }
      finally {
         @unlink(sys_get_temp_dir() . '/bootgly-acme-account-link-' . getmypid());
         foreach (glob("{$path}key.pem.corrupt.*") ?: [] as $quarantined) {
            @unlink($quarantined);
         }
         @unlink("{$path}key.pem");
         @unlink("{$path}url");
         @unlink("{$path}contact");
         @rmdir($path);
      }
   }
);
