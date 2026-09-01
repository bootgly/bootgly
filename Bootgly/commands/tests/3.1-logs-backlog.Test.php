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

      $line = static function (float $timestamp, string $level, string $channel, string $message, string $project, null|string $instance = null): string {
         $data = [
            'timestamp' => $timestamp, 'level' => $level, 'project' => $project,
            'channel' => $channel, 'message' => $message, 'context' => [], 'extra' => [],
         ];
         // ? A legacy line (written before the field existed) carries no instance key
         if ($instance !== null) {
            $data['instance'] = $instance;
         }
         return json_encode($data) . "\n";
      };
      file_put_contents(
         "$dir/App.log",
         $line(100.0, 'INFO', 'App', 'app-info', 'Alpha', '8080')
            . $line(250.0, 'INFO', 'App', 'app-padded', 'Alpha', '08080')
            . $line(300.0, 'ERROR', 'App', 'app-error', 'Alpha', '8443')
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

      // # Unfiltered: every record, merged ascending (the instance key is additive)
      $all = $render(['json' => true]);
      yield assert(
         assertion: substr_count($all, "\n") === 4
            && strpos($all, 'app-info') < strpos($all, 'core-warning')
            && strpos($all, 'core-warning') < strpos($all, 'app-padded')
            && strpos($all, 'app-padded') < strpos($all, 'app-error'),
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

      // # --instance narrows the backlog to one instance (BG-24)
      $one = $render(['json' => true, 'project' => 'Alpha', 'instance' => '8443']);
      yield assert(
         assertion: str_contains($one, 'app-error') && str_contains($one, '"instance":"8443"')
            && str_contains($one, 'app-info') === false
            && str_contains($one, 'app-padded') === false
            && str_contains($one, 'core-warning') === false,
         description: '--instance keeps only that instance\'s records (import → re-format keeps the key)'
      );

      $kit = $render(['json' => true, 'instance' => '8080']);
      yield assert(
         assertion: str_contains($kit, 'app-info')
            && str_contains($kit, 'app-padded') === false
            && str_contains($kit, 'core-warning') === false
            && str_contains($kit, 'app-error') === false,
         description: 'kit-scope --instance matches the exact string: no legacy lines, no numeric coercion (08080 is not 8080)'
      );

      $none = $render(['json' => true, 'instance' => '99999']);
      $human = $render(['instance' => '99999']);
      yield assert(
         assertion: $none === '' && str_contains($human, 'No log records matched.'),
         description: 'an unknown instance prints nothing: the JSON stream stays empty, the human run says so'
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

      // # A bare --instance (no qualifier) refuses too — never silently ignored
      $Bare = new LogsCommand;
      $Bare->directory = $dir;
      $Host = new Output('php://memory');
      $Terminal->Output = $Host;
      try {
         $bare = $Bare->run([], ['json' => true, 'instance' => true]);
      }
      finally {
         $Terminal->Output = $Restore;
      }
      rewind($Host->stream);
      yield assert(
         assertion: $bare === false && str_contains((string) stream_get_contents($Host->stream), 'Invalid --instance'),
         description: 'a bare --instance is refused with a message, like a bare --since'
      );

      // @ Cleanup
      foreach ((array) glob("$dir/*") as $file) {
         @unlink((string) $file);
      }
      @rmdir($dir);
   }
);
