<?php
namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const PHP_BINARY;
use function array_merge;
use function array_values;
use function assert;
use function fclose;
use function function_exists;
use function getenv;
use function is_file;
use function is_resource;
use function preg_replace;
use function proc_close;
use function proc_open;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environment\Agent;


/**
 * `project <name> <verb> --help` answers for that verb — in the order run()
 * accepts — with the verb's own options, and without pretending the name is
 * missing when it was given.
 */
return new Test(
   description: '`project <name> <verb> --help` renders the verb help with its options; `project <verb> --help` still asks for the name',
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
      $run = static function (array $arguments) use ($launcher, $environment): string {
         $Process = proc_open(
            [PHP_BINARY, $launcher, 'project', ...$arguments, '--help'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BOOTGLY_ROOT_BASE,
            $environment
         );
         if (is_resource($Process) === false) {
            return '';
         }
         $output = (string) stream_get_contents($pipes[1]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         proc_close($Process);
         // : Without the escapes — the assertions read words, not colors
         return (string) preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $output);
      };

      // @ Name first — the form the tips print
      $named = $run(['Demo/HTTP_Server_CLI', 'logs']);

      yield assert(
         assertion: str_contains($named, 'Project logs options') && str_contains($named, '--follow'),
         description: '`project <name> logs --help` lists the logs options'
      );
      yield assert(
         assertion: str_contains($named, 'Missing required argument') === false,
         description: 'a name that was given is not reported missing'
      );
      yield assert(
         assertion: str_contains($named, 'Project arguments') === false,
         description: 'the generic 14-verb panel is not what a verb help renders'
      );

      // @ The start verb lists the mode flags the scaffold maps
      $start = $run(['Demo/HTTP_Server_CLI', 'start']);

      yield assert(
         assertion: str_contains($start, 'Project start options') && str_contains($start, '-f, -i, -m'),
         description: '`project <name> start --help` names the `-f`/`-i`/`-m` mode flags'
      );

      // @ Verb first, no name
      $bare = $run(['logs']);

      yield assert(
         assertion: str_contains($bare, 'Missing required argument') && str_contains($bare, 'Project logs options'),
         description: '`project logs --help` still asks for the name and lists the options'
      );
   }
);
