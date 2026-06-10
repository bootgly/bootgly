<?php

use function extension_loaded;
use function function_exists;
use function pcntl_fork;
use function pcntl_waitpid;
use function random_int;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(Shared): atomic increment is shared across forked workers',
   skip: extension_loaded('sysvshm') === false
      || extension_loaded('sysvsem') === false
      || function_exists('pcntl_fork') === false,
   test: function () {
      $segment = random_int(200_000, 9_000_000);
      $config = [
         'driver' => 'shared',
         'prefix' => 'cw:',
         'segment' => $segment,
         'size' => 262_144,
      ];

      $Cache = new Cache($config);
      $Cache->clear();

      $rounds = 100;

      // @ Child worker increments the same shared counter
      $pid = pcntl_fork();
      if ($pid === 0) {
         $Worker = new Cache($config);
         for ($i = 0; $i < $rounds; $i++) {
            $Worker->increment('counter');
         }
         exit(0);
      }

      // @ Parent increments concurrently
      for ($i = 0; $i < $rounds; $i++) {
         $Cache->increment('counter');
      }

      pcntl_waitpid($pid, $status);

      $total = $Cache->fetch('counter');
      yield assert(
         assertion: $total === $rounds * 2,
         description: "Both workers' increments sum without loss (got {$total}, expected " . ($rounds * 2) . ')'
      );

      // @ Release the OS segment + semaphore
      $Driver = $Cache->Driver;
      if ($Driver instanceof Shared === true) {
         $Driver->destroy();
      }
   }
);
