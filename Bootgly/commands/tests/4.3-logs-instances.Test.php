<?php

namespace Bootgly\commands;


use const BOOTGLY_STORAGE_DIR;
use const LOCK_EX;
use const LOCK_NB;
use function assert;
use function is_array;
use function posix_getpid;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function time;
use function unlink;
use ReflectionMethod;

use const Bootgly\CLI;
use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: '`logs -f`: several live instances list-and-refuse; a dead tap degrades to files-only',
   test: function () {
      $pidsDir = BOOTGLY_STORAGE_DIR . 'pids/';
      $PID = posix_getpid();

      // ! Two live, authenticated WPI instances of a scratch "project" id
      //   (attach() encodes 'LogsInstancesTest' to itself — no slash, no dot)
      $A = new State('LogsInstancesTest', '7101');
      $B = new State('LogsInstancesTest', '7102');
      $held = $A->lock(LOCK_EX | LOCK_NB) && $B->lock(LOCK_EX | LOCK_NB);
      foreach ([[$A, 7101], [$B, 7102]] as [$State, $port]) {
         $State->save([
            'master' => $PID, 'workers' => [], 'started' => time(),
            'type' => 'WPI', 'host' => '127.0.0.1', 'port' => $port,
         ]);
      }

      $Attach = new ReflectionMethod(LogsCommand::class, 'attach');
      $Command = new LogsCommand;

      $probe = static function (array $options) use ($Attach, $Command): array {
         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         try {
            $result = $Attach->invoke($Command, $options);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         return [$result, (string) stream_get_contents($Host->stream)];
      };

      // # Ambiguity: two live instances, no --instance → list and refuse
      [$result, $output] = $probe(['project' => 'LogsInstancesTest']);
      yield assert(
         assertion: $held && is_array($result) && $result[2] === true
            && str_contains($output, '7101') && str_contains($output, '7102'),
         description: 'the refusal lists every live instance qualifier'
      );

      // # The tiebreaker targets one instance; its dead tap degrades to files-only
      [$result, $output] = $probe(['project' => 'LogsInstancesTest', 'instance' => '7101']);
      yield assert(
         assertion: is_array($result) && $result[2] === false && $result[0] === []
            && isSet($result[1][0]) && str_contains((string) $result[1][0], 'following files only'),
         description: '--instance selects one instance; no live socket → note + file lane'
      );

      // # An unknown --instance under a project scope says so instead of sitting silent
      [$result, $output] = $probe(['project' => 'LogsInstancesTest', 'instance' => '7999']);
      yield assert(
         assertion: is_array($result) && $result[2] === false && $result[0] === []
            && isSet($result[1][0]) && str_contains((string) $result[1][0], 'No instance 7999'),
         description: 'an --instance no live instance matches → note + file lane, never a silent empty follow'
      );

      // # Kit scope (no --project): an --instance no project answers to notes it once
      [$result, $output] = $probe(['instance' => '7999']);
      yield assert(
         assertion: is_array($result) && $result[2] === false && $result[0] === []
            && isSet($result[1][0]) && str_contains((string) $result[1][0], 'No instance 7999'),
         description: 'kit scope: an unmatched --instance is noted instead of followed in silence'
      );

      // ! Cleanup
      $A->clean();
      $B->clean();
      foreach (['7101', '7102'] as $qualifier) {
         @unlink("{$pidsDir}LogsInstancesTest.$qualifier.json");
         @unlink("{$pidsDir}LogsInstancesTest.$qualifier.command");
         @unlink("{$pidsDir}LogsInstancesTest.$qualifier.lock");
      }
   }
);
