<?php
namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_VERSION;
use const PHP_BINARY;
use const PHP_VERSION;
use function array_merge;
use function array_values;
use function assert;
use function fclose;
use function function_exists;
use function getenv;
use function is_file;
use function is_resource;
use function proc_close;
use function proc_open;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environment\Agent;


/**
 * `--version` / `-V` answer with the version and nothing else. `-v` stays the
 * verbosity flag (help), and `version` is no command.
 */
return new Test(
   description: '`bootgly --version` and `-V` print the version with exit 0; `-v` still means verbosity',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      $launcher = BOOTGLY_ROOT_BASE . '/bootgly';
      if (is_file($launcher) === false) {
         yield assert(assertion: true, description: 'Skipped: the bootgly launcher is not at the root');
         return;
      }

      // ! A human environment — agent markers would switch the output contract
      $environment = getenv();
      foreach ([...array_merge(...array_values(Agent::MARKERS)), 'AI_AGENT', 'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY'] as $variable) {
         unset($environment[$variable]);
      }
      $run = static function (string $flag) use ($launcher, $environment): array {
         $Process = proc_open(
            [PHP_BINARY, $launcher, $flag],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BOOTGLY_ROOT_BASE,
            $environment
         );
         if (is_resource($Process) === false) {
            return ['', -1];
         }
         $output = (string) stream_get_contents($pipes[1]);
         fclose($pipes[1]);
         fclose($pipes[2]);

         return [$output, proc_close($Process)];
      };

      // @
      $expected = 'Bootgly v' . BOOTGLY_VERSION . ' | PHP v' . PHP_VERSION;
      foreach (['--version', '-V'] as $flag) {
         [$output, $status] = $run($flag);

         yield assert(
            assertion: $status === 0 && str_contains($output, $expected) && str_contains($output, 'Commands arguments') === false,
            description: "`{$flag}` prints `{$expected}` alone, exit 0"
         );
      }

      // ? `-v` is verbosity: the help, not the version
      [$output, $status] = $run('-v');

      yield assert(
         assertion: str_contains($output, 'Commands arguments') && str_contains($output, $expected . "\n") === false,
         description: '`-v` keeps meaning verbosity — the help renders, the bare version line does not'
      );
   }
);
