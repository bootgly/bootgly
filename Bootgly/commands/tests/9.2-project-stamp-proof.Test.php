<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const PHP_BINARY;
use const PHP_OS_FAMILY;
use const SIGKILL;
use function assert;
use function chmod;
use function clearstatcache;
use function escapeshellarg;
use function fclose;
use function fgets;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function filesize;
use function getenv;
use function glob;
use function is_file;
use function is_resource;
use function microtime;
use function mkdir;
use function posix_getpid;
use function posix_kill;
use function preg_match;
use function proc_close;
use function proc_open;
use function rmdir;
use function shell_exec;
use function str_contains;
use function str_starts_with;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use function usleep;

use Bootgly\ACI\Process\Census;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The project controls over the Darwin instance proof — the holder's stamp
 * in the lock plus the process table — forced on Linux through both
 * fixtures: a stamped stand-in master and stamped commands.
 */

return new Test(
   description: '`project show|reload|stop` prove a stamped instance without procfs — passive under SIGSTOP, never leaving a frozen master stopped, refusing stale locks, forged state and stamp-less locks',
   // ? The proof needs the system `ps` with its keywords (BusyBox lacks
   //   them) — never skipped on Darwin, whose proof it is
   skip: PHP_OS_FAMILY !== 'Darwin' && Census::read(posix_getpid()) === [],
   test: function () {
      // ! Scratch working base: registry + one project + its pids directory
      $base = sys_get_temp_dir() . '/bootgly-stampproof-' . uniqid();
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
      $pids = "$base/storage/pids";
      $marker = "$base/reloaded";

      // ! Run `bootgly project Scratch <verb>` — the Darwin proof unless told not to
      $probe = static function (string $verb, bool $stamped = true) use ($base): string {
         // ! A wide terminal, so the Alert crop never decides an assertion
         $environment = 'COLUMNS=200 BOOTGLY_STOP_PROBE_ROOT=' . escapeshellarg(BOOTGLY_ROOT_BASE)
            . ' BOOTGLY_STOP_PROBE_BASE=' . escapeshellarg($base)
            . ' BOOTGLY_STOP_PROBE_VERB=' . escapeshellarg($verb)
            . ' BOOTGLY_STOP_PROBE_STAMPED=' . ($stamped ? '1' : '0');
         $fixture = escapeshellarg(__DIR__ . '/fixtures/stop_probe.php');

         return (string) shell_exec("$environment " . escapeshellarg(PHP_BINARY) . " $fixture 2>&1");
      };
      // ! Detach a stand-in master (see fixtures/master_probe.php): stamped
      //   unless told not to, optionally with a worker keeping the inherited
      //   lock for N seconds, deaf to SIGTERM, recording SIGUSR2, with a
      //   worker that spoils the lock's mode once SIGTERM reaches it
      /** @var array<int,int> $spawned every stand-in PID, killed in finally */
      $spawned = [];
      $spawn = static function (
         string $port,
         bool $stamped = true,
         int $hold = 0,
         bool $deaf = false,
         bool $spoil = false
      ) use ($base, $marker, &$spawned): array {
         $Launcher = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/master_probe.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
               'BOOTGLY_MASTER_PROBE_ROOT' => BOOTGLY_ROOT_BASE,
               'BOOTGLY_MASTER_PROBE_BASE' => $base,
               'BOOTGLY_MASTER_PROBE_PORT' => $port,
               'BOOTGLY_MASTER_PROBE_STAMPED' => $stamped ? '1' : '0',
               'BOOTGLY_MASTER_PROBE_MARKER' => $marker,
               'BOOTGLY_MASTER_PROBE_WORKER' => (string) $hold,
               'BOOTGLY_MASTER_PROBE_DEAF' => $deaf ? '1' : '0',
               'BOOTGLY_MASTER_PROBE_SPOIL' => $spoil ? '1' : '0',
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
         $master = 0;
         $worker = 0;
         if (preg_match('/^ready (\d+) (\d+)$/', trim($ready), $match) === 1) {
            $master = (int) $match[1];
            $worker = (int) $match[2];
            $spawned[] = $master;
            if ($worker > 0) {
               $spawned[] = $worker;
            }
         }

         return [$master, $worker];
      };
      // ! init reaps a killed process; give the process table a moment
      $settle = static function (int $PID): bool {
         $alive = $PID > 1 && posix_kill($PID, 0);
         for ($i = 0; $alive && $i < 30; $i++) {
            usleep(100000);
            $alive = posix_kill($PID, 0);
         }

         return $alive;
      };
      $stamp = static function (string $port) use ($pids): string {
         clearstatcache(true, "$pids/Scratch.$port.lock");

         return (string) @file_get_contents("$pids/Scratch.$port.lock");
      };

      try {
         // # A) A stamped stand-in and its worker are running
         [$master, $worker] = $spawn('9201', hold: 20);

         yield assert(
            assertion: $master > 1 && $worker > 1 && $stamp('9201') === "{$master}\n",
            description: 'the stand-in master holds its instance lock, stamped with its own PID, beside one worker'
         );

         $output = $probe('show');

         yield assert(
            assertion: str_contains($output, 'result:true')
               && preg_match('/Status\s+running/', $output) === 1
               && preg_match("/Master PID\s+{$master}\b/", $output) === 1
               && preg_match('/Workers\s+1\/1/', $output) === 1,
            description: 'show proves the master and its worker without procfs — got: ' . $output
         );

         // ? The procfs proof exists on Linux only
         if (PHP_OS_FAMILY === 'Linux') {
            $output = $probe('show', stamped: false);

            yield assert(
               assertion: preg_match('/Status\s+running/', $output) === 1 && preg_match('/Workers\s+1\/1/', $output) === 1,
               description: 'the procfs proof still verifies a stamped lock (the sides may disagree) — got: ' . $output
            );
         }
         else {
            yield (new Assertion(
               description: 'procfs proof over a stamped lock not exercised: this kernel has no procfs proof'
            ))->skip();
         }

         // # B) reload signals the proven master
         $output = $probe('reload');
         $deadline = microtime(true) + 2.0;
         while (is_file($marker) === false && microtime(true) < $deadline) {
            usleep(20000);
         }

         yield assert(
            assertion: str_contains($output, 'result:true')
               && str_contains($output, 'Reload signal sent to 1 instance(s)')
               && @file_get_contents($marker) === 'SIGUSR2',
            description: 'reload signals the proven master, which records SIGUSR2 — got: ' . $output
         );

         // # C) stop terminates the lineage and tombstones the state
         $output = $probe('stop');
         $masterAlive = $settle($master);
         $workerAlive = $settle($worker);
         clearstatcache(true, "$pids/Scratch.9201.json");

         yield assert(
            assertion: str_contains($output, 'result:true')
               && str_contains($output, 'SUCCESS')
               && $masterAlive === false
               && $workerAlive === false
               && @filesize("$pids/Scratch.9201.json") === 0,
            description: 'stop terminates the proven master and worker and tombstones their state — got: ' . $output
         );

         $output = $probe('show');

         yield assert(
            assertion: str_contains($output, 'result:false') && str_contains($output, 'No running instance found'),
            description: 'show after the stop finds nothing running — got: ' . $output
         );

         // # D) Passive: a master deaf to SIGTERM is frozen with SIGSTOP and
         //   re-proven — with its worker — while stopped, then killed
         [$master, $worker] = $spawn('9202', hold: 20, deaf: true);
         $started = microtime(true);
         $output = $probe('stop');
         $elapsed = microtime(true) - $started;
         $masterAlive = $settle($master);
         $workerAlive = $settle($worker);

         yield assert(
            assertion: $master > 1 && $worker > 1
               && str_contains($output, 'result:true')
               && str_contains($output, 'SUCCESS')
               && $masterAlive === false
               && $workerAlive === false
               && $elapsed >= 5.0,
            description: 'a master that ignores SIGTERM is proven while frozen and killed with its worker (took ' . (int) $elapsed . 's) — got: ' . $output
         );

         // # D2) A frozen master whose proof then fails is resumed, never
         //   stranded in SIGSTOP: its worker spoils the lock's mode once
         //   stop signals it — and stop signals workers only after the freeze
         [$master, $worker] = $spawn('9206', hold: 20, deaf: true, spoil: true);
         $output = $probe('stop');
         $lock = "$pids/Scratch.9206.lock";
         clearstatcache(true, $lock);
         $mode = (int) @fileperms($lock) & 0777;
         @chmod($lock, 0600);
         $state = Census::read($master)[$master]['state'] ?? '';

         yield assert(
            assertion: $master > 1 && $worker > 1
               && $mode === 0644
               && str_contains($output, 'result:false')
               && str_contains($output, 'slipped')
               && $state !== ''
               && str_starts_with($state, 'T') === false,
            description: "a master proven before the freeze but not after it is resumed and reported, never left stopped (state {$state}) — got: " . $output
         );
         $master > 1 && posix_kill($master, SIGKILL);
         $worker > 1 && posix_kill($worker, SIGKILL);

         // # E) Stale: the master is gone, an orphaned worker still holds the lock
         [$master, $worker] = $spawn('9203', hold: 8);
         posix_kill($master, SIGKILL);
         $masterAlive = $settle($master);
         $output = $probe('stop');
         $workerAlive = $worker > 1 && posix_kill($worker, 0);

         yield assert(
            assertion: $masterAlive === false
               && str_contains($output, 'result:false')
               && str_contains($output, 'is not running')
               && $workerAlive,
            description: 'a lock held by an orphaned worker proves no master: stop reports not running and signals nothing — got: ' . $output
         );
         $worker > 1 && posix_kill($worker, SIGKILL);

         // # F) A state document forged to name another live process of this account
         [$master] = $spawn('9204');
         $runner = posix_getpid();
         file_put_contents(
            "$pids/Scratch.9204.json",
            '{"master":' . $runner . ',"workers":[],"host":"0.0.0.0","port":9204,"started":1,"type":"WPI"}'
         );
         $output = $probe('stop');

         yield assert(
            assertion: $master > 1
               && str_contains($output, 'result:false')
               && str_contains($output, 'nothing stopped')
               && str_contains($output, 'SUCCESS') === false
               && posix_kill($master, 0),
            description: 'a document naming a process the stamp does not name is refused: nothing is signalled, the stand-in still runs — got: ' . $output
         );
         $master > 1 && posix_kill($master, SIGKILL);

         // # G) A stamp-less lock never attests under the stamped proof
         [$master] = $spawn('9205', stamped: false);
         $empty = $stamp('9205');
         $stamped = $probe('show');

         yield assert(
            assertion: $master > 1
               && $empty === ''
               && str_contains($stamped, 'No running instance found'),
            description: 'a stamp-less lock fails closed under the stamped proof — got: ' . $stamped
         );

         // ? The procfs proof exists on Linux only
         if (PHP_OS_FAMILY === 'Linux') {
            $native = $probe('show', stamped: false);

            yield assert(
               assertion: preg_match('/Status\s+running/', $native) === 1,
               description: 'the procfs proof verifies the same stamp-less lock (the control) — got: ' . $native
            );
         }
         else {
            yield (new Assertion(
               description: 'procfs control over a stamp-less lock not exercised: this kernel has no procfs proof'
            ))->skip();
         }
      }
      finally {
         // @ Cleanup — every stand-in first, then the tree
         foreach ($spawned as $PID) {
            if ($PID > 1 && posix_kill($PID, 0)) {
               posix_kill($PID, SIGKILL);
            }
         }
         foreach ((array) glob("$pids/*") as $file) {
            @unlink((string) $file);
         }
         @unlink($marker);
         @rmdir($pids);
         @rmdir("$base/storage");
         @unlink("$base/projects/Scratch/Scratch.Project.php");
         @unlink("$base/projects/Bootgly.projects.php");
         @rmdir("$base/projects/Scratch");
         @rmdir("$base/projects");
         @rmdir($base);
      }
   }
);
