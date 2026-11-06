<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_STORAGE_DIR;
use const FILE_APPEND;
use const PHP_BINARY;
use function array_diff;
use function array_filter;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_reverse;
use function array_search;
use function array_values;
use function assert;
use function chmod;
use function class_exists;
use function count;
use function decoct;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getenv;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_decode;
use function json_encode;
use function lchgrp;
use function lchown;
use function lstat;
use function microtime;
use function mkdir;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function posix_get_last_error;
use function posix_getpwnam;
use function posix_getuid;
use function posix_kill;
use function preg_match_all;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function rmdir;
use function rtrim;
use function scandir;
use function shell_exec;
use function str_contains;
use function str_repeat;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function symlink;
use function trim;
use function unlink;
use function usleep;
use function var_export;
use Throwable;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handler;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Suites;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs as TCPConfigs;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache as SessionCache;


if (! class_exists(TCPServerCLIStorageProbe::class, false)) {
   /** A node whose workers write `cache/` and `sessions/` (the HTTP node declares only `sessions/`). */
   class TCPServerCLIStorageProbe extends TCPServer
   {
      protected const array DIRECTORIES = [
         'cache' => 0o775,
         'sessions' => 0o700
      ];

      public function examine (): null|string
      {
         return $this->provision(static::DIRECTORIES);
      }
   }
   /** A node whose workers take 300 ms to demote — the window, made wide. */
   class TCPServerCLISlowProbe extends TCPServer
   {
      public static function boot (mixed $Environment): void
      {
      }

      public function instance ()
      {
         usleep(300_000);

         return parent::instance();
      }
   }
}


/**
 * A root launch prepares what its demoted workers write, or refuses; and the
 * demoted master can signal every worker as soon as start() returns.
 *
 * SESS-4 — the workers write what this probe node declares (`cache/` and
 * `sessions/`; the HTTP node declares only `sessions/`) as the runtime
 * identity, so those directories must be theirs before the fork:
 *
 *   S1) absent `cache/` and `sessions/`: created and handed to the runtime
 *       identity, `sessions/` owner-only (0700);
 *   S2) a pre-existing root-owned `cache/`: refused, the reason names it;
 *   S3) both already the runtime identity's: accepted, untouched;
 *   S4) a link where `sessions/` should be: refused — a worker's directory is
 *       never a target somebody chose — and link and target are left alone;
 *   S6) a runtime-owned `sessions/` opener than 0700: refused, naming the chmod;
 *   S7) a runtime-owned `cache/` of another group: used as is, never chowned;
 *   S8) a group/world-writable ancestor: said in a warning, the launch goes on;
 *   R1) root building the session store over a runtime-owned `sessions/` with a
 *       link planted at its records directory: refused by its owner, nothing
 *       created through the link;
 *   R2) a root-owned `sessions/` with a link at `sessions/cache`: refused by the
 *       records directory check;
 *   S5) start() over a root-owned `cache/`: the launch exits 1 before any fork
 *       and says "Server not started", naming the directory.
 *
 * TEST-14 — the master demotes right after the fork, each worker only once its
 * listener is in place; a signal sent in between is refused (EPERM):
 *
 *   W1) a Test-mode server whose workers take 300 ms to demote: right after
 *       start() returns, the demoted master can signal every one of them.
 *
 * The legs run as UID 0 — this case re-runs itself inside an unprivileged user
 * namespace (`unshare --user` + `newuidmap`), as `1.6-store-root-handoff` does.
 * Without one the case is skipped and says so; `BOOTGLY_REQUIRE_ROOT_LEGS=1`
 * turns an unavailable sandbox into a failure.
 */
