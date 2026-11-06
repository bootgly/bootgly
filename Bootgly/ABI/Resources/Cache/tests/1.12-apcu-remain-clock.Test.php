<?php


use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Cache(APCu): remain() measures on real time, the clock APCu expires on (requires ext-apcu with apc.enable_cli)',
   skip: extension_loaded('apcu') === false || apcu_enabled() === false,
   test: function () {
      // ! A Config Clock far from real time: APCu ignores it when it expires
      //   entries, so a remainder measured on it would be off by decades
      $Clock = new Clock(2_000_000);
      $id = uniqid('', true);
      $Cache = new Cache([
         'driver' => 'apcu',
         'prefix' => "apcu-clock:{$id}:",
         'clock' => $Clock,
      ]);

      try {
         // @
         $Cache->store('k', 'v', 30);
         $remain = $Cache->remain('k');
         yield assert(
            assertion: $remain >= 29 && $remain <= 30,
            description: "remain() is the real remainder of the TTL, not one measured on the Config Clock (got {$remain})"
         );

         $Clock->advance(1_000_000_000);
         $remain = $Cache->remain('k');
         yield assert(
            assertion: $remain >= 29 && $remain <= 30 && $Cache->fetch('k') === 'v',
            description: "moving the Config Clock moves neither the remainder nor the expiry (got {$remain})"
         );

         $Cache->store('f', 'v');
         yield assert(
            assertion: $Cache->remain('f') === -1 && $Cache->remain('missing') === -2,
            description: 'no expiry reads -1 and a missing key -2, as before'
         );
      }
      finally {
         // ! Release
         $Cache->clear();
      }
   }
);
