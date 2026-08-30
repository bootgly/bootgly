<?php

use Bootgly\ACI\Logs\Backlog;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Backlog::scan() lists log files with rotations ordered oldest-first before their active file',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-backlog-scan-' . uniqid();
      mkdir($dir, 0o775, true);

      // ! Two channels, one rotated
      file_put_contents("$dir/App.log", '');
      file_put_contents("$dir/App.log.1", '');
      file_put_contents("$dir/App.log.3", '');
      file_put_contents("$dir/Web.log", '');

      $Backlog = new Backlog($dir);
      $files = array_map('basename', $Backlog->scan());

      yield assert(
         assertion: $files === ['App.log.3', 'App.log.1', 'App.log', 'Web.log'],
         description: 'rotations come oldest-first (highest suffix) before the active file — got '
            . implode(', ', $files)
      );

      // # --since bounds rotation reads by mtime
      touch("$dir/App.log.3", time() - 86400);
      $bounded = array_map('basename', $Backlog->scan(since: time() - 3600));
      yield assert(
         assertion: in_array('App.log.3', $bounded, true) === false
            && in_array('App.log.1', $bounded, true) === true,
         description: 'a rotation finished before the cutoff is skipped; fresher ones stay'
      );

      // # Rotations excluded on demand
      $plain = array_map('basename', (new Backlog($dir, rotations: false))->scan());
      yield assert(
         assertion: $plain === ['App.log', 'Web.log'],
         description: 'rotations: false lists only active files'
      );

      // @ Cleanup
      foreach ((array) glob("$dir/*") as $file) {
         @unlink((string) $file);
      }
      @rmdir($dir);
   }
);
