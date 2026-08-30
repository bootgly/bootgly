<?php

namespace Bootgly\commands;


use function assert;
use function file_put_contents;
use function glob;
use function json_encode;
use function mkdir;
use function rewind;
use function rmdir;
use function str_contains;
use function stream_get_contents;
use function strpos;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: '`bootgly logs` prints the filtered backlog and exits',
   test: function () {
      // ! Fixture logs directory
      $dir = sys_get_temp_dir() . '/bootgly-logscmd-' . uniqid();
      mkdir($dir, 0o775, true);

      $line = static function (float $timestamp, string $level, string $channel, string $message, string $project): string {
         return json_encode([
            'timestamp' => $timestamp, 'level' => $level, 'project' => $project,
            'channel' => $channel, 'message' => $message, 'context' => [], 'extra' => [],
         ]) . "\n";
      };
      file_put_contents(
         "$dir/App.log",
         $line(100.0, 'INFO', 'App', 'app-info', 'Alpha')
            . $line(300.0, 'ERROR', 'App', 'app-error', 'Alpha')
      );
      file_put_contents(
         "$dir/Core.log",
         $line(200.0, 'WARNING', 'Core', 'core-warning', 'framework')
      );

      $render = static function (array $options) use ($dir): string {
         $Command = new LogsCommand;
         $Command->directory = $dir;

         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         try {
            $Command->run([], $options);
         }
         finally {
            $Terminal->Output = $Restore;
         }

         rewind($Host->stream);
         return (string) stream_get_contents($Host->stream);
      };

      // # Unfiltered: every record, merged ascending
      $all = $render(['json' => true]);
      yield assert(
         assertion: substr_count($all, "\n") === 3
            && strpos($all, 'app-info') < strpos($all, 'core-warning')
            && strpos($all, 'core-warning') < strpos($all, 'app-error'),
         description: 'all records print as JSON lines, merged ascending by timestamp'
      );

      // # --level bounds severity
      $errors = $render(['json' => true, 'level' => 'error']);
      yield assert(
         assertion: str_contains($errors, 'app-error')
            && str_contains($errors, 'app-info') === false
            && str_contains($errors, 'core-warning') === false,
         description: '--level=error keeps only records at ERROR or more severe'
      );

      // # --channel restricts channels
      $core = $render(['json' => true, 'channel' => 'Core']);
      yield assert(
         assertion: str_contains($core, 'core-warning') && str_contains($core, 'app-') === false,
         description: '--channel keeps only the named channel'
      );

      // # Provenance filters
      $alpha = $render(['json' => true, 'project' => 'Alpha']);
      $framework = $render(['json' => true, 'framework' => true]);
      yield assert(
         assertion: str_contains($alpha, 'app-info') && str_contains($alpha, 'core-warning') === false
            && str_contains($framework, 'core-warning') && str_contains($framework, 'app-info') === false,
         description: '--project and --framework filter by record provenance'
      );

      // # --since bounds by timestamp; invalid --since refuses
      $recent = $render(['json' => true, 'since' => '@150']);
      yield assert(
         assertion: str_contains($recent, 'core-warning') && str_contains($recent, 'app-info') === false,
         description: '--since (strtotime syntax) drops records before the cutoff'
      );

      $Bad = new LogsCommand;
      $Bad->directory = $dir;
      $Host = new Output('php://memory');
      $Terminal = CLI->Terminal;
      $Restore = $Terminal->Output;
      $Terminal->Output = $Host;
      try {
         $refused = $Bad->run([], ['since' => 'not-a-time']);
      }
      finally {
         $Terminal->Output = $Restore;
      }
      yield assert(
         assertion: $refused === false,
         description: 'an unparseable --since refuses the run'
      );

      // @ Cleanup
      foreach ((array) glob("$dir/*") as $file) {
         @unlink((string) $file);
      }
      @rmdir($dir);
   }
);
