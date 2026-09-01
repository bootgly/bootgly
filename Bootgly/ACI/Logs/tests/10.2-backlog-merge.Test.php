<?php

use Bootgly\ACI\Logs\Backlog;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Backlog::read() merges files ascending by timestamp, skipping malformed and oversized lines',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-backlog-merge-' . uniqid();
      mkdir($dir, 0o775, true);

      $line = static function (float $timestamp, string $channel, string $message, null|string $project = null, null|string $instance = null): string {
         $data = [
            'timestamp' => $timestamp,
            'level' => 'INFO',
            'channel' => $channel,
            'message' => $message,
            'context' => [],
            'extra' => [],
         ];
         if ($project !== null) {
            $data['project'] = $project;
         }
         if ($instance !== null) {
            $data['instance'] = $instance;
         }
         return json_encode($data) . "\n";
      };

      // ! Interleaved timestamps across two files + noise
      file_put_contents(
         "$dir/A.log",
         $line(10.0, 'A', 'first')
            . "not-json\n"
            . $line(30.0, 'A', 'third', 'Alpha', '8443')
      );
      file_put_contents(
         "$dir/B.log",
         $line(20.0, 'B', 'second')
            . str_repeat('x', Backlog::MAX_LINE_BYTES + 128) . "\n"
            . $line(40.0, 'B', 'fourth')
      );

      $Backlog = new Backlog($dir);
      $Records = iterator_to_array($Backlog->read(), false);
      $messages = array_map(static fn ($Record) => $Record->message, $Records);

      yield assert(
         assertion: $messages === ['first', 'second', 'third', 'fourth'],
         description: 'records merge ascending by timestamp across files — got ' . implode(', ', $messages)
      );

      yield assert(
         assertion: $Records[0]->project === 'framework' && $Records[2]->project === 'Alpha',
         description: 'legacy lines import as framework; stamped lines keep their provenance'
      );

      yield assert(
         assertion: $Records[0]->instance === '' && $Records[2]->instance === '8443',
         description: 'legacy lines import with an empty instance; stamped lines keep theirs'
      );

      // # --since bounds by record timestamp
      $recent = array_map(
         static fn ($Record) => $Record->message,
         iterator_to_array($Backlog->read(since: 25.0), false)
      );
      yield assert(
         assertion: $recent === ['third', 'fourth'],
         description: '--since keeps only records stamped at or after the cutoff'
      );

      // @ Cleanup
      foreach ((array) glob("$dir/*") as $file) {
         @unlink((string) $file);
      }
      @rmdir($dir);
   }
);
