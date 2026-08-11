<?php


use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Cache(Shared): expired counters release fixed segment capacity automatically',
   skip: extension_loaded('sysvshm') === false || extension_loaded('sysvsem') === false,
   test: function () {
      $Clock = new Clock(4_000_000);
      $recovering = false;
      $recoveryReads = 0;
      $Cache = new Cache([
         'driver' => 'shared',
         'prefix' => 'ratelimit:',
         'segment' => random_int(10_000_000, 2_000_000_000),
         'size' => 32_768,
         'clock' => static function () use (
            $Clock,
            &$recovering,
            &$recoveryReads,
         ): int {
            if ($recovering && ++$recoveryReads === 3) {
               // ! Model a reclaim that outlasts this short counter window.
               $Clock->advance(6);
            }

            return (int) $Clock->now;
         },
      ]);
      $Driver = $Cache->Driver;
      $keyA = 'b97186618aa1434e:12345';
      $keyB = '2f6843fd71907689:12345';

      try {
         $Cache->store($keyA, 'permanent-A');
         $Cache->store($keyB, 'permanent-B');
         yield assert(
            assertion: crc32("ratelimit:{$keyA}") === crc32("ratelimit:{$keyB}"),
            description: 'The rollback fixture stores two full keys in one CRC32 slot'
         );

         $successes = 0;
         $capacityFailed = false;
         for ($index = 0; $index < 64; $index++) {
            $key = str_pad("expired-{$index}-", 1_024, 'k');
            try {
               $Cache->increment($key, 1, 5);
               $successes++;
            }
            catch (RuntimeException $Exception) {
               $capacityFailed = str_contains(
                  $Exception->getMessage(),
                  'Shared-memory capacity exhausted while writing variable',
               );

               break;
            }
         }

         yield assert(
            assertion: $successes > 0 && $capacityFailed === true,
            description: 'The isolated segment reaches real capacity after successful counters'
         );

         // ! PHP 8.4 removes the whole old SysV slot before reporting that its
         //   replacement cannot fit. The driver must restore both full keys.
         $preserved = false;
         $replacementFailed = false;
         try {
            $Cache->store($keyA, str_repeat('x', 65_536));
         }
         catch (RuntimeException $Exception) {
            $replacementFailed = str_contains(
               $Exception->getMessage(),
               'Shared-memory capacity exhausted while writing variable',
            );
            $preserved = $Cache->fetch($keyA) === 'permanent-A'
               && $Cache->fetch($keyB) === 'permanent-B';
         }
         yield assert(
            assertion: $replacementFailed && $preserved,
            description: 'A destructive capacity failure preserves both colliding live values'
         );

         $Clock->advance(6);
         $recovering = true;
         $recoveryReads = 0;
         $recoveryKey = str_pad('recovered-', 1_024, 'r');
         $first = $Cache->increment($recoveryKey, 1, 5);
         $second = $Cache->increment($recoveryKey, 1, 5);
         $recovering = false;

         yield assert(
            assertion: $first === 1 && $second === 2,
            description: 'Recovery re-samples time and persists a live post-sweep counter'
         );
         $sentinels = [
            $Cache->fetch($keyA),
            $Cache->fetch($keyB),
         ];
         $purged = $Cache->purge();
         yield assert(
            assertion: $sentinels === ['permanent-A', 'permanent-B'],
            description: 'Automatic reclamation preserves permanent collision neighbors'
         );
         yield assert(
            assertion: $purged === 0,
            description: "Automatic reclamation leaves no expired records (purged {$purged})"
         );
      }
      finally {
         if ($Driver instanceof Shared) {
            $Driver->destroy();
         }
      }
   }
);