$root = posix_getuid() === 0;
$leg = $root && getenv('BOOTGLY_ROOT_LEG') === '1';
$capable = $root
   || (
      function_exists('proc_open')
      && trim((string) shell_exec('command -v unshare 2>/dev/null')) !== ''
      && trim((string) shell_exec('command -v newuidmap 2>/dev/null')) !== ''
      && (int) trim((string) shell_exec('grep -c "^$(id -un):" /etc/subuid 2>/dev/null')) > 0
      && trim((string) shell_exec('unshare --user --map-root-user true >/dev/null 2>&1 && echo yes')) === 'yes'
   );

return new Test(
   description: 'A root launch prepares its workers\' storage directories or refuses to start, root never follows what the runtime identity planted, and the demoted master can signal every worker once start() returns',
   skip: $capable === false && getenv('BOOTGLY_REQUIRE_ROOT_LEGS') !== '1',
   test: function () use ($leg) {
      // ? The legs themselves — only with the storage redirected to a fixture
      if ($leg) {
         // ! The legs purge and chown under the storage root: never anywhere but
         //   the fixture the outer run handed over. A runner that lost the
         //   prepend (a re-exec dropping `-d`) fails here, touching nothing
         $fixture = (string) getenv('BOOTGLY_ROOT_LEG_STORAGE');
         if ($fixture === '' || BOOTGLY_STORAGE_DIR !== "{$fixture}/") {
            yield assert(
               assertion: false,
               description: 'the root legs run only on the fixture storage (fixture=' . var_export($fixture, true)
                  . ', storage=' . BOOTGLY_STORAGE_DIR . ')'
            );

            return;
         }
         $entry = posix_getpwnam('daemon');
         $runtime = is_array($entry) ? (int) $entry['uid'] : 1;
         $group = is_array($entry) ? (int) $entry['gid'] : 1;
         $cache = BOOTGLY_STORAGE_DIR . 'cache';
         $sessions = BOOTGLY_STORAGE_DIR . 'sessions';
         $aside = BOOTGLY_STORAGE_DIR . 'root-leg';
         $trace = "$aside/probe.log";
         $marker = dirname(rtrim(BOOTGLY_STORAGE_DIR, '/')) . '/legs.json';
         $legs = [];

         $purge = static function (string $path) use (&$purge): void {
            if (is_dir($path) && is_link($path) === false) {
               foreach (array_diff((array) @scandir($path), ['.', '..']) as $child) {
                  $purge("$path/$child");
               }
               @rmdir($path);

               return;
            }
            @unlink($path);
         };
         $reset = static function () use ($purge, $cache, $sessions, $aside, $trace): void {
            $purge($cache);
            $purge($sessions);
            $purge($aside);
            @mkdir(BOOTGLY_STORAGE_DIR . 'pids', 0o755, true);
            @mkdir($aside, 0o755, true);
            // ! The trace must stay writable after a probe demotes
            @file_put_contents($trace, '');
            @chmod($trace, 0o666);
         };
         $owned = static function (string $path, int $uid): bool {
            $inode = @lstat($path);

            return is_array($inode) && (int) $inode['uid'] === $uid;
         };
         $mode = static function (string $path): int {
            $inode = @lstat($path);

            return is_array($inode) ? (int) $inode['mode'] & 0o777 : -1;
         };
         $examine = static function (): null|string {
            $Probe = new TCPServerCLIStorageProbe(Modes::Test);
            $Probe->configure(new TCPConfigs(host: '127.0.0.1', port: 65002, workers: 1, user: 'daemon'));

            return $Probe->examine();
         };
         $probe = static fn (): string => ', probe: ' . trim((string) @file_get_contents($trace));

         try {
            // @ S1) Fresh: both created and handed over
            $reset();
            $refusal = $examine();
            $legs['S1'] = $refusal === null
               && $owned($cache, $runtime)
               && $owned($sessions, $runtime)
               && $mode($sessions) === 0o700;

            yield assert(
               assertion: $legs['S1'],
               description: 'absent cache/ and sessions/ are created and handed to the runtime identity, sessions/ owner-only '
                  . '(refusal=' . var_export($refusal, true) . ', sessions mode=' . decoct($mode($sessions)) . ')'
            );

            // @ S2) A pre-existing root-owned cache/: refused, by name
            $reset();
            @mkdir($cache, 0o755);
            $refusal = $examine();
            $legs['S2'] = $refusal !== null
               && str_contains($refusal, 'storage/cache')
               && str_contains($refusal, "`chown -R daemon: {$cache}`")
               && $owned($cache, 0);

            yield assert(
               assertion: $legs['S2'],
               description: 'a root-owned cache/ of unknown provenance is never given away: the launch is refused and the '
                  . 'reason names it (refusal=' . var_export($refusal, true) . ')'
            );

            // @ S3) Already the runtime identity's: accepted
            $reset();
            foreach ([$cache => 0o775, $sessions => 0o700] as $directory => $permissions) {
               @mkdir($directory, $permissions);
               lchown($directory, $runtime);
               lchgrp($directory, $group);
            }
            $refusal = $examine();
            $legs['S3'] = $refusal === null && $owned($cache, $runtime) && $owned($sessions, $runtime);

            yield assert(
               assertion: $legs['S3'],
               description: 'cache/ and sessions/ already owned by the runtime identity are accepted '
                  . '(refusal=' . var_export($refusal, true) . ')'
            );

            // @ S4) A link where sessions/ should be: refused, both left alone
            $reset();
            $target = "$aside/elsewhere";
            @mkdir($target, 0o700);
            lchown($target, $runtime);
            lchgrp($target, $group);
            symlink($target, $sessions);
            $refusal = $examine();
            $legs['S4'] = $refusal !== null
               && str_contains($refusal, 'storage/sessions is a link')
               && is_link($sessions)
               && $owned($target, $runtime)
               && array_values(array_diff((array) @scandir($target), ['.', '..'])) === [];

            yield assert(
               assertion: $legs['S4'],
               description: 'a link where sessions/ should be is refused — the session handler refuses a linked key directory — '
                  . 'and neither the link nor its target is touched (refusal=' . var_export($refusal, true) . ')'
            );

            // @ S6) A runtime-owned sessions/ opener than 0700: refused
            $reset();
            @mkdir($sessions, 0o700);
            chmod($sessions, 0o775);
            lchown($sessions, $runtime);
            lchgrp($sessions, $group);
            $refusal = $examine();
            $legs['S6'] = $refusal !== null
               && str_contains($refusal, 'storage/sessions is group/world-writable (775)')
               && str_contains($refusal, 'chmod 700')
               && str_contains($refusal, 'chown') === false
               && str_contains($refusal, 'as daemon')
               && $mode($sessions) === 0o775;

            yield assert(
               assertion: $legs['S6'],
               description: 'a runtime-owned sessions/ that is group-writable is refused with the chmod to run, and left as found '
                  . '(refusal=' . var_export($refusal, true) . ')'
            );

            // @ S7) Already the runtime identity's, another group: used as is
            $reset();
            @mkdir($cache, 0o775);
            lchown($cache, $runtime);
            lchgrp($cache, 0);
            @mkdir($sessions, 0o700);
            lchown($sessions, $runtime);
            lchgrp($sessions, $group);
            $refusal = $examine();
            $inode = @lstat($cache);
            $legs['S7'] = $refusal === null
               && is_array($inode)
               && (int) $inode['uid'] === $runtime
               && (int) $inode['gid'] === 0;

            yield assert(
               assertion: $legs['S7'],
               description: 'a directory the runtime identity already owns is used as is — its group is never replaced '
                  . '(refusal=' . var_export($refusal, true) . ', gid=' . (is_array($inode) ? $inode['gid'] : 'none') . ')'
            );

            // @ S8) An ancestor the session store refuses: said, not refused
            $reset();
            $Captured = new class ($trace) extends Handler {
               private string $trace;

               public function __construct (string $trace)
               {
                  parent::__construct();

                  $this->trace = $trace;
               }

               protected function write (string $formatted, Record $Record): bool
               {
                  return @file_put_contents($this->trace, "{$Record->message}\n", FILE_APPEND) !== false;
               }
            };
            // ! The storage root itself must stay safe (the process state
            //   refuses a group-writable one): the fixture above it opens up
            $above = dirname(rtrim(BOOTGLY_STORAGE_DIR, '/'));
            $before = $mode($above);
            $warned = static function (int $permissions) use ($above, $before, $Captured, $examine, $trace): array {
               @file_put_contents($trace, '');
               chmod($above, $permissions);
               Logger::$Tap = $Captured;
               try {
                  $refusal = $examine();
               }
               finally {
                  Logger::$Tap = null;
                  chmod($above, $before);
               }

               return [$refusal, (string) @file_get_contents($trace)];
            };
            [$safeRefusal, $safe] = $warned($before);
            [$refusal, $said] = $warned(0o777);
            [$stickyRefusal, $sticky] = $warned(0o1777);
            $legs['S8'] = $safeRefusal === null && str_contains($safe, 'its ancestor') === false
               && $refusal === null
               && str_contains($said, "its ancestor {$above} is group/world-writable without the sticky bit — `chmod go-w {$above}`")
               && str_contains($said, 'chown') === false
               && $stickyRefusal === null && str_contains($sticky, 'its ancestor') === false
               && $owned($sessions, $runtime);

            yield assert(
               assertion: $legs['S8'],
               description: 'a group/world-writable ancestor of sessions/ is said in a warning naming it and only its chmod — the launch '
                  . 'is not refused — while a safe tree and a sticky 1777 ancestor warn nothing (refusal=' . var_export($refusal, true)
                  . ', safe=' . var_export(trim($safe), true) . ', sticky=' . var_export(trim($sticky), true) . $probe() . ')'
            );

            // @ R1) Root never builds the session store over the runtime identity's sessions/
            $reset();
            $victim = "$aside/victim";
            @mkdir($victim, 0o755);
            @mkdir($sessions, 0o700);
            lchown($sessions, $runtime);
            lchgrp($sessions, $group);
            symlink($victim, "$sessions/cache");
            lchown("$sessions/cache", $runtime);
            $thrown = '';
            try {
               new SessionCache(['secret' => str_repeat('k', 32)]);
            }
            catch (Throwable $Throwable) {
               $thrown = $Throwable->getMessage();
            }
            $legs['R1'] = $thrown === "Session Cache store directory `{$sessions}` must be owned by the current user (uid 0), not uid {$runtime}."
               && array_values(array_diff((array) @scandir($victim), ['.', '..'])) === [];

            yield assert(
               assertion: $legs['R1'],
               description: 'root building the session store over a runtime-owned sessions/ refuses it by its owner, and nothing is '
                  . 'created through the link planted inside (thrown=' . var_export($thrown, true) . ')'
            );

            // @ R2) A root-owned sessions/ with a link at its records directory: refused by the records check
            $reset();
            @mkdir($sessions, 0o700);
            symlink($victim, "$sessions/cache");
            $thrown = '';
            try {
               new SessionCache(['secret' => str_repeat('k', 32)]);
            }
            catch (Throwable $Throwable) {
               $thrown = $Throwable->getMessage();
            }
            $legs['R2'] = $thrown === "Session Cache store directory `{$sessions}/cache` must be a plain directory, not a link.";

            yield assert(
               assertion: $legs['R2'],
               description: 'a link at sessions/cache is refused by the records directory check itself (thrown=' . var_export($thrown, true) . ')'
            );

            // @ S5) start() over a root-owned cache/: exits 1 before any fork
            $reset();
            @mkdir($cache, 0o755);
            $pid = pcntl_fork();
            if ($pid === 0) {
               Display::$segments = Display::NONE;
               // ! Every record, straight to the trace — no file sink policy in the way
               Logger::$Tap = new class ($trace) extends Handler {
                  private string $trace;

                  public function __construct (string $trace)
                  {
                     parent::__construct();

                     $this->trace = $trace;
                  }

                  protected function write (string $formatted, Record $Record): bool
                  {
                     return @file_put_contents($this->trace, "{$Record->message}\n", FILE_APPEND) !== false;
                  }
               };
               $Probe = new TCPServerCLIStorageProbe(Modes::Test);
               $Probe->configure(new TCPConfigs(host: '127.0.0.1', port: 65002, workers: 1, user: 'daemon'));
               $Probe->on(Events::DataReceive, static fn ($input): string => 'x');
               $Probe->start();
               exit(0);
            }
            $status = 0;
            pcntl_waitpid($pid, $status);
            $exit = $pid > 0 ? pcntl_wexitstatus($status) : -1;
            $said = (string) @file_get_contents($trace);
            $legs['S5'] = $exit === 1
               && str_contains($said, 'Server not started')
               && str_contains($said, 'storage/cache')
               && str_contains($said, 'Forking') === false;

            yield assert(
               assertion: $legs['S5'],
               description: 'start() over a root-owned cache/ exits 1 before forking any worker and says why '
                  . "(exit=$exit" . $probe() . ')'
            );

            // @ W1) The demoted master can signal every worker right after start()
            $reset();
            $Reservation = stream_socket_server('tcp://127.0.0.1:0');
            $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
            $port = (int) substr($name, (int) strrpos($name, ':') + 1);
            if (is_resource($Reservation)) {
               fclose($Reservation);
            }
            $pid = pcntl_fork();
            if ($pid === 0) {
               $step = static function (string $line) use ($trace): void {
                  @file_put_contents($trace, "$line\n", FILE_APPEND);
               };
               try {
                  Display::$segments = Display::NONE;
                  $Server = new TCPServerCLISlowProbe(Modes::Test);
                  $Server->configure(new TCPConfigs(host: '127.0.0.1', port: $port, workers: 2, user: 'daemon'));
                  $Server->on(Events::DataReceive, static fn ($input): string => 'x');
                  $Server->start();
                  // ! The probe runs as the demoted master — what the test arm
                  //   of every E2E suite does first
                  $refused = 0;
                  $Children = (fn () => $this->Process->Children->PIDs)->call($Server);
                  foreach ($Children as $child) {
                     if (@posix_kill($child, 0) === false && posix_get_last_error() === 1) {
                        $refused++;
                     }
                  }
                  $step('uid=' . posix_getuid() . ' workers=' . count($Children) . " refused=$refused");
                  (function (): void {
                     $this->Process->stopping = true;
                     $this->Process->Children->terminate();
                     $this->Process->State->clean();
                  })->call($Server);
                  exit($refused === 0 && count($Children) === 2 && posix_getuid() === $runtime ? 0 : 7);
               }
               catch (Throwable $Throwable) {
                  $step('threw ' . $Throwable::class . ': ' . $Throwable->getMessage());
                  exit(9);
               }
            }
            $status = 0;
            pcntl_waitpid($pid, $status);
            $exit = $pid > 0 ? pcntl_wexitstatus($status) : -1;
            $legs['W1'] = $exit === 0;

            yield assert(
               assertion: $legs['W1'],
               description: 'right after start() returns, the demoted master can signal every worker — even ones that take '
                  . "300 ms to demote (exit=$exit" . $probe() . ')'
            );
         }
         finally {
            @file_put_contents($marker, json_encode($legs));
            $purge($cache);
            $purge($sessions);
            $purge($aside);
         }

         return;
      }

      // ? Outside: re-run this very case as UID 0 — inside a user namespace
      //   unless already root — with the storage redirected to a fixture, and
      //   relay its verdict
      $base = Temporaries::reserve('tcp-root-storage-signals');
      $storage = "$base/storage";
      $prepend = "$base/prepend.php";
      $runner = "$base/run.sh";
      $marker = "$base/legs.json";
      $Process = null;
      $pipes = [];
      $output = '';
      $verdict = null;
      $legs = null;

      try {
         mkdir($storage, 0o755, true);
         // ! The runtime identity the probes demote to must reach the fixture
         chmod($base, 0o711);
         file_put_contents($prepend, "<?php\ndefine('BOOTGLY_STORAGE_BASE', '$storage');\ndefine('BOOTGLY_STORAGE_DIR', '$storage/');\n");

         // ! This case's own coordinates in the runner (1-based)
         /** @var Suites $Suites */
         $Suites = include BOOTGLY_ROOT_DIR . 'tests/autoboot.php';
         $suite = (int) array_search('Bootgly/WPI/Interfaces/TCP_Server_CLI/tests/', $Suites->directories, true) + 1;
         preg_match_all('/\'(\d+(?:\.\d+)*-[^\']+)\'/', (string) file_get_contents(__DIR__ . '/autoboot.php'), $registered);
         $case = (int) array_search('1.19-root-launch-storage-signals', $registered[1], true) + 1;

         $inner = "$base/inner.sh";
         file_put_contents($inner, "#!/bin/bash\ncd '$base'\n"
            . "env AI_AGENT=1 BOOTGLY_ROOT_LEG=1 BOOTGLY_ROOT_LEG_STORAGE='$storage' " . PHP_BINARY
            // ! Assertions on up front: the runner would otherwise re-exec itself
            //   with its own `-d` flags only, dropping the prepend
            . " -d zend.assertions=1 -d bootgly.assertions=1 -d opcache.jit=0 -d auto_prepend_file='$prepend' '" . BOOTGLY_ROOT_DIR . "bootgly' test $suite $case\n"
            . "s=\$?\nrm -rf '$storage'\nexit \$s\n");
         chmod($inner, 0o700);
         $script = <<<RUNNER
         #!/bin/bash
         set -u
         cd '{$base}'
         if [ "\$(id -u)" = "0" ]; then
            exec bash '{$inner}'
         fi
         command -v unshare   >/dev/null 2>&1 || { echo "no-unshare";   exit 3; }
         command -v newuidmap >/dev/null 2>&1 || { echo "no-newuidmap"; exit 3; }
         SUB_UID=\$(grep "^\$(id -un):" /etc/subuid | head -1 | cut -d: -f2)
         SUB_UID_N=\$(grep "^\$(id -un):" /etc/subuid | head -1 | cut -d: -f3)
         SUB_GID=\$(grep "^\$(id -un):" /etc/subgid | head -1 | cut -d: -f2)
         SUB_GID_N=\$(grep "^\$(id -un):" /etc/subgid | head -1 | cut -d: -f3)
         [ -n "\$SUB_UID" ] && [ -n "\$SUB_GID" ] || { echo "no-subuid"; exit 3; }
         FIFO='{$base}/.gate'; mkfifo "\$FIFO" || { echo "no-fifo"; exit 3; }
         unshare --user bash -c "read gate < '\$FIFO'; exec bash '{$inner}'" &
         NSPID=\$!
         for _ in \$(seq 1 100); do [ -e "/proc/\$NSPID/uid_map" ] && break; sleep 0.05; done
         newuidmap "\$NSPID" 0 "\$(id -u)" 1 1 "\$SUB_UID" "\$SUB_UID_N" || { echo "map-uid-failed"; kill \$NSPID 2>/dev/null; exit 3; }
         newgidmap "\$NSPID" 0 "\$(id -g)" 1 1 "\$SUB_GID" "\$SUB_GID_N" || { echo "map-gid-failed"; kill \$NSPID 2>/dev/null; exit 3; }
         echo gate > "\$FIFO"
         wait \$NSPID
         exit \$?
         RUNNER;
         file_put_contents($runner, $script);
         chmod($runner, 0o700);

         $Process = proc_open(
            ['/bin/bash', $runner],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $base
         );
         $status = [];
         if (is_resource($Process)) {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + 120.0;
            do {
               foreach ([1, 2] as $index) {
                  $chunk = stream_get_contents($pipes[$index]);
                  if ($chunk !== false) {
                     $output .= $chunk;
                  }
               }
               $status = proc_get_status($Process);
               if (($status['running'] ?? false) === false) {
                  break;
               }
               usleep(50000);
            }
            while (microtime(true) < $deadline);
            if (($status['running'] ?? false) === true) {
               proc_terminate($Process);
               usleep(200000);
            }
            foreach ([1, 2] as $index) {
               $chunk = stream_get_contents($pipes[$index]);
               if ($chunk !== false) {
                  $output .= $chunk;
               }
               fclose($pipes[$index]);
            }
            proc_close($Process);
            $Process = null;
         }

         // ! The inner runner's JSON is its last line; the legs marker pins WHICH case ran
         foreach (array_reverse(explode("\n", $output)) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '{') {
               $verdict = json_decode($line, true);
               break;
            }
         }
         $legs = is_file($marker) ? json_decode((string) file_get_contents($marker), true) : null;
      }
      finally {
         if (is_resource($Process)) {
            proc_terminate($Process);
            proc_close($Process);
         }
         // ? Everything the namespace left behind is owned by this user again
         $purge = static function (string $path) use (&$purge): void {
            if (is_dir($path) && is_link($path) === false) {
               foreach (array_diff((array) @scandir($path), ['.', '..']) as $child) {
                  $purge("$path/$child");
               }
               @rmdir($path);

               return;
            }
            @unlink($path);
         };
         $purge($base);
      }

      // ? The sandbox proved unavailable at run time: the host's capability,
      //   not this contract — said, and a failure only where a lane insists
      $first = trim((string) (explode("\n", trim($output))[0] ?? ''));
      $unavailable = ['no-unshare', 'no-newuidmap', 'no-subuid', 'no-fifo', 'map-uid-failed', 'map-gid-failed'];
      if ($verdict === null && in_array($first, $unavailable, true)) {
         yield assert(
            assertion: getenv('BOOTGLY_REQUIRE_ROOT_LEGS') !== '1',
            description: "root legs NOT run — the user namespace sandbox is unavailable on this host ($first)"
               . (getenv('BOOTGLY_REQUIRE_ROOT_LEGS') === '1' ? ' — and BOOTGLY_REQUIRE_ROOT_LEGS=1 demands them' : '')
         );

         return;
      }

      $cases = is_array($verdict) ? ($verdict['cases'] ?? []) : [];
      $failures = is_array($verdict) ? array_map(
         static fn (array $failure): string => (string) ($failure['message'] ?? ''),
         $verdict['failures'] ?? []
      ) : [];
      $expected = ['S1', 'S2', 'S3', 'S4', 'S6', 'S7', 'S8', 'R1', 'R2', 'S5', 'W1'];
      $ran = is_array($legs) ? array_keys($legs) : [];

      yield assert(
         assertion: is_array($verdict)
            && ($verdict['result'] ?? null) === 'passed'
            && ($cases['passed'] ?? 0) === 1
            && ($cases['skipped'] ?? 0) === 0
            && is_array($legs)
            && array_values(array_intersect($expected, $ran)) === $expected
            && array_filter($legs, static fn (mixed $passed): bool => $passed !== true) === [],
         description: 'every root leg passes as UID 0 (legs run: ' . implode(',', $ran) . ')'
            . (is_array($verdict)
               ? ' (result=' . ($verdict['result'] ?? '?') . ', failures: ' . implode(' | ', $failures) . ')'
               : " (no verdict; runner said: $first)")
            . (is_array($verdict) && ($verdict['result'] ?? null) === 'passed' ? '' : ' output tail: ' . substr(trim($output), -700))
      );
   }
);
