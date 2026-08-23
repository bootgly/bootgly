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
         $Shared = new Cache([
            'driver' => 'shared',
            'segment' => 1589239861,
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
            'segment' => 1589239861,
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

         $Shared->clear();
         $Open->clear();
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
