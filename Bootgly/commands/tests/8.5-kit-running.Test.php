<?php

namespace Bootgly\commands;


use const BOOTGLY_STORAGE_DIR;
use const LOCK_EX;
use const LOCK_NB;
use function array_diff;
use function array_key_first;
use function assert;
use function bin2hex;
use function getmypid;
use function glob;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function json_decode;
use function mkdir;
use function posix_getpid;
use function random_bytes;
use function random_int;
use function rewind;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function sys_get_temp_dir;
use function time;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;
use Bootgly\CLI\Terminal\Output;


/**
 * A running instance keeps the files it loaded: the move warns, names it,
 * and — headless — proceeds only with `--yes`.
 */

return new Test(
   description: 'a running instance is named and the move waits for --yes',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-kit-running-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($base, 0775, true);
      $Erase = function (string $target) use (&$Erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $Erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      // ! A live, authenticated instance of a registered project — this process is its master
      $path = (string) array_key_first(Projects::read());
      // ? No registered project (a kit with the empty registry): nothing to plant an instance on
      if ($path === '') {
         yield assert(assertion: true, description: 'Skipped: the registry names no project to plant an instance on');

         return;
      }
      $id = Projects::encode($path);
      $PID = posix_getpid();
      // ! A qualifier no real instance is likely to hold — never a fixed port
      $port = random_int(40000, 60000);
      $Server = new State($id, (string) $port);
      $held = $Server->lock(LOCK_EX | LOCK_NB);
      $Server->save([
         'master' => $PID, 'workers' => [], 'started' => time() - 60, 'status' => 'Running',
         'type' => 'WPI', 'host' => '127.0.0.1', 'port' => $port, 'tap' => '', 'project' => $path,
      ]);

      try {
         $fixture = (require __DIR__ . '/fixtures/kit_fixture.php')($base);
         $canon = $fixture['canon'];
         $commits = $fixture['commits'];
         $Run = $fixture['run'];

         $Kit = new class ($fixture['clone']('kit', 'refs/tags/v1.0.0-beta.1'), $canon) extends KitCommand {
            public function __construct (string $kit, string $repository)
            {
               parent::__construct();
               $this->kit = $kit;
               $this->repository = $repository;
            }
         };
         $Probe = static function (KitCommand $Command, array $arguments, array $options): array {
            $Host = new Output('php://memory');
            $Terminal = CLI->Terminal;
            $Restore = $Terminal->Output;
            $Terminal->Output = $Host;
            try {
               $result = $Command->run($arguments, $options);
            }
            finally {
               $Terminal->Output = $Restore;
            }
            rewind($Host->stream);
            $document = json_decode((string) stream_get_contents($Host->stream), true);

            return [$result, is_array($document) ? $document : []];
         };
         $kit = "{$base}/kit";

         yield assert(
            assertion: $held === true && $path !== '',
            description: 'probe precondition: a registered project with a planted live instance'
         );

         // # Same major, so the instance is the only question
         [$result, $document] = $Probe($Kit, ['upgrade', 'v1.0.0-beta.2'], ['json' => true]);

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'refused'
               && str_contains($document['reason'] ?? '', 'Not confirmed')
               && in_array("{$path} ({$port})", $document['running'] ?? [], true)
               && $Run($kit, 'rev-parse HEAD') === $commits['v1.0.0-beta.1'],
            description: 'the running instance is named and, without --yes, the kit does not move'
         );

         [$result, $document] = $Probe($Kit, ['upgrade', 'v1.0.0-beta.2'], ['json' => true, 'yes' => true]);

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'moved'
               && in_array("{$path} ({$port})", $document['running'] ?? [], true)
               && $Run($kit, 'rev-parse HEAD') === $commits['v1.0.0-beta.2'],
            description: 'with --yes the move proceeds, still naming the instance to reload'
         );
      }
      finally {
         // ! `clean()` tombstones (truncates); the files this test minted leave with it
         $Server->clean();
         foreach (glob(BOOTGLY_STORAGE_DIR . "pids/{$id}.{$port}.*") ?: [] as $file) {
            @unlink($file);
         }
         $Erase($base);
      }
   }
);
