<?php

use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Resources\Storage;
use Bootgly\ABI\Resources\Storage\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage: Written/Read/Deleted events dispatch through Emitter::$Instance with payload',
   test: function () {
      // ! Fresh bus — Storage uses the shared Emitter::$Instance, isolate the suite
      Emitter::$Instance = new Emitter();
      $Emitter = Emitter::$Instance;

      $written = [];
      $reads = [];
      $deletes = [];
      $Emitter->listen(Events::Written, function (Emission $Emission) use (&$written) {
         $written[] = $Emission->payload;
      });
      $Emitter->listen(Events::Read, function (Emission $Emission) use (&$reads) {
         $reads[] = $Emission->payload;
      });
      $Emitter->listen(Events::Deleted, function (Emission $Emission) use (&$deletes) {
         $deletes[] = $Emission->payload;
      });

      $root = sys_get_temp_dir() . '/bootgly-storage-events-' . uniqid();
      $Storage = new Storage(['disks' => ['local' => ['driver' => 'local', 'root' => $root]]]);

      // @ Written
      $Storage->write('k.txt', source('v'));
      yield assert(
         assertion: $written === [['k.txt', true]],
         description: 'Storage.Written fired once with [path, written]'
      );

      // @ Read (found)
      grab($Storage, 'k.txt');
      yield assert(
         assertion: $reads === [['k.txt', true]],
         description: 'Storage.Read fired with [path, found=true]'
      );

      // @ Read (miss)
      grab($Storage, 'absent.txt');
      yield assert(
         assertion: $reads === [['k.txt', true], ['absent.txt', false]],
         description: 'Storage.Read fires with found=false on a miss'
      );

      // @ Deleted
      $deleted = $Storage->delete('k.txt');
      yield assert(
         assertion: $deleted === true && $deletes === [['k.txt', true]],
         description: 'Storage.Deleted fired once with [path, deleted]'
      );

      // ! Restore a clean bus for any later suite using the shared instance
      Emitter::$Instance = new Emitter();

      $Storage->clear();
   }
);
