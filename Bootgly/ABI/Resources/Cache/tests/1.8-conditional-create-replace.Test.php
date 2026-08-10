<?php

use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Cache atomic create/swap/evict/renew across File, Memory and Shared',
   test: function () {
      $root = sys_get_temp_dir() . '/bootgly-cache-conditional-' . bin2hex(random_bytes(12));
      $prefix = 'conditional-' . bin2hex(random_bytes(8)) . ':';
      $results = [];
      $SharedCache = null;

      $Cleanup = null;
      $Cleanup = static function (string $path) use (&$Cleanup): void {
         if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
         }
         if (is_dir($path) === false) {
            return;
         }

         foreach (@scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            $Cleanup($path . DIRECTORY_SEPARATOR . $entry);
         }
         @rmdir($path);
      };

      /**
       * @return array<string,bool>
       */
      $Exercise = static function (Cache $Primary, Cache $Peer, Clock $Clock): array {
         $key = 'record';
         $expiryKey = 'expiring';

         $Primary->delete($key);
         $swapMissing = $Peer->swap($key, 'missing', 'unexpected') === false;
         $evictMissing = $Peer->evict($key, 'missing') === false;
         $renewMissing = $Peer->renew($key, 10) === false;
         $missingStayedAbsent = $Primary->fetch($key) === null;

         $firstCreate = $Primary->create($key, 'created') === true;
         $firstValue = $Peer->fetch($key) === 'created';
         $duplicateCreate = $Peer->create(
            $key,
            'clobbered',
            tags: ['rejected-create'],
         ) === false;
         $Peer->invalidate('rejected-create');
         $duplicatePreserved = $Primary->fetch($key) === 'created';

         $staleSwap = $Peer->swap(
            $key,
            'stale',
            'clobbered',
            tags: ['rejected-swap'],
         ) === false;
         $Peer->invalidate('rejected-swap');
         $staleSwapPreserved = $Primary->fetch($key) === 'created';
         $liveSwap = $Peer->swap($key, 'created', 'replaced') === true;
         $swappedVisible = $Primary->fetch($key) === 'replaced';

         $staleEvict = $Primary->evict($key, 'created') === false;
         $staleEvictPreserved = $Peer->fetch($key) === 'replaced';
         $evicted = $Primary->evict($key, 'replaced') === true;
         $swapEvicted = $Peer->swap($key, 'replaced', 'resurrected') === false;
         $deletedStayedAbsent = $Primary->fetch($key) === null;
         $recreateDeleted = $Primary->create($key, 'fresh') === true
            && $Peer->fetch($key) === 'fresh';

         $Primary->delete($expiryKey);
         $expiringCreated = $Primary->create($expiryKey, 'short-lived', 5) === true;
         $Clock->advance(3);
         $renewed = $Peer->renew($expiryKey, 10) === true;
         $renewPreserved = $Primary->fetch($expiryKey) === 'short-lived';
         $Clock->advance(3);
         $survivedOriginalExpiry = $Primary->fetch($expiryKey) === 'short-lived';
         $Clock->advance(8);
         $swapExpired = $Peer->swap($expiryKey, 'short-lived', 'resurrected') === false;
         $evictExpired = $Peer->evict($expiryKey, 'short-lived') === false;
         $expiredStayedAbsent = $Primary->fetch($expiryKey) === null;
         $recreateExpired = $Primary->create($expiryKey, 'renewed') === true
            && $Peer->fetch($expiryKey) === 'renewed';

         $Primary->clear();

         return [
            'missing_refused' => $swapMissing && $evictMissing && $renewMissing
               && $missingStayedAbsent,
            'exclusive_create' => $firstCreate && $firstValue
               && $duplicateCreate && $duplicatePreserved,
            'exact_swap' => $staleSwap && $staleSwapPreserved
               && $liveSwap && $swappedVisible,
            'exact_evict' => $staleEvict && $staleEvictPreserved
               && $evicted && $swapEvicted && $deletedStayedAbsent && $recreateDeleted,
            'renew' => $expiringCreated && $renewed && $renewPreserved
               && $survivedOriginalExpiry,
            'expired_refused' => $swapExpired && $evictExpired
               && $expiredStayedAbsent && $recreateExpired,
         ];
      };

      try {
         $FileClock = new Clock(1_000_000);
         $fileConfig = [
            'driver' => 'file',
            'path' => $root . '/file',
            'prefix' => $prefix,
            'clock' => static fn (): int => (int) $FileClock->now,
         ];
         $FileCache = new Cache($fileConfig);
         $FilePeer = new Cache($fileConfig);
         $results['File'] = $Exercise($FileCache, $FilePeer, $FileClock);

         $MemoryClock = new Clock(1_000_000);
         $MemoryCache = new Cache([
            'driver' => 'memory',
            'prefix' => $prefix,
            'clock' => static fn (): int => (int) $MemoryClock->now,
         ]);
         $results['Memory'] = $Exercise($MemoryCache, $MemoryCache, $MemoryClock);

         if (
            extension_loaded('sysvshm')
            && extension_loaded('sysvsem')
         ) {
            $SharedClock = new Clock(1_000_000);
            $sharedConfig = [
               'driver' => 'shared',
               'prefix' => $prefix,
               'segment' => random_int(200_000, 9_000_000),
               'size' => 262_144,
               'clock' => static fn (): int => (int) $SharedClock->now,
            ];
            $SharedCache = new Cache($sharedConfig);
            $SharedPeer = new Cache($sharedConfig);
            $results['Shared'] = $Exercise($SharedCache, $SharedPeer, $SharedClock);
         }

         foreach ($results as $backend => $result) {
            yield assert(
               assertion: $result['missing_refused'],
               description: "Cache({$backend}) swap()/evict()/renew() refuse a missing key"
            );
            yield assert(
               assertion: $result['exclusive_create'],
               description: "Cache({$backend}) create() is exclusive and preserves the first live value"
            );
            yield assert(
               assertion: $result['exact_swap'],
               description: "Cache({$backend}) swap() requires the exact live value"
            );
            yield assert(
               assertion: $result['exact_evict'],
               description: "Cache({$backend}) evict() compares exactly and swap() cannot resurrect it"
            );
            yield assert(
               assertion: $result['renew'],
               description: "Cache({$backend}) renew() extends TTL without changing the value"
            );
            yield assert(
               assertion: $result['expired_refused'],
               description: "Cache({$backend}) treats expired keys as missing for swap()/evict()"
            );
         }
      }
      finally {
         if ($SharedCache instanceof Cache && $SharedCache->Driver instanceof Shared) {
            $SharedCache->Driver->destroy();
         }
         $Cleanup($root);
      }
   }
);
