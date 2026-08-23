<?php


use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * Stand-in for the in-tree gadget chain. The static counter is the assertion
 * that matters: `shm_get_var()` and `apcu_fetch()` reconstruct whatever the
 * store holds before any driver code runs, so a record REJECTED after the fact
 * is not a defence — the question is whether the class was built at all.
 */
class CacheGadget_7_4
{
   public static int $fired = 0;

   public string $target = '';


   public function __destruct ()
   {
      self::$fired++;

      if ($this->target !== '' && is_file($this->target) === true) {
         unlink($this->target);
      }
   }
}


$shared = extension_loaded('sysvshm') && extension_loaded('sysvsem');
$apcu = function_exists('apcu_enabled') && apcu_enabled();

return new Test(
   description: 'Cache(Shared/APCu): the extension drivers refuse an undeclared class too',
   skip: $shared === false && $apcu === false,

   test: function () use ($shared, $apcu) {
      $probe = static function (Cache $Cache, string $key): array {
         $victim = sys_get_temp_dir() . '/bootgly-ext-' . uniqid('', true) . '.probe';
         file_put_contents($victim, 'SECRET');

         $Gadget = new CacheGadget_7_4;
         $Gadget->target = $victim;
         $Cache->store($key, $Gadget);
         // ! Disarm the local instance so its own destructor is never the one measured
         $Gadget->target = '';
         unset($Gadget);
         CacheGadget_7_4::$fired = 0;

         $value = $Cache->fetch($key);
         $object = is_object($value);
         unset($value);
         gc_collect_cycles();

         $alive = is_file($victim);
         @unlink($victim);

         return ['object' => $object, 'constructed' => CacheGadget_7_4::$fired, 'victim' => $alive];
      };

      if ($shared === true) {
         // ! A per-run key: a fixed one leaks a segment between runs and makes
         //   the spec fail hard the moment anything else holds it
         $segment = 1_600_000_000 + (crc32(uniqid('', true)) % 100_000_000);

         $Shared = new Cache([
            'driver' => 'shared',
            'segment' => $segment,
            'size' => 2 * 1024 * 1024,
            'prefix' => 'x' . uniqid('', true) . ':',
         ]);

         $observed = $probe($Shared, 'g');
         yield assert(
            assertion: $observed['object'] === false
               && $observed['constructed'] === 0
               && $observed['victim'] === true,
            description: 'Shared never reconstructs an undeclared class: ' . var_export($observed, true)
         );

         // ? Declared classes still round-trip, and so do the shapes the two
         //   in-tree consumers actually store
         $Open = new Cache([
            'driver' => 'shared',
            'segment' => $segment,
            'size' => 2 * 1024 * 1024,
            'prefix' => 'y' . uniqid('', true) . ':',
            'classes' => [CacheGadget_7_4::class],
         ]);
         $Open->store('e', new CacheGadget_7_4);

         yield assert(
            assertion: $Open->fetch('e') instanceof CacheGadget_7_4
               && $Open->increment('n') === 1
               && $Open->increment('n', 2) === 3
               && $Open->store('s', 'plain') === true
               && $Open->fetch('s') === 'plain'
               && $Open->fetch('missing') === null,
            description: 'Shared still round-trips declared objects, counters and scalars'
         );

         // @ The shape the driver genuinely CANNOT defend against: a raw
         //   live-value plant into an already-marked segment. shm_get_var()
         //   rebuilds it before any code here runs, so the canary DOES die —
         //   what the allow-list still buys is that the object never reaches
         //   the caller. Pinning the real limit here keeps a future reader from
         //   believing the stronger claim.
         $victim = sys_get_temp_dir() . '/bootgly-ext-raw-' . uniqid('', true) . '.probe';
         file_put_contents($victim, 'SECRET');
         $Planted = new CacheGadget_7_4;
         $Planted->target = $victim;
         $Segment = shm_attach($segment, 2 * 1024 * 1024, 0600);
         shm_put_var($Segment, crc32("{$Shared->Config->prefix}raw"), [
            'k' => "{$Shared->Config->prefix}raw",
            'e' => 0,
            'v' => $Planted,
         ]);
         $Planted->target = '';
         unset($Planted);
         CacheGadget_7_4::$fired = 0;

         $handed = $Shared->fetch('raw');

         yield assert(
            assertion: $handed === null,
            description: 'A raw live-value plant is never handed to the caller, '
               . 'though shm_get_var() has already built it: constructed='
               . CacheGadget_7_4::$fired
         );

         @unlink($victim);
         shm_detach($Segment);

         $Shared->clear();
         $Open->clear();

         // ! Both caches share the key, so one destroy() releases the segment
         //   and its semaphore rather than leaving them for the next run
         $Shared->Driver->destroy();
      }

      if ($apcu === true) {
         $APCu = new Cache(['driver' => 'apcu', 'prefix' => 'x' . uniqid('', true) . ':']);

         $observed = $probe($APCu, 'g');
         yield assert(
            assertion: $observed['object'] === false
               && $observed['constructed'] === 0
               && $observed['victim'] === true,
            description: 'APCu never reconstructs an undeclared class: ' . var_export($observed, true)
         );

         $Open = new Cache([
            'driver' => 'apcu',
            'prefix' => 'y' . uniqid('', true) . ':',
            'classes' => [CacheGadget_7_4::class],
         ]);
         $Open->store('e', new CacheGadget_7_4);

         yield assert(
            assertion: $Open->fetch('e') instanceof CacheGadget_7_4
               && $Open->increment('n') === 1
               && $Open->increment('n', 2) === 3
               && $Open->store('s', 'plain') === true
               && $Open->fetch('s') === 'plain'
               && $Open->fetch('missing') === null,
            description: 'APCu still round-trips declared objects, counters and scalars'
         );

         $APCu->clear();
         $Open->clear();
      }
   }
);
