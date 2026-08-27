<?php

use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Atomic;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\JWT\Vault;


return new Test(
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

      $unsupported = false;
      try {
         new Vault(new Cache(['driver' => 'apcu']), secret: $secret);
      }
      catch (InvalidArgumentException $Exception) {
         $unsupported = str_contains($Exception->getMessage(), 'atomic create, swap and evict');
      }

      yield assert(
         assertion: $unsupported === true,
         description: 'vault rejects a Cache driver without complete atomic primitives'
      );

      $atomic = [];
      foreach (['file', 'memory', 'shared', 'redis'] as $driver) {
         $atomic[$driver] = (new Cache(['driver' => $driver]))->Driver instanceof Atomic;
      }

      yield assert(
         assertion: $atomic === [
            'file' => true,
            'memory' => true,
            'shared' => true,
            'redis' => true,
         ],
         description: 'Vault-supported Cache drivers declare the complete atomic contract'
      );

      // @ An authentic legacy envelope can outlive its protected expiry when
      //   the backend clock/TTL lags. Replace it concurrently after claim()
      //   reads: only CAS keeps that new winner from being overwritten.
      $expiredPayload = json_encode([
         'expires' => time() - 1,
         'value' => 'expired',
      ], JSON_THROW_ON_ERROR);
      $expiredRecord = hash_hmac('sha256', $expiredPayload, $secret) . $expiredPayload;
      $concurrentPayload = json_encode([
         'expires' => time() + 60,
         'value' => 'concurrent',
         'nonce' => bin2hex(random_bytes(16)),
      ], JSON_THROW_ON_ERROR);
      $concurrentRecord = hash_hmac('sha256', $concurrentPayload, $secret) . $concurrentPayload;
      $expiredKey = 'jwt_' . hash('sha256', 'expired-claim');
      $CASPath = "{$path}-cas";
      $CASStorage = new class([
         'driver' => 'file',
         'path' => $CASPath,
      ], $expiredKey, $concurrentRecord) extends Cache implements Atomic {
         // * Data
         private string $target;
         private string $replacement;

         // * Metadata
         private bool $intervened = false;
         private int $swaps = 0;


         /** @param array<string,mixed> $config */
         public function __construct (array $config, string $target, string $replacement)
         {
            parent::__construct($config);

            // * Data
            $this->target = $target;
            $this->replacement = $replacement;
         }

         public function fetch (string $key): mixed
         {
            $value = parent::fetch($key);
            if ($this->intervened === false && $key === $this->target) {
               $this->intervened = true;
               parent::store($key, $this->replacement, 60);
            }

            return $value;
         }

         /** @param array<int,string> $tags */
         public function swap (
            string $key,
            mixed $expected,
            mixed $value,
            int $TTL = 0,
            array $tags = [],
         ): bool
         {
            $this->swaps++;

            return parent::swap($key, $expected, $value, $TTL, $tags);
         }

         /** @return array{intervened:bool,swaps:int} */
         public function report (): array
         {
            return [
               'intervened' => $this->intervened,
               'swaps' => $this->swaps,
            ];
         }
      };
      $CASVault = new Vault($CASStorage, secret: $secret);
      $CASStorage->store($expiredKey, $expiredRecord, 60);
      $CASClaimed = $CASVault->claim('expired-claim', 'fresh', 60);
      $CASValue = $CASVault->read('expired-claim');
      $CASState = $CASStorage->report();

      yield assert(
         assertion: $CASClaimed === false
            && $CASValue === 'concurrent'
            && $CASState['intervened'] === true
            && $CASState['swaps'] === 1,
         description: 'claim CAS preserves a concurrent replacement of an expired envelope'
      );

      // @ Two same-value writes in one second must still have distinct raw
      //   identities, so a stale compare-and-evict cannot delete the newer one.
      $ABAKey = 'jwt_' . hash('sha256', 'aba');
      $oldABA = null;
      $newABA = null;
      $OldPayload = null;
      $NewPayload = null;
      for ($attempt = 0; $attempt < 5; $attempt++) {
         $second = time();
         $Vault->write('aba', 'same', 60);
         $oldABA = $Storage->fetch($ABAKey);
         $Vault->write('aba', 'same', 60);
         $newABA = $Storage->fetch($ABAKey);

         if (time() === $second && is_string($oldABA) && is_string($newABA)) {
            $OldPayload = json_decode(substr($oldABA, 64), true, flags: JSON_THROW_ON_ERROR);
            $NewPayload = json_decode(substr($newABA, 64), true, flags: JSON_THROW_ON_ERROR);
            break;
         }
      }
      $staleEvicted = $Storage->evict($ABAKey, $oldABA);

      yield assert(
         assertion: is_string($oldABA)
            && is_string($newABA)
            && is_array($OldPayload)
            && is_array($NewPayload)
            && $OldPayload['expires'] === $NewPayload['expires']
            && is_string($OldPayload['nonce'] ?? null)
            && is_string($NewPayload['nonce'] ?? null)
            && $OldPayload['nonce'] !== $NewPayload['nonce']
            && $oldABA !== $newABA
            && $staleEvicted === false
            && $Vault->read('aba') === 'same',
         description: 'unique envelopes prevent stale ABA eviction of a replacement record'
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

      $clean($CASPath);
      $clean($path);
   }
);
