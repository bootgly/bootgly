<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const PHP_BINARY;
use function assert;
use function chmod;
use function escapeshellarg;
use function fclose;
use function fgets;
use function file_put_contents;
use function flock;
use function fopen;
use function getenv;
use function glob;
use function is_resource;
use function mkdir;
use function posix_getpid;
use function posix_getuid;
use function posix_kill;
use function proc_close;
use function proc_open;
use function rmdir;
use function shell_exec;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use function usleep;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * `project stop` and `restart` against the state files a project leaves behind:
 * the zero-byte tombstone of its own clean stop, and a state this account
 * cannot verify. Neither may be reported as a privilege problem it is not, and
 * the second may never be covered by a success.
 */

return new Test(
   description: '`project stop|restart`: a tombstone is silent, an unverifiable instance is named and never covered by success',
   test: function () {
      // ! Scratch working base: registry + one project + its pids directory
      $base = sys_get_temp_dir() . '/bootgly-stopreport-' . uniqid();
      mkdir("$base/projects/Scratch", 0o775, true);
      mkdir("$base/storage/pids", 0o755, true);
      file_put_contents(
         "$base/projects/Bootgly.projects.php",
         "<?php return ['Scratch' => ['interfaces' => ['WPI']]];"
      );
      file_put_contents("$base/projects/Scratch/Scratch.Project.php", <<<'PROJECT'
      <?php

      use Bootgly\API\Projects\Project;

      return new Project(
         boot: static function (): void {},
         exportable: false,
         name: 'Scratch'
      );
      PROJECT);

      $probe = static function (string $verb, string $qualifier = '') use ($base): string {
         // ! A wide terminal, so the Alert crop never decides an assertion
         $environment = 'COLUMNS=200 BOOTGLY_STOP_PROBE_ROOT=' . escapeshellarg(BOOTGLY_ROOT_BASE)
            . ' BOOTGLY_STOP_PROBE_BASE=' . escapeshellarg($base)
            . ' BOOTGLY_STOP_PROBE_VERB=' . escapeshellarg($verb)
            . ' BOOTGLY_STOP_PROBE_QUALIFIER=' . escapeshellarg($qualifier);
         $fixture = escapeshellarg(__DIR__ . '/fixtures/stop_probe.php');

         return (string) shell_exec("$environment " . escapeshellarg(PHP_BINARY) . " $fixture 2>&1");
      };
      // ! Detach a stand-in master (see fixtures/master_probe.php); returns
      //   the launcher handle (already closed) and the detached master's PID
      $spawn = static function (string $port) use ($base): array {
         $Launcher = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/master_probe.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
               'BOOTGLY_MASTER_PROBE_ROOT' => BOOTGLY_ROOT_BASE,
               'BOOTGLY_MASTER_PROBE_BASE' => $base,
               'BOOTGLY_MASTER_PROBE_PORT' => $port,
               'PATH' => (string) getenv('PATH'),
            ]
         );
         $ready = is_resource($Launcher) ? (string) fgets($pipes[1]) : '';
         if (is_resource($Launcher)) {
            foreach ($pipes as $pipe) {
               is_resource($pipe) && fclose($pipe);
            }
            proc_close($Launcher);
         }
         $master = str_starts_with($ready, 'ready ') ? (int) trim(substr($ready, strlen('ready'))) : 0;

         return [null, $master];
      };
      // ! init reaps the killed master; give the process table a moment
      $settle = static function (int $master): bool {
         $alive = $master > 1 && posix_kill($master, 0);
         for ($i = 0; $alive && $i < 30; $i++) {
            usleep(100000);
            $alive = posix_kill($master, 0);
         }

         return $alive;
      };
      $tip = 'state files exist but could not be verified';
      // ! The tip is uid-gated: root sees everything and never gets it
      $root = posix_getuid() === 0;
      $Master = null;
      $master = 0;
      $Held = null;

      try {
         // # A) The project's own clean-stop marker — a zero-byte tombstone
         file_put_contents("$base/storage/pids/Scratch.8082.json", '');
         $output = $probe('stop');

         yield assert(
            assertion: str_contains($output, 'result:false') && str_contains($output, 'is not running'),
            description: 'stop on a tombstone reports "is not running" and fails — got: ' . $output
         );
         yield assert(
            assertion: str_contains($output, $tip) === false,
            description: 'a tombstone never earns the service-account tip'
         );

         // # B) A readable state whose master is gone — stale, not foreign
         file_put_contents(
            "$base/storage/pids/Scratch.8083.json",
            '{"master":2147483646,"workers":[],"host":"0.0.0.0","port":8083,"started":1,"type":"WPI"}'
         );
         $output = $probe('stop');

         yield assert(
            assertion: str_contains($output, 'is not running') && str_contains($output, $tip) === false,
            description: 'a dead master is stale state, not a privilege boundary — got: ' . $output
         );
         unlink("$base/storage/pids/Scratch.8083.json");

         // # G) A single verified master and nothing else: the clean SUCCESS
         [$Master, $master] = $spawn('9001');

         yield assert(
            assertion: $master > 1 && posix_kill($master, 0),
            description: 'the stand-in master is detached, holds its instance lock and published its state'
         );

         $output = $probe('stop');
         $alive = $settle($master);

         yield assert(
            assertion: str_contains($output, 'result:true') && str_contains($output, 'SUCCESS') && $alive === false,
            description: 'a stop that covered everything prints SUCCESS, returns true and the master is gone — got: ' . $output
         );

         // # C) A live master this account cannot verify — the CMD-9 trigger:
         //   the lock is HELD (by this runner) but its inode identity refuses
         //   (0644), and the state names a process that is alive
         // ! Held directly on the scratch inode — a State object would bind
         //   to the runner's own storage, not the scratch base
         $Held = fopen("$base/storage/pids/Scratch.9002.lock", 'c+b');
         $held = is_resource($Held) && flock($Held, LOCK_EX | LOCK_NB);
         chmod("$base/storage/pids/Scratch.9002.lock", 0644);
         file_put_contents(
            "$base/storage/pids/Scratch.9002.json",
            '{"master":' . posix_getpid() . ',"workers":[],"host":"0.0.0.0","port":9002,"started":1,"type":"WPI"}'
         );

         yield assert(
            assertion: $held === true,
            description: 'the runner holds the 9002 instance lock for the unverifiable case'
         );

         $output = $probe('stop');

         yield assert(
            assertion: str_contains($output, 'result:false')
               && str_contains($output, 'Unverifiable instance(s) 9002')
               && str_contains($output, 'nothing stopped'),
            description: 'stop names the unverifiable instance and reports that nothing stopped — got: ' . $output
         );
         yield assert(
            assertion: $root || str_contains($output, $tip),
            description: 'an unverifiable instance earns the service-account tip (unless root)'
         );
         yield assert(
            assertion: str_contains($output, 'SUCCESS') === false,
            description: 'no success is printed over an instance the command could not act on'
         );

         // # D) The qualifier scopes both the verdict and the tip
         $output = $probe('stop', '8082');

         yield assert(
            assertion: str_contains($output, 'is not running on port 8082') && str_contains($output, $tip) === false,
            description: 'stop <port> on the tombstoned port ignores the unverifiable state of another instance — got: ' . $output
         );

         // # E) restart refuses rather than starting a second master beside it
         $output = $probe('restart');

         yield assert(
            assertion: str_contains($output, 'result:false')
               && str_contains($output, 'Unverifiable instance(s) 9002')
               && str_contains($output, 'restart refused')
               && str_contains($output, 'Starting') === false,
            description: 'restart is refused while an instance cannot be verified — got: ' . $output
         );

         // # F) A verified master beside the unverifiable one: the loop stops
         //   it, and the verdict still refuses to cover what it could not act on
         [$Master, $master] = $spawn('9001');

         yield assert(
            assertion: $master > 1 && posix_kill($master, 0),
            description: 'a second stand-in master is up beside the unverifiable instance'
         );

         $output = $probe('stop');
         $alive = $settle($master);

         yield assert(
            assertion: str_contains($output, 'result:false')
               && str_contains($output, 'Stopped 1; unverified 9002'),
            description: 'a partial stop is reported as partial — one stopped, one unverifiable — and fails — got: ' . $output
         );
         yield assert(
            assertion: $alive === false,
            description: 'the verified master was actually terminated by the stop'
         );
         yield assert(
            assertion: str_contains($output, 'SUCCESS') === false,
            description: 'a partial stop never prints the unqualified SUCCESS'
         );
      }
      finally {
         // @ Cleanup — the held lock and any stand-in master first, then the tree
         if (is_resource($Held)) {
            flock($Held, LOCK_UN);
            fclose($Held);
         }
         if ($master > 1 && posix_kill($master, 0)) {
            posix_kill($master, 9);
         }
         foreach ((array) glob("$base/storage/pids/*") as $file) {
            @unlink((string) $file);
         }
         @rmdir("$base/storage/pids");
         @rmdir("$base/storage");
         @unlink("$base/projects/Scratch/Scratch.Project.php");
         @unlink("$base/projects/Bootgly.projects.php");
         @rmdir("$base/projects/Scratch");
         @rmdir("$base/projects");
         @rmdir($base);
      }
   }
);
