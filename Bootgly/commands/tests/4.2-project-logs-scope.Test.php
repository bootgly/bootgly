<?php

namespace Bootgly\commands;


use function assert;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function rewind;
use function rmdir;
use function str_contains;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: '`project <Name> logs` delegates to the kit logs command, project-scoped',
   test: function () {
      $Command = new ProjectCommand;

      $render = static function (callable $call): string {
         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         try {
            $call();
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         return (string) stream_get_contents($Host->stream);
      };

      // # A flag the subcommand does not implement is refused by admit()
      $refused = null;
      $output = $render(static function () use ($Command, &$refused): void {
         $refused = $Command->logs(['Demo/HTTP_Server_CLI'], ['dry-run' => true]);
      });
      yield assert(
         assertion: $refused === false && str_contains($output, 'Unknown option'),
         description: 'an option outside the logs set is refused with the admit() alert'
      );

      // # A missing name renders the subcommand help and fails
      $helped = null;
      $output = $render(static function () use ($Command, &$helped): void {
         $helped = $Command->logs([], []);
      });
      yield assert(
         assertion: $helped === false && str_contains($output, 'logs'),
         description: 'omitting the project name falls back to the logs help'
      );

      // # Delegation: the registered kit command runs project-scoped
      /** @var null|LogsCommand $Logs */
      $Logs = CLI->Commands->find('logs');
      yield assert(
         assertion: $Logs instanceof LogsCommand,
         description: 'the kit `logs` command is registered (one implementation)'
      );

      $dir = sys_get_temp_dir() . '/bootgly-projlogs-' . uniqid();
      mkdir($dir, 0o775, true);
      file_put_contents("$dir/App.log", json_encode([
         'timestamp' => 100.0, 'level' => 'INFO', 'project' => 'Demo/HTTP_Server_CLI',
         'channel' => 'App', 'message' => 'scoped-record', 'context' => [], 'extra' => [],
      ]) . "\n" . json_encode([
         'timestamp' => 200.0, 'level' => 'INFO', 'project' => 'Other',
         'channel' => 'App', 'message' => 'foreign-record', 'context' => [], 'extra' => [],
      ]) . "\n");

      $saved = $Logs->directory;
      $Logs->directory = $dir;
      try {
         $output = $render(static function () use ($Command): void {
            $Command->logs(['Demo/HTTP_Server_CLI'], ['json' => true]);
         });
      }
      finally {
         $Logs->directory = $saved;
      }

      yield assert(
         assertion: str_contains($output, 'scoped-record')
            && str_contains($output, 'foreign-record') === false,
         description: 'the delegate filters by the project\'s provenance automatically'
      );

      // @ Cleanup
      @unlink("$dir/App.log");
      @rmdir($dir);
   }
);
