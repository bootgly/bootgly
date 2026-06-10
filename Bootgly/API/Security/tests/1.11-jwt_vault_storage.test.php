<?php

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\JWT\Vault;


return new Specification(
   description: 'JWT: vault on an injected Cache storage backend',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-jwt-vault-storage-' . bin2hex(random_bytes(4));
      $clean = static function (string $path): void {
         if (is_dir($path) === false) {
            return;
         }
         // ! The vault storage backend shards records into subdirectories
         $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
         );
         foreach ($Iterator as $Info) {
            $Info->isDir() ? rmdir($Info->getPathname()) : unlink($Info->getPathname());
         }
         rmdir($path);
      };

      // ! Clock-controlled storage backend (any driver works; file is always available)
      $offset = 0;
      $Storage = new Cache([
         'driver' => 'file',
         'path' => $path,
         'clock' => function () use (&$offset): int {
            return time() + $offset;
         },
      ]);
      $secret = bin2hex(random_bytes(32));

      $Vault = new Vault($Storage, secret: $secret);
      $written = $Vault->write('record', 'value', 60);

      yield assert(
         assertion: $written === true
            && $Vault->read('record') === 'value'
            && $Vault->Storage === $Storage,
         description: 'vault accepts a prepared Cache facade and round-trips records through it'
      );

      $Peer = new Vault($Storage, secret: $secret);

      yield assert(
         assertion: $Peer->read('record') === 'value',
         description: 'vault instances sharing storage and secret read each other\'s records'
      );

      $Stranger = new Vault($Storage, secret: bin2hex(random_bytes(32)));

      yield assert(
         assertion: $Stranger->read('record') === null,
         description: 'vault rejects records signed with a different secret'
      );

      $guarded = false;
      try {
         new Vault($Storage, secret: 'short');
      }
      catch (InvalidArgumentException) {
         $guarded = true;
      }

      yield assert(
         assertion: $guarded === true,
         description: 'vault rejects shared secrets shorter than 32 bytes'
      );

      $first = $Vault->claim('claimed', 'a', 60);
      $second = $Vault->claim('claimed', 'b', 60);

      yield assert(
         assertion: $first === true
            && $second === false
            && $Vault->read('claimed') === 'a',
         description: 'vault claim writes only when the key is absent'
      );

      $taken = $Vault->take('claimed');

      yield assert(
         assertion: $taken === 'a'
            && $Vault->read('claimed') === null
            && $Vault->claim('claimed', 'c', 60) === true,
         description: 'vault take returns and removes the record, releasing the claim'
      );

      $deleted = $Vault->delete('claimed');

      yield assert(
         assertion: $deleted === true && $Vault->read('claimed') === null,
         description: 'vault delete removes the record'
      );

      $Vault->write('volatile', 'soon-gone', 1);
      $offset = 2;
      $expired = $Vault->read('volatile');
      $purged = $Vault->purge();
      $offset = 0;

      yield assert(
         assertion: $expired === null && $purged === true,
         description: 'vault records expire through the storage TTL and purge succeeds'
      );

      $clean($path);
   }
);
