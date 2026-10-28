<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const LOCK_NB;
use const LOCK_SH;
use const PHP_BINARY;
use const PHP_OS_FAMILY;
use const SIGKILL;
use function array_intersect;
use function array_map;
use function assert;
use function clearstatcache;
use function count;
use function escapeshellarg;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fileinode;
use function filemtime;
use function flock;
use function fopen;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
use function in_array;
use function is_array;
use function is_dir;
use function is_int;
use function is_link;
use function is_resource;
use function json_decode;
use function microtime;
use function mkdir;
use function posix_getpid;
use function posix_getsid;
use function posix_kill;
use function preg_match;
use function proc_close;
use function proc_open;
use function rmdir;
use function shell_exec;
use function str_contains;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function sys_get_temp_dir;
use function touch;
use function trim;
use function uniqid;
use function unlink;
use function usleep;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use Bootgly\ACI\Process\Census;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The whole Darwin path, forced on Linux: a real 2-worker TCP daemon whose
 * workers share the one listener its master binds (`TCP_Server_CLI::$shared`),
 * controlled by `project start|show|reload|stop` over the stamped instance
 * proof (`State::$stamped`) — the reload included, where the fresh image
 * adopts the lock without stamping it again.
 */

return new Test(
   description: '`project start|show|reload|stop` drive a real TCP daemon in the Darwin modes (shared listener, stamped lock), forced on Linux',
   // ? The server needs fork; the proof, the system `ps` with its keywords
   //   (BusyBox lacks them) — never skipped on Darwin, whose proof it is
   skip: function_exists('pcntl_fork') === false
      || (PHP_OS_FAMILY !== 'Darwin' && Census::read(posix_getpid()) === []),
   test: function () {
      // ! Scratch working base: registry + one project booting the daemon
      $base = sys_get_temp_dir() . '/bootgly-darwinlife-' . uniqid();
      mkdir("$base/projects/Scratch", 0o775, true);
      mkdir("$base/storage/pids", 0o755, true);
      file_put_contents(
         "$base/projects/Bootgly.projects.php",
         "<?php return ['Scratch' => ['interfaces' => ['WPI']]];"
      );
      file_put_contents("$base/projects/Scratch/Scratch.Project.php", <<<'PROJECT'
      <?php

      use Bootgly\ACI\Process\State;
      use Bootgly\API\Endpoints\Server\Modes;
      use Bootgly\API\Projects\Project;
      use Bootgly\WPI\Interfaces\TCP_Server_CLI;
      use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs;
      use Bootgly\WPI\Interfaces\TCP_Server_CLI\Events;

      return new Project(
         boot: static function (array $arguments = [], array $options = []): void {
            // ! The Darwin modes, forced in every image (a reload re-runs this)
            TCP_Server_CLI::$shared = true;
            State::$stamped = true;

            $Server = new TCP_Server_CLI(Mode: Modes::Daemon);
            $Server->configure(new Configs(host: '127.0.0.1', port: (int) getenv('PORT'), workers: 2));
            $Server->on(Events::DataReceive, static fn (string $input): string => (string) posix_getpid());
            $Server->start();
         },
         exportable: false,
         name: 'Scratch'
      );
      PROJECT);

      // ! A free loopback port
      $Free = stream_socket_server('tcp://127.0.0.1:0');
      $port = 0;
      if (is_resource($Free)) {
         $port = (int) (explode(':', (string) stream_socket_get_name($Free, false))[1] ?? 0);
         fclose($Free);
      }
      $lock = "$base/storage/pids/Scratch.$port.lock";
      $document = "$base/storage/pids/Scratch.$port.json";

      // ! Run `bootgly project Scratch <verb>` under the stamped proof
      $probe = static function (string $verb) use ($base): string {
         // ! A wide terminal, so the Alert crop never decides an assertion
         $environment = 'COLUMNS=200 BOOTGLY_STOP_PROBE_ROOT=' . escapeshellarg(BOOTGLY_ROOT_BASE)
            . ' BOOTGLY_STOP_PROBE_BASE=' . escapeshellarg($base)
            . ' BOOTGLY_STOP_PROBE_VERB=' . escapeshellarg($verb)
            . ' BOOTGLY_STOP_PROBE_STAMPED=1';
         $fixture = escapeshellarg(__DIR__ . '/fixtures/stop_probe.php');

         return (string) shell_exec("$environment " . escapeshellarg(PHP_BINARY) . " $fixture 2>&1");
      };
      // ! The published topology: [master, workers]
      $topology = static function () use ($document): array {
         clearstatcache(true, $document);
         $data = json_decode((string) @file_get_contents($document), true);
         $master = is_array($data) && is_int($data['master'] ?? null) ? $data['master'] : 0;
         $workers = is_array($data) && is_array($data['workers'] ?? null)
            ? array_map('intval', $data['workers'])
            : [];

         return [$master, $workers];
      };
      // ! One request: the serving worker answers with its PID
      $ask = static function () use ($port): int {
         $Socket = @stream_socket_client("tcp://127.0.0.1:$port", $code, $message, 2.0);
         if ($Socket === false) {
            return 0;
         }
         stream_set_timeout($Socket, 2);
         fwrite($Socket, 'pid');
         $answer = (string) fread($Socket, 32);
         fclose($Socket);

         return (int) $answer;
      };
      $stamp = static function () use ($lock): string {
         clearstatcache(true, $lock);

         return (string) @file_get_contents($lock);
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
      /** @var array<int,int> $seen every daemon PID, killed in finally */
      $seen = [];

      try {
         // # start -d: the launcher returns once the daemon acknowledged readiness
         $environment = getenv();
         unset($environment['BOOTGLY_TTY']);
         $environment['PORT'] = (string) $port;
         $environment['COLUMNS'] = '200';
         $environment['BOOTGLY_STOP_PROBE_ROOT'] = BOOTGLY_ROOT_BASE;
         $environment['BOOTGLY_STOP_PROBE_BASE'] = $base;
         $environment['BOOTGLY_STOP_PROBE_VERB'] = 'start';
         $environment['BOOTGLY_STOP_PROBE_STAMPED'] = '1';
         $Launcher = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/stop_probe.php'],
            [
               0 => ['file', '/dev/null', 'r'],
               1 => ['file', "$base/start.log", 'w'],
               2 => ['file', "$base/start.log", 'a']
            ],
            $pipes,
            $base,
            $environment
         );
         $launched = is_resource($Launcher) ? proc_close($Launcher) : -1;

         [$master, $workers] = $topology();
         $deadline = microtime(true) + 10.0;
         while (count($workers) !== 2 && microtime(true) < $deadline) {
            usleep(100000);
            [$master, $workers] = $topology();
         }
         $seen = [$master, ...$workers];

         yield assert(
            assertion: $port > 0 && $launched === 0 && $master > 1 && count($workers) === 2
               && $stamp() === "{$master}\n",
            description: "start -d leaves a daemon master that stamped its lock, beside 2 workers — got: " . (string) @file_get_contents("$base/start.log")
         );

         $output = $probe('show');

         yield assert(
            assertion: str_contains($output, 'result:true')
               && preg_match('/Status\s+running/', $output) === 1
               && preg_match("/Master PID\s+{$master}\b/", $output) === 1
               && preg_match('/Workers\s+2\/2/', $output) === 1,
            description: 'show proves the daemon master and both workers through the stamp — got: ' . $output
         );

         $served = $ask();

         yield assert(
            assertion: in_array($served, $workers, true),
            description: "a worker accepts on the shared listener and answers (PID {$served})"
         );

         // ! A re-stamp within the same second keeps a whole-second mtime:
         //   move the lock's mtime a second back, so any rewrite shows. The
         //   master was born before it stamped, so it still attests.
         clearstatcache(true, $lock);
         $inode = (int) @fileinode($lock);
         $mtime = (int) @filemtime($lock) - 1;
         touch($lock, $mtime);

         // # reload: the same master re-execs; fresh workers; the lock is adopted, not re-stamped
         $output = $probe('reload');
         $previous = $workers;
         $deadline = microtime(true) + 15.0;
         do {
            usleep(100000);
            [$current, $workers] = $topology();
            $fresh = $current === $master
               && count($workers) === 2
               && array_intersect($workers, $previous) === []
               && posix_kill($workers[0], 0)
               && posix_kill($workers[1], 0);
         }
         while ($fresh === false && microtime(true) < $deadline);
         $seen = [...$seen, ...$workers];

         yield assert(
            assertion: str_contains($output, 'Reload signal sent to 1 instance(s)') && $fresh,
            description: 'reload signals the proven master, which serves again with 2 fresh workers — got: ' . $output
         );
         $content = $stamp();
         $kept = (int) @fileinode($lock) === $inode && (int) @filemtime($lock) === $mtime;

         yield assert(
            assertion: $content === "{$master}\n" && $kept,
            description: 'the fresh image adopts the lock without stamping it again: the same inode and mtime, a stamp still naming the master'
         );

         $output = $probe('show');

         yield assert(
            assertion: preg_match('/Status\s+running/', $output) === 1
               && preg_match("/Master PID\s+{$master}\b/", $output) === 1
               && preg_match('/Workers\s+2\/2/', $output) === 1,
            description: 'after the reload the same master and both fresh workers are proven — got: ' . $output
         );

         $served = $ask();

         yield assert(
            assertion: in_array($served, $workers, true),
            description: "a fresh worker answers on the handed-over listener (PID {$served})"
         );

         // # stop
         $output = $probe('stop');
         $alive = $settle($master) || $settle($workers[0] ?? 0) || $settle($workers[1] ?? 0);

         yield assert(
            assertion: str_contains($output, 'result:true') && str_contains($output, 'SUCCESS') && $alive === false,
            description: 'stop terminates the proven daemon and its workers — got: ' . $output
         );

         $output = $probe('show');

         yield assert(
            assertion: str_contains($output, 'result:false') && str_contains($output, 'No running instance found'),
            description: 'show after the stop finds nothing running — got: ' . $output
         );
      }
      finally {
         // @ Cleanup — the daemon's session first, then the tree
         // ! A daemon whose document never named it is in no list: while
         //   its lock is held, the stamp names its master — the leader of
         //   the session (and group) its workers share
         $Lock = @fopen($lock, 'rb');
         $held = $Lock !== false && flock($Lock, LOCK_SH | LOCK_NB) === false;
         $Lock !== false && fclose($Lock);
         $holder = (int) trim($stamp());
         if ($held && $holder > 1 && posix_getsid($holder) === $holder) {
            posix_kill(-$holder, SIGKILL);
         }
         [$late, $lateWorkers] = $topology();
         foreach ([...$seen, $late, ...$lateWorkers] as $PID) {
            if ($PID > 1 && posix_kill($PID, 0)) {
               posix_kill($PID, SIGKILL);
            }
         }
         if (is_dir($base) && is_link($base) === false) {
            $Entries = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
               RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($Entries as $Entry) {
               $path = (string) $Entry->getPathname();
               $Entry->isDir() && $Entry->isLink() === false ? @rmdir($path) : @unlink($path);
            }
            @rmdir($base);
         }
      }
   }
);
