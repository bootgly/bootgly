<?php

use Bootgly\ACI\Logs\Backlog;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Backlog::following() yields only appended NDJSON, surviving rotation and new files',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-backlog-follow-' . uniqid();
      mkdir($dir, 0o775, true);
      file_put_contents("$dir/App.log", "{\"message\":\"old\"}\n");

      $Backlog = new Backlog($dir);
      $Following = $Backlog->following();

      // # Pre-existing content never flows
      $chunk = $Following->current();
      yield assert(
         assertion: $chunk === '',
         description: 'the first cycle yields nothing — following starts at each current end'
      );

      // # Appended bytes flow on the next cycle
      file_put_contents("$dir/App.log", "{\"message\":\"new\"}\n", FILE_APPEND);
      $Following->next();
      $chunk = (string) $Following->current();
      yield assert(
         assertion: str_contains($chunk, '"new"') && str_contains($chunk, '"old"') === false,
         description: 'only content appended after the start is yielded'
      );

      // # A file created mid-follow is picked up in full
      file_put_contents("$dir/Web.log", "{\"message\":\"fresh-channel\"}\n");
      $Following->next();
      $chunk = (string) $Following->current();
      yield assert(
         assertion: str_contains($chunk, '"fresh-channel"'),
         description: 'a new channel file appearing mid-follow is followed from its start'
      );

      // # Rotation (inode swap) reopens the active file at zero
      rename("$dir/App.log", "$dir/App.log.1");
      file_put_contents("$dir/App.log", "{\"message\":\"post-rotate\"}\n");
      $Following->next();
      $chunk = (string) $Following->current();
      yield assert(
         assertion: str_contains($chunk, '"post-rotate"'),
         description: 'an inode change (rotation) reopens the file and delivers its new content'
      );

      // @ Cleanup
      foreach ((array) glob("$dir/*") as $file) {
         @unlink((string) $file);
      }
      @rmdir($dir);
   }
);
