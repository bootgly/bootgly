<?php

use function Bootgly\ABI\remove_recursively;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Observability\Exporters\JSON;
use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'dump() replaces the snapshot through File->replace(): plain-write mode, link replaced, no temporary left',
   test: function () {
      // ! The permission bits of an entry
      $mode = static function (string $path): int {
         clearstatcache(true);

         return (int) fileperms($path) & 0777;
      };
      $directory = Temporaries::reserve('observability-dump-replace');
      $previous = umask(0027);

      try {
         $Observability = new Observability(collectors: false);
         $Counter = new Counter(name: 'http_requests_total', labels: ['method' => 'GET']);
         $Counter->increment(by: 7);
         $Observability->Metrics->push($Counter);

         // @ A fresh snapshot gets the mode a plain write gives under the umask
         $file = "{$directory}/metrics/worker-1.json";
         $written = $Observability->dump(new JSON, $file);
         yield assert(
            assertion: $written === true && $mode($file) === 0640,
            description: 'a fresh snapshot is written with 0666 minus the umask (0640 under 0027), got: '
               . decoct($mode($file))
         );

         // @ A snapshot already on disk is replaced by a new inode
         $inode = fileinode($file);
         $Counter->increment(by: 1);
         $rewritten = $Observability->dump(new JSON, $file);
         clearstatcache(true);
         $Snapshot = json_decode((string) file_get_contents($file), true);
         yield assert(
            assertion: $rewritten === true
               && fileinode($file) !== $inode
               && is_array($Snapshot)
               && str_contains((string) file_get_contents($file), '"value":8'),
            description: 'an existing snapshot is swapped for a new inode holding the new value'
         );

         // @ A link planted at the snapshot path is replaced, never written through
         $victim = "{$directory}/victim.txt";
         file_put_contents($victim, 'untouched');
         $linked = "{$directory}/metrics/worker-2.json";
         symlink($victim, $linked);
         $replaced = $Observability->dump(new JSON, $linked);
         clearstatcache(true);
         yield assert(
            assertion: $replaced === true
               && is_link($linked) === false
               && file_get_contents($victim) === 'untouched'
               && str_contains((string) file_get_contents($linked), 'http_requests_total'),
            description: 'a symlink at the snapshot path is replaced by the snapshot, its target is left untouched'
         );

         // @ No temporary survives a dump
         $entries = array_values(array_diff(scandir("{$directory}/metrics") ?: [], ['.', '..']));
         sort($entries);
         yield assert(
            assertion: $entries === ['worker-1.json', 'worker-2.json'],
            description: 'only the snapshots remain beside each other, got: ' . json_encode($entries)
         );

         // @ A directory that cannot be created leaves dump() false
         file_put_contents("{$directory}/blocked", '');
         $refused = $Observability->dump(new JSON, "{$directory}/blocked/worker-3.json");
         yield assert(
            assertion: $refused === false && file_exists("{$directory}/blocked/worker-3.json") === false,
            description: 'dump() answers false when the destination directory cannot exist'
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
