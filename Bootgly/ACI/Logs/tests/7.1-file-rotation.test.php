<?php

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers\File;
use Bootgly\ACI\Logs\Handlers\File\Rotation;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'File handler rotates on size cap and on day change, keeping at most N archives',
   test: function () {
      $saved = Display::$segments;
      Display::show(Display::MESSAGE);

      // # Size-based rotation, keep 3
      $sizeDir = sys_get_temp_dir() . '/bootgly-logtest-size-' . uniqid();
      $sizePath = "$sizeDir/app.log";

      $Logger = new Logger('f');
      $Logger->Handlers = new Handlers;
      $Logger->Handlers->push(new File($sizePath, Rotation: new Rotation(size: 50, daily: false, keep: 3)));

      $Logger->log(info: str_repeat('a', 80));
      yield assert(
         assertion: is_file($sizePath),
         description: 'the active log file is created on first write'
      );

      $Logger->log(info: str_repeat('b', 80)); // file now exceeds 50B → rotate before append
      yield assert(
         assertion: is_file("$sizePath.1"),
         description: 'exceeding the size cap rotates the active file to .1'
      );

      for ($i = 0; $i < 6; $i++) {
         $Logger->log(info: str_repeat('c', 80));
      }
      yield assert(
         assertion: is_file("$sizePath.3") && is_file("$sizePath.4") === false,
         description: 'keep=3 retains .1..3 and drops older archives'
      );

      // # Daily rotation (size disabled)
      $dayDir = sys_get_temp_dir() . '/bootgly-logtest-day-' . uniqid();
      $dayPath = "$dayDir/d.log";

      $Daily = new Logger('f');
      $Daily->Handlers = new Handlers;
      $Daily->Handlers->push(new File($dayPath, Rotation: new Rotation(size: 0, daily: true, keep: 3)));

      $Daily->log(info: 'day1');
      touch($dayPath, time() - 90000); // backdate to "yesterday"
      $Daily->log(info: 'day2');

      yield assert(
         assertion: is_file("$dayPath.1") && str_contains((string) file_get_contents("$dayPath.1"), 'day1'),
         description: 'a day change rotates the previous day to .1'
      );
      yield assert(
         assertion: str_contains((string) file_get_contents($dayPath), 'day2'),
         description: 'the active file holds the new day'
      );

      // @ Cleanup
      foreach ([$sizeDir, $dayDir] as $dir) {
         foreach ((array) glob("$dir/*") as $file) {
            @unlink((string) $file);
         }
         @rmdir($dir);
      }

      Display::show($saved);
   }
);
