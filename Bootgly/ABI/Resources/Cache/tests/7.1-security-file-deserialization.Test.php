<?php


use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Item;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * Stand-in for the in-tree gadget chain — a `__destruct()` that reaches
 * `unlink()`. It exists so the case can assert the class was never CONSTRUCTED,
 * which is the property being defended. Asserting the read returned a miss
 * would pass before the fix too: the record was always rejected, the destructor
 * had simply already run by then.
 */
class CacheGadget_7_1
{
   public static int $fired = 0;

   public string $target = '';


   public function __destruct ()
   {
      self::$fired++;

      if (is_file($this->target) === true) {
         unlink($this->target);
      }
   }
}


return new Test(
   description: 'Cache(File): a planted gadget record is refused by every read sink',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-cache-sec-' . uniqid('', true);
      $prefix = 's:';
      $Cache = new Cache(['driver' => 'file', 'path' => $dir, 'prefix' => $prefix]);

      // ! The same layout File::locate() computes: <path>/<2-char shard>/<xxh3>.cache
      $key = "{$prefix}gadget";
      $hash = hash('xxh3', $key);
      $file = "{$dir}/" . substr($hash, 0, 2) . "/{$hash}.cache";
      mkdir("{$dir}/" . substr($hash, 0, 2), 0775, true);

      $victim = "{$dir}/victim.probe";

      // ! Bytes taken from a live instance, which is then disarmed so its own
      //   destructor can never be mistaken for one a cache read triggered
      $Gadget = new CacheGadget_7_1;
      $Gadget->target = $victim;
      $blob = serialize(['key' => $key, 'Item' => $Gadget]);
      $Gadget->target = '';
      unset($Gadget);

      $plant = static function () use ($file, $blob, $victim): void {
         file_put_contents($victim, 'SECRET');
         file_put_contents($file, $blob);
         CacheGadget_7_1::$fired = 0;
      };

      $plant();
      $value = $Cache->fetch('gadget');
      yield assert(
         assertion: $value === null
            && CacheGadget_7_1::$fired === 0
            && is_file($victim) === true,
         description: 'fetch() reads a miss and never constructs the planted class'
      );

      $plant();
      yield assert(
         assertion: $Cache->check('gadget') === false
            && CacheGadget_7_1::$fired === 0
            && is_file($victim) === true,
         description: 'check() reads a miss and never constructs the planted class'
      );

      $plant();
      yield assert(
         assertion: $Cache->remain('gadget') === -2
            && CacheGadget_7_1::$fired === 0
            && is_file($victim) === true,
         description: 'remain() reports the record missing and never constructs it'
      );

      // ? purge() is the weakest sink — it never knew which key the file holds
      $plant();
      yield assert(
         assertion: $Cache->purge() === 0
            && CacheGadget_7_1::$fired === 0
            && is_file($victim) === true,
         description: 'purge() walks past the planted file without constructing it'
      );

      // ? increment() reads under flock, then overwrites the planted record
      $plant();
      yield assert(
         assertion: $Cache->increment('gadget') === 1
            && CacheGadget_7_1::$fired === 0
            && is_file($victim) === true,
         description: 'increment() ignores the planted record and starts a fresh counter'
      );

      // ! Item is the one class the allow-list still admits, so a forged one is
      //   the remaining way in: neither a value its property cannot hold nor a
      //   missing property may turn a read into a raise
      $record = serialize(['key' => $key, 'Item' => new Item(null, 0, [])]);

      file_put_contents($file, str_replace('s:6:"expiry";i:0;', 's:6:"expiry";s:3:"abc";', $record));
      yield assert(
         assertion: $Cache->fetch('gadget') === null && $Cache->remain('gadget') === -2,
         description: 'A forged Item carrying a value its property cannot hold reads as a miss'
      );

      $class = Item::class;
      file_put_contents($file, str_replace(
         serialize(new Item(null, 0, [])),
         'O:' . strlen($class) . ":\"{$class}\":0:{}",
         $record
      ));
      yield assert(
         assertion: $Cache->remain('gadget') === -1 && $Cache->fetch('gadget') === null,
         description: 'A forged Item with no properties reads through its defaults, never uninitialized'
      );

      $Cache->clear();
   }
);
