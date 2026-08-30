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

      $line = static function (string $level, string $message): string {
         return json_encode([
            'timestamp' => microtime(true), 'level' => $level, 'project' => 'framework',
            'channel' => 'App', 'message' => $message, 'context' => [], 'extra' => [],
         ]) . "\n";
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

      // @ Cleanup
      foreach ((array) glob("$dir/*") as $file) {
         @unlink((string) $file);
      }
      @rmdir($dir);
   }
);
