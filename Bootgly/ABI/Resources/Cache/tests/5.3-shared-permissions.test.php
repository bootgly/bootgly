<?php

use function extension_loaded;
use function is_file;
use function random_int;
use function sem_get;
use function sem_remove;
use function shm_attach;
use function shm_remove;
use function str_contains;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(Shared): rejects pre-existing SysV objects with unexpected permissions',
   skip: extension_loaded('sysvshm') === false
      || extension_loaded('sysvsem') === false
      || is_file('/proc/sysvipc/shm') === false
      || is_file('/proc/sysvipc/sem') === false,

   test: function () {
      $Find = static function (string $path, int $key): bool {
         foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ((int) trim($line) === $key) {
               return true;
            }
         }

         return false;
      };

      do {
         $Keys = [
            random_int(0x20000000, 0x6fffffff),
            random_int(0x20000000, 0x6fffffff),
         ];
      }
      while (
         $Keys[0] === $Keys[1]
         || $Find('/proc/sysvipc/shm', $Keys[0])
         || $Find('/proc/sysvipc/sem', $Keys[0])
         || $Find('/proc/sysvipc/shm', $Keys[1])
         || $Find('/proc/sysvipc/sem', $Keys[1])
      );

      $Segments = [];
      $Semaphores = [];
      $SHMMessage = '';
      $SEMMessage = '';

      try {
         // # First key: shared memory itself is world-writable.
         $Segments[0] = shm_attach($Keys[0], 262_144, 0666);
         $Semaphores[0] = sem_get($Keys[0], 1, 0666, true);

         try {
            $Cache = new Cache([
               'driver' => 'shared',
               'segment' => $Keys[0],
               'size' => 262_144,
               'permissions' => 0600,
            ]);
            $Cache->fetch('probe');
         }
         catch (RuntimeException $Exception) {
            $SHMMessage = $Exception->getMessage();
         }

         // # Second key: shared memory is private, but its semaphore is not.
         $Segments[1] = shm_attach($Keys[1], 262_144, 0600);
         $Semaphores[1] = sem_get($Keys[1], 1, 0666, true);

         try {
            $Cache = new Cache([
               'driver' => 'shared',
               'segment' => $Keys[1],
               'size' => 262_144,
               'permissions' => 0600,
            ]);
            $Cache->fetch('probe');
         }
         catch (RuntimeException $Exception) {
            $SEMMessage = $Exception->getMessage();
         }
      }
      finally {
         foreach ($Segments as $Segment) {
            shm_remove($Segment);
         }
         foreach ($Semaphores as $Semaphore) {
            sem_remove($Semaphore);
         }
      }

      yield assert(
         assertion: str_contains($SHMMessage, 'SysV shm')
            && str_contains($SHMMessage, 'unexpected owner or permissions'),
         description: 'Shared refuses a pre-existing world-writable memory segment: '
            . $SHMMessage
      );
      yield assert(
         assertion: str_contains($SEMMessage, 'SysV sem')
            && str_contains($SEMMessage, 'unexpected owner or permissions'),
         description: 'Shared refuses a pre-existing world-writable semaphore: '
            . $SEMMessage
      );
   }
);
