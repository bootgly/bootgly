<?php

namespace Bootgly\commands;


use const BOOTGLY_STORAGE_DIR;
use const LOCK_EX;
use const LOCK_NB;
use function array_key_first;
use function assert;
use function is_array;
use function json_decode;
use function posix_getpid;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function time;
use function touch;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: '`projects show`: one row per live instance across the registry; --all adds what is on record; --json emits the rows',
   test: function () {
      $pidsDir = BOOTGLY_STORAGE_DIR . 'pids/';
      $PID = posix_getpid();

      // ! show() walks the registry, so the planted instances belong to a registered project
      $path = (string) array_key_first(Projects::read());
      $id = Projects::encode($path);

      yield assert(
         assertion: $path !== '',
         description: 'probe precondition: the registry names at least one project'
      );

      // ! Two live, authenticated instances of that project: a server on a
      //   port and a console worker by PID — both masters are this process
      $Server = new State($id, '7201');
      $Worker = new State($id, (string) $PID);
      $held = $Server->lock(LOCK_EX | LOCK_NB) && $Worker->lock(LOCK_EX | LOCK_NB);
      $Server->save([
         'master' => $PID, 'workers' => [], 'started' => time() - 3725, 'status' => 'Running',
         'type' => 'WPI', 'host' => '127.0.0.1', 'port' => 7201, 'tap' => '', 'project' => $path,
      ]);
      $Worker->save([
         'master' => $PID, 'workers' => [$PID], 'started' => time() - 5,
         'type' => 'CLI', 'project' => $path,
      ]);
      // ! The tap column probes the pathname recomputed from the identity —
      //   a file at that path is what "available" means to the reader
      touch($Server->tapFile);

      $Command = new ProjectsCommand;
      $probe = static function (array $options) use ($Command): array {
         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         try {
            $result = $Command->run(['show'], $options);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         return [$result, (string) stream_get_contents($Host->stream)];
      };
      $pick = static function (string $output, string $instance) use ($path): null|array {
         $rows = json_decode($output, true);
         if (is_array($rows) === false) {
            return null;
         }
         foreach ($rows as $row) {
            if (($row['project'] ?? null) === $path && ($row['instance'] ?? null) === $instance) {
               return $row;
            }
         }
         return null;
      };

      try {
         // # --json: one document, one row per instance, the shape scripts read
         [$result, $output] = $probe(['json' => true]);
         $server = $pick($output, '7201');
         $worker = $pick($output, (string) $PID);

         yield assert(
            assertion: $held && $result === true && $server !== null
               && $server['interface'] === 'WPI' && $server['status'] === 'running'
               && $server['master'] === $PID && $server['workers'] === 0
               && $server['address'] === '127.0.0.1:7201' && $server['tap'] === true
               && $server['uptime'] >= 3725 && $server['uptime'] < 3785,
            description: 'the server instance row carries interface, status, master, address, tap and uptime — got: ' . $output
         );
         yield assert(
            assertion: $worker !== null
               && $worker['interface'] === 'CLI' && $worker['status'] === 'running'
               && $worker['master'] === $PID && $worker['workers'] === null
               && $worker['address'] === null && $worker['tap'] === false
               && $worker['uptime'] >= 5 && $worker['uptime'] < 65,
            description: 'the console instance row has no address, no tap and no worker count'
         );

         // # Text: the table lists both instances with the compact uptime
         [$result, $output] = $probe([]);

         yield assert(
            assertion: $result === true
               && str_contains($output, $path) && str_contains($output, '7201')
               && str_contains($output, (string) $PID) && str_contains($output, 'running')
               && str_contains($output, '127.0.0.1:7201') && str_contains($output, '1h 2m')
               && str_contains($output, 'yes'),
            description: 'the table shows both instances, the address, the tap and the uptime — got: ' . $output
         );

         // # A stopped record: the default view hides it, --all shows it as stopped
         $Server->clean();

         [, $output] = $probe(['json' => true]);
         $hidden = $pick($output, '7201');
         [, $output] = $probe(['json' => true, 'all' => true]);
         $stopped = $pick($output, '7201');

         yield assert(
            assertion: $hidden === null && $stopped !== null
               && $stopped['status'] === 'stopped' && $stopped['master'] === null
               && $stopped['uptime'] === null && $stopped['tap'] === false,
            description: 'a tombstone is hidden by default and listed as stopped under --all'
         );

         // # An unknown flag is refused before anything is read
         [$result, $output] = $probe(['follow' => true]);

         yield assert(
            assertion: $result === false && str_contains($output, 'Unknown option'),
            description: 'show() refuses a flag it does not implement'
         );
      }
      finally {
         // ! Cleanup — leave the registry of instances as it was found
         $Worker->clean();
         @unlink($Server->tapFile);
         foreach (['7201', (string) $PID] as $qualifier) {
            @unlink("{$pidsDir}{$id}.{$qualifier}.json");
            @unlink("{$pidsDir}{$id}.{$qualifier}.command");
            @unlink("{$pidsDir}{$id}.{$qualifier}.lock");
         }
      }
   }
);
