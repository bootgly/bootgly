<?php

namespace Bootgly\commands;


use const FILE_APPEND;
use function assert;
use function file_put_contents;
use function glob;
use function json_encode;
use function microtime;
use function mkdir;
use function rmdir;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use ReflectionMethod;

use Bootgly\ACI\Logs\Backlog;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: '`bootgly logs -f` file lane: the follow source yields only appended, filter-passing lines',
   test: function () {
      // ! Fixture logs directory with pre-existing content
      $dir = sys_get_temp_dir() . '/bootgly-logsfollow-' . uniqid();
      mkdir($dir, 0o775, true);

      $line = static function (string $level, string $message, null|string $instance = null): string {
         $data = [
            'timestamp' => microtime(true), 'level' => $level, 'project' => 'framework',
            'channel' => 'App', 'message' => $message, 'context' => [], 'extra' => [],
         ];
         // ? A legacy line (written before the field existed) carries no instance key
         if ($instance !== null) {
            $data['instance'] = $instance;
         }
         return json_encode($data) . "\n";
      };
      file_put_contents("$dir/App.log", $line('INFO', 'pre-existing'));

      // ! The command's follow source, filtered to ERROR+
      $Command = new LogsCommand;
      $Command->directory = $dir;

      $Sieve = new ReflectionMethod(LogsCommand::class, 'sieve');
      $Filters = $Sieve->invoke($Command, ['level' => 'error']);

      $Source = new ReflectionMethod(LogsCommand::class, 'source');
      $Following = $Source->invoke($Command, new Backlog($dir), $Filters);

      // # Nothing pre-existing flows
      yield assert(
         assertion: $Following->current() === '',
         description: 'the first cycle yields nothing — only appended content follows'
      );

      // # Appended lines flow, filtered
      file_put_contents("$dir/App.log", $line('INFO', 'ignored-info') . $line('ERROR', 'kept-error'), FILE_APPEND);
      $Following->next();
      $chunk = (string) $Following->current();
      yield assert(
         assertion: str_contains($chunk, 'kept-error') && str_contains($chunk, 'ignored-info') === false,
         description: 'appended lines pass through the record filters before printing'
      );

      // # --instance filters the file lane too — the lane that leaked other
      //   instances under -f (BG-24)
      $Filters = $Sieve->invoke($Command, ['instance' => '8443']);
      $Following = $Source->invoke($Command, new Backlog($dir), $Filters);
      $Following->current();
      file_put_contents(
         "$dir/App.log",
         $line('INFO', 'kept-instance', '8443') . $line('INFO', 'other-instance', '8080') . $line('INFO', 'legacy-line'),
         FILE_APPEND
      );
      $Following->next();
      $chunk = (string) $Following->current();
      yield assert(
         assertion: str_contains($chunk, 'kept-instance')
            && str_contains($chunk, 'other-instance') === false
            && str_contains($chunk, 'legacy-line') === false,
         description: 'the file lane honors --instance: other instances and legacy lines never flow'
      );

      // @ Cleanup
      foreach ((array) glob("$dir/*") as $file) {
         @unlink((string) $file);
      }
      @rmdir($dir);
   }
);
