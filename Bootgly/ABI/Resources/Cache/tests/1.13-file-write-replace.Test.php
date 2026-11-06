<?php

use function Bootgly\ABI\remove_recursively;
use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Cache(File): records are written through File->replace(): plain-write mode, lazy shards, link replaced, no temporary left',
   test: function () {
      // ! The permission bits of an entry
      $mode = static function (string $path): int {
         clearstatcache(true);

         return (int) fileperms($path) & 0777;
      };
      // ! Every entry of a directory but the dot entries
      $list = static function (string $directory): array {
         clearstatcache(true);
         $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
         sort($entries);

         return $entries;
      };
      // ! The one record file of the cache
      $locate = static function (string $directory): string {
         $records = glob("{$directory}/*/*.cache") ?: [];

         return count($records) === 1 ? $records[0] : '';
      };

      $directory = Temporaries::reserve('cache-file-write-replace');
      $previous = umask(0027);
      try {
         $path = "{$directory}/cache";
         $Cache = new Cache(['driver' => 'file', 'path' => $path]);

         // @ The first store creates the shard lazily and gets the plain-write mode
         $stored = $Cache->store('key', 'value');
         $record = $locate($path);
         yield assert(
            assertion: $stored === true && $record !== '' && $mode($record) === 0640,
            description: 'store() creates the shard lazily and writes the record with 0666 minus the umask (0640 under 0027), got: '
               . ($record !== '' ? decoct($mode($record)) : 'no record')
         );
         $shard = dirname($record);
         yield assert(
            assertion: $list($shard) === [basename($record)] && $Cache->fetch('key') === 'value',
            description: 'no temporary is left beside the record, got: ' . json_encode($list($shard))
         );

         // @ Rewrites (store, swap, renew) replace the record with the same mode
         $Cache->store('key', 'second', 60);
         $swapped = $Cache->swap('key', 'second', 'third', 60);
         $renewed = $Cache->renew('key', 120);
         yield assert(
            assertion: $swapped === true && $renewed === true && $Cache->fetch('key') === 'third'
               && $mode($record) === 0640 && $list($shard) === [basename($record)],
            description: 'store(), swap() and renew() replace the record in place, with no temporary left'
         );

         // @ A link planted at the record's name is replaced, never written through
         $victim = "{$directory}/victim.txt";
         file_put_contents($victim, 'untouched');
         unlink($record);
         symlink($victim, $record);
         $replaced = $Cache->store('key', 'linked');
         clearstatcache(true);
         yield assert(
            assertion: $replaced === true && is_link($record) === false
               && file_get_contents($victim) === 'untouched' && $Cache->fetch('key') === 'linked',
            description: 'a symlink at the record path is replaced by the record, its target is left untouched'
         );

         // @ A shard that cannot be a directory refuses the write
         $Cache->clear();
         remove_recursively($shard);
         file_put_contents($shard, '');
         yield assert(
            assertion: $Cache->store('key', 'blocked') === false && $Cache->fetch('key') === null,
            description: 'store() answers false when the shard directory cannot be created'
         );
      }
      finally {
         umask($previous);
         remove_recursively($directory);
      }

      yield assert(
         assertion: is_dir($directory) === false,
         description: 'the temporary directory is cleaned up'
      );
   }
);
