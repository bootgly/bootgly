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
use function escapeshellarg;
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
use function link;
use function lstat;
use function microtime;
use function mkdir;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function posix_getpwnam;
use function posix_getuid;
use function preg_match_all;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function rename;
use function rmdir;
use function rtrim;
use function scandir;
use function shell_exec;
use function str_contains;
use function stream_get_contents;
use function stream_set_blocking;
use function substr;
use function symlink;
use function time;
use function touch;
use function trim;
use function umask;
use function unlink;
use function usleep;
use function var_export;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Handlers\File as FileHandler;
use Bootgly\ACI\Logs\Handlers\Memory as MemoryHandler;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Suites;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs as TCPConfigs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as UDPServer;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs as UDPConfigs;


if (! class_exists(TCPServerCLIRootProbe::class, false)) {
   class TCPServerCLIRootProbe extends TCPServer
   {
      public function store (bool $starting = false): void
      {
         parent::store($starting);
      }
      public function hold (string $message): void
      {
         $this->Logger->log(notice: $message);
      }
      public function demote (): void
      {
         parent::demote();
      }
   }
   class UDPServerCLIRootProbe extends UDPServer
   {
      public function store (bool $starting = false): void
      {
         parent::store($starting);
      }
      public function hold (string $message): void
      {
         $this->Logger->log(notice: $message);
      }
      public function demote (): void
      {
         parent::demote();
      }
   }
}


/**
 * A root launch with a runtime `user` never writes a log sink as root.
 *
 * The legs run as UID 0 — this case re-runs itself inside an unprivileged
 * user namespace (`unshare --user` + `newuidmap`, no host privilege) with the
 * storage redirected to a fixture; a suite that already runs as root takes the
 * same path without the namespace. Each leg forks a probe that does what the
 * daemon does at boot — store(), one record while still root, demote() — and
 * the root parent audits the filesystem afterwards:
 *
 *   A) fresh install (TCP and UDP): the log file is CREATED by the runtime
 *      identity, the notice is its first record, the record held while root
 *      ran follows;
 *   B) a link the runtime identity planted at the log pathname, aimed at a
 *      root-owned canary: the canary keeps its bytes, nothing is written;
 *   C) a link where `storage/logs` should be — owned by a third identity, or
 *      by root and aimed at an empty directory of root's: refused before any
 *      ownership change, link and target left exactly as found;
 *   D) an existing runtime-owned log older than a day: rotated and recreated
 *      by the runtime identity, never by root;
 *   E) a project-configured sink: held like the fallback, created by the
 *      runtime identity, no notice;
 *   F) a demotion that fails (unknown user): the probe exits 1, root has
 *      created no file, and the held records reach the system logger;
 *   G) a privileged file sink writes through a link of root's, refuses a link of
 *      another identity on the way and an attacker-owned sticky directory, and
 *      accepts sticky directories of third identities on the way only once
 *      store() named whom to guard against;
 *   H) a root-owned tree renamed onto storage/logs before the launch is left as
 *      found — root gives away only the directory it created in this launch;
 *   J) a third identity's storage/logs is left as found too;
 *   I) two servers in one root process share one hold; the second, settling
 *      first, installs the withheld sinks, and the first's pending notice
 *      reaches them when the process ends;
 *   K) a sink registered between configure() and start() — the Web App's own
 *      File sink — takes the fallback's place in the hold: withheld like it,
 *      never live as root, created by the runtime identity with every held
 *      record once, and no fallback file or notice appears;
 *   L) one registered after start() but before the demotion takes the
 *      fallback's place at settle() the same way;
 *   M) one registered at a root-only path while root runs is never written
 *      by root: the withheld sink writes nothing until the runtime identity
 *      holds it, and then only what that identity may;
 *   N) the fallback yielding does not silence what root found wrong with
 *      `storage/logs`: that notice is still the first record, in the sink
 *      that took the fallback's place.
 *
 * The legs need an unprivileged user namespace (`unshare --user` + `newuidmap`
 * with a subordinate uid range). Without one the case is skipped and says so;
 * a lane that must have the proof sets `BOOTGLY_REQUIRE_ROOT_LEGS=1`, which
 * turns an unavailable sandbox into a failure.
 *
 * The probe must survive demote() in every leg that demotes — a sink that
 * cannot write must never take the daemon down.
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
   description: 'A root launch hands storage/logs over and installs the log sinks only as the runtime identity (TCP and UDP)',
   skip: $capable === false && getenv('BOOTGLY_REQUIRE_ROOT_LEGS') !== '1',
   test: function () use ($leg) {
      // ? The legs themselves — only with the storage redirected to a fixture
      if ($leg) {
         $entry = posix_getpwnam('daemon');
         $runtime = is_array($entry) ? (int) $entry['uid'] : 1;
         $group = is_array($entry) ? (int) $entry['gid'] : 1;
         $logs = BOOTGLY_STORAGE_DIR . 'logs';
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
         $reset = static function () use ($purge, $logs, $aside, $trace): void {
            $purge($logs);
            $purge($aside);
            @mkdir(BOOTGLY_STORAGE_DIR . 'pids', 0o755, true);
            @mkdir($aside, 0o755, true);
            // ! The trace must stay writable after the probe demotes
            @file_put_contents($trace, '');
            @chmod($trace, 0o666);
         };
         $lines = static fn (string $file): array => array_values(array_filter(
            explode("\n", (string) @file_get_contents($file))
         ));
         // @ One boot, as the daemon does it: store() as root, a record while
         //   still root, then demote() — in a child, since a demotion is one-way
         $boot = static function (
            string $class = TCPServerCLIRootProbe::class,
            string $user = 'daemon',
            null|Handlers $Project = null,
            null|string $beside = null,
            null|string $between = null
         ) use ($runtime, $trace): int {
            $pid = pcntl_fork();
            if ($pid === 0) {
               $step = static function (string $name) use ($trace): void {
                  @file_put_contents($trace, "step: $name\n", FILE_APPEND);
               };
               try {
                  Display::$segments = Display::NONE;
                  Logger::$Sinks = $Project;
                  Logger::$Tap = new FileHandler($trace);
                  $step('construct');
                  $Probe = new $class(Modes::Daemon);
                  $step('configure');
                  $Configs = $class === UDPServerCLIRootProbe::class
                     ? new UDPConfigs(host: '127.0.0.1', port: 65001, workers: 1, user: $user)
                     : new TCPConfigs(host: '127.0.0.1', port: 65001, workers: 1, user: $user);
                  $Probe->configure($Configs);
                  // ! Logged after configure() and BEFORE the explicit store():
                  //   the transport adopt() must already be holding
                  $Probe->hold('configured-before-store');
                  if ($between !== null) {
                     // ! Registered between configure() and start() — as the
                     //   Web App does, beside the hold
                     Logger::$Sinks?->push(new FileHandler($between));
                  }
                  $step('store');
                  $Probe->store(starting: true);
                  if ($beside !== null) {
                     // ! Pushed beside the hold, between store() and demote()
                     Logger::$Sinks?->push(new FileHandler($beside));
                  }
                  $step('hold');
                  $Probe->hold('held-while-root');
                  $step('demote');
                  $Probe->demote();
                  $step('demoted as uid ' . posix_getuid());
               }
               catch (\Throwable $Throwable) {
                  $step('threw ' . $Throwable::class . ': ' . $Throwable->getMessage() . ' @ ' . $Throwable->getFile() . ':' . $Throwable->getLine());
                  exit(9);
               }
               exit(posix_getuid() === $runtime ? 0 : 7);
            }
            $status = 0;
            pcntl_waitpid($pid, $status);

            return $pid > 0 ? pcntl_wexitstatus($status) : -1;
         };
         $owned = static function (string $path, int $uid): bool {
            $inode = @lstat($path);

            return is_array($inode) && (int) $inode['uid'] === $uid;
         };
         $probe = static fn (): string => ', probe: ' . trim((string) @file_get_contents($trace));

         try {
            // @@ A) Fresh install — TCP, then UDP
            foreach ([
               'TCP' => [TCPServerCLIRootProbe::class, "$logs/TCP.Server.CLI.log"],
               'UDP' => [UDPServerCLIRootProbe::class, "$logs/UDP.Server.CLI.log"],
            ] as $name => [$class, $log]) {
               $reset();
               $exit = $boot($class);
               $records = $lines($log);
               $legs["A-$name"] = $exit === 0
                  && $owned($logs, $runtime)
                  && $owned($log, $runtime)
                  && is_link($log) === false
                  && count($records) === 3
                  && str_contains($records[0], 'No global log sinks configured')
                  && str_contains($records[1], 'configured-before-store')
                  && str_contains($records[2], 'held-while-root');

               yield assert(
                  assertion: $legs["A-$name"],
                  description: "$name fresh install: the directory and the log file belong to the runtime identity, "
                     . 'the notice is the first record, the record logged right after configure() was held and follows, '
                     . "then the one held before demote() (exit=$exit, records=" . count($records) . $probe() . ')'
               );
            }
            $log = "$logs/TCP.Server.CLI.log";

            // @@ B) A planted link at the log pathname → root-owned canary
            $reset();
            $canary = "$aside/canary";
            file_put_contents($canary, "# root canary\n");
            chmod($canary, 0o666);
            @mkdir($logs, 0o775);
            lchown($logs, $runtime);
            lchgrp($logs, $group);
            symlink($canary, $log);
            lchown($log, $runtime);
            lchgrp($log, $group);
            $exit = $boot();
            $inside = array_values(array_diff((array) @scandir($logs), ['.', '..']));
            $legs['B'] = $exit === 0
               && (string) file_get_contents($canary) === "# root canary\n"
               && $owned($canary, 0)
               && is_link($log) === true
               && $inside === ['TCP.Server.CLI.log'];

            yield assert(
               assertion: $legs['B'],
               description: 'planted link: the probe still comes up, the world-writable root canary keeps its bytes and owner, '
                  . "the link stays and nothing else appears in the directory (exit=$exit, entries=" . implode(',', $inside) . ')'
            );

            // @@ C) A link where storage/logs should be — a third identity's, then root's to an empty dir of root's
            foreach (['third' => 3, 'root' => 0] as $who => $uid) {
               $reset();
               $trap = "$aside/trap";
               @mkdir($trap, 0o755);
               symlink($trap, $logs);
               lchown($logs, $uid);
               lchgrp($logs, $uid);
               $exit = $boot();
               $trapped = array_values(array_diff((array) @scandir($trap), ['.', '..']));
               $legs["C-$who"] = $exit === 0
                  && is_link($logs) === true
                  && $owned($logs, $uid)
                  && $owned($trap, 0)
                  && $trapped === [];

               yield assert(
                  assertion: $legs["C-$who"],
                  description: "a link owned by $who where the directory should be is left as found: root follows nothing, "
                     . "hands nothing over and creates nothing in the target (exit=$exit, entries=" . implode(',', $trapped) . ')'
               );
            }

            // @@ D) An existing runtime-owned log older than a day
            $reset();
            @mkdir($logs, 0o775);
            lchown($logs, $runtime);
            lchgrp($logs, $group);
            file_put_contents($log, "{\"old\":1}\n");
            lchown($log, $runtime);
            lchgrp($log, $group);
            touch($log, time() - 2 * 86400);
            $exit = $boot();
            $records = $lines($log);
            $legs['D'] = $exit === 0
               && $owned("$log.1", $runtime)
               && (string) file_get_contents("$log.1") === "{\"old\":1}\n"
               && $owned($log, $runtime)
               && count($records) === 3
               && str_contains($records[0], 'No global log sinks configured');

            yield assert(
               assertion: $legs['D'],
               description: 'a day-old log is rotated and recreated by the runtime identity, never by root '
                  . "(exit=$exit, records=" . count($records) . $probe() . ')'
            );

            // @@ E) A project-configured sink is held like the fallback
            $reset();
            $Project = new Handlers;
            $Project->push(new FileHandler(BOOTGLY_STORAGE_DIR . 'logs/project/{channel}.log'));
            $exit = $boot(TCPServerCLIRootProbe::class, 'daemon', $Project, BOOTGLY_STORAGE_DIR . 'logs/beside/{channel}.log');
            $own = "$logs/project/TCP.Server.CLI.log";
            $records = $lines($own);
            $beside = $lines("$logs/beside/TCP.Server.CLI.log");
            $legs['E'] = $exit === 0
               && $owned($logs, $runtime)
               && $owned("$logs/project", $runtime)
               && $owned($own, $runtime)
               && count($records) === 2
               && str_contains($records[0], 'configured-before-store')
               && str_contains($records[1], 'held-while-root')
               && is_file($log) === false
               && $owned("$logs/beside/TCP.Server.CLI.log", $runtime)
               && count($beside) === 2
               && str_contains($beside[1], 'held-while-root');

            yield assert(
               assertion: $legs['E'],
               description: 'a project-configured sink is withheld from root too: its file is created by the runtime identity '
                  . 'with the held record, no fallback file or notice appears, and a handler pushed beside the hold rides '
                  . "along at settle() (exit=$exit, records=" . count($records) . ', beside=' . count($beside) . $probe() . ')'
            );

            // @@ F) A demotion that fails: no file by root, the probe exits 1, and
            //       what it held reaches the system logger (checked where a journal is readable)
            $reset();
            $exit = $boot(TCPServerCLIRootProbe::class, 'no-such-user-bootgly');
            $inside = is_dir($logs) ? array_values(array_diff((array) @scandir($logs), ['.', '..'])) : [];
            $legs['F'] = $exit === 1 && $inside === [];

            yield assert(
               assertion: $legs['F'],
               description: 'an unknown runtime user ends the probe with exit 1 and root has created no log file '
                  . "(exit=$exit, entries=" . implode(',', $inside) . '); the held records are checked in the journal outside the namespace'
            );

            // @@ H) Root gives away only what THIS launch created. A root-owned
            //       storage/logs from before — the runtime identity owns the
            //       name and can rename any tree of root's onto it — is left
            //       as found, with everything inside, and the notice says so
            $reset();
            @mkdir("$aside/security/tls", 0o700, true);
            file_put_contents("$aside/security/tls/private-key.pem", "root's secret\n");
            chmod("$aside/security/tls/private-key.pem", 0o600);
            rename("$aside/security", $logs);
            $exit = $boot();
            $key = @lstat("$logs/tls/private-key.pem");
            $legs['H'] = $exit === 0
               && $owned($logs, 0)
               && $owned("$logs/tls", 0)
               && is_array($key) && (int) $key['uid'] === 0 && ((int) $key['mode'] & 0o777) === 0o600
               && str_contains((string) @file_get_contents($trace), 'left as found');

            yield assert(
               assertion: $legs['H'],
               description: 'a root-owned tree renamed onto storage/logs before the launch stays root\'s, 0600 key included, '
                  . "and the notice says it was left as found (exit=$exit, key uid=" . ($key['uid'] ?? 'none') . $probe() . ')'
            );

            // @@ J) A pre-existing storage/logs that is somebody else's — a third
            //       identity's here — is left as found too, with the notice
            $reset();
            @mkdir($logs, 0o700);
            lchown($logs, 3);
            lchgrp($logs, 3);
            $exit = $boot();
            $legs['J'] = $exit === 0
               && $owned($logs, 3)
               && str_contains((string) @file_get_contents($trace), 'left as found');

            yield assert(
               assertion: $legs['J'],
               description: "a storage/logs owned by a third identity before the launch stays theirs and the notice says so (exit=$exit)"
            );

            // @@ I) Two servers configured in one root process share ONE hold:
            //       the second adopts it instead of nesting, and the first
            //       server's settle() installs the real sinks with both records
            $reset();
            $pid = pcntl_fork();
            if ($pid === 0) {
               try {
                  Display::$segments = Display::NONE;
                  Logger::$Sinks = null;
                  Logger::$Tap = new FileHandler($trace);
                  $First = new TCPServerCLIRootProbe(Modes::Daemon);
                  $First->configure(new TCPConfigs(host: '127.0.0.1', port: 65001, workers: 1, user: 'daemon'));
                  $Second = new TCPServerCLIRootProbe(Modes::Daemon);
                  $Second->configure(new TCPConfigs(host: '127.0.0.1', port: 65002, workers: 1, user: 'daemon'));
                  $held = count(Logger::$Sinks?->Handlers ?? []);
                  $Second->hold('held-by-the-second');
                  $First->hold('held-by-the-first');
                  // ! The SECOND server settles first: it must install the real
                  //   sinks the first one withheld, not leave the hold in place
                  $Second->demote();
                  $Second->hold('after-the-second-settled');
                  $installed = array_map(static fn (object $Handler): string => $Handler::class, Logger::$Sinks?->Handlers ?? []);
                  @file_put_contents($trace, "step: nested held=$held installed=" . implode(',', $installed) . "\n", FILE_APPEND);
               }
               catch (\Throwable $Throwable) {
                  @file_put_contents($trace, 'step: threw ' . $Throwable->getMessage() . "\n", FILE_APPEND);
                  exit(9);
               }
               exit(posix_getuid() === $runtime ? 0 : 7);
            }
            $status = 0;
            pcntl_waitpid($pid, $status);
            $exit = pcntl_wexitstatus($status);
            $records = $lines($log);
            $nested = (string) @file_get_contents($trace);
            $legs['I'] = $exit === 0
               && str_contains($nested, 'nested held=1 installed=Bootgly\\ACI\\Logs\\Handlers\\File')
               && str_contains($nested, 'Memory') === false
               && count($records) === 4
               && str_contains($records[0], 'held-by-the-second')
               && str_contains($records[1], 'held-by-the-first')
               && str_contains($records[2], 'after-the-second-settled')
               && str_contains($records[3], 'No global log sinks configured');

            yield assert(
               assertion: $legs['I'],
               description: 'a second server in the same root process shares the existing hold (one Memory handler, no nesting) and, '
                  . 'settling first, installs the withheld File sink — no Memory left — with both held records and the next one; '
                  . "the first server's pending notice lands in those sinks when the process ends (exit=$exit, records="
                  . count($records) . $probe() . ')'
            );

            // @@ K) A sink registered between configure() and start() takes the
            //       fallback's place in the hold — withheld, never live as root
            $reset();
            $exit = $boot(TCPServerCLIRootProbe::class, 'daemon', null, null, "$logs/app/{channel}.log");
            $app = "$logs/app/TCP.Server.CLI.log";
            $records = $lines($app);
            $legs['K'] = $exit === 0
               && $owned($logs, $runtime)
               && $owned("$logs/app", $runtime)
               && $owned($app, $runtime)
               && is_file($log) === false
               && count($records) === 2
               && str_contains($records[0], 'configured-before-store')
               && str_contains($records[1], 'held-while-root')
               && str_contains((string) @file_get_contents($app), 'No global log sinks') === false;
            yield assert(
               assertion: $legs['K'],
               description: 'a sink registered between configure() and start() takes the fallback\'s place: withheld from root, '
                  . 'its file is created by the runtime identity with each held record once, and neither the fallback file '
                  . "nor the notice appears (exit=$exit, records=" . count($records) . $probe() . ')'
            );
            // @@ L) Registered after start()'s store() and before the demotion:
            //       the fallback yields at settle() the same way
            $reset();
            $exit = $boot(TCPServerCLIRootProbe::class, 'daemon', null, "$logs/late/{channel}.log");
            $late = "$logs/late/TCP.Server.CLI.log";
            $records = $lines($late);
            $legs['L'] = $exit === 0
               && $owned("$logs/late", $runtime)
               && $owned($late, $runtime)
               && is_file($log) === false
               && count($records) === 2
               && str_contains($records[0], 'configured-before-store')
               && str_contains($records[1], 'held-while-root')
               && str_contains((string) @file_get_contents($late), 'No global log sinks') === false;
            yield assert(
               assertion: $legs['L'],
               description: 'a sink registered after start() and before the demotion takes the fallback\'s place at settle(): '
                  . 'its file is the runtime identity\'s with each held record once, no fallback file, no notice '
                  . "(exit=$exit, records=" . count($records) . $probe() . ')'
            );
            // @@ M) Registered between configure() and start() at a path only root
            //       may write: withheld, so root never writes there — and the
            //       runtime identity, refused, writes nothing either
            $reset();
            @mkdir("$aside/rootonly", 0o755);
            $exit = $boot(TCPServerCLIRootProbe::class, 'daemon', null, null, "$aside/rootonly/{channel}.log");
            $inside = array_values(array_diff((array) @scandir("$aside/rootonly"), ['.', '..']));
            $legs['M'] = $exit === 0
               && $owned("$aside/rootonly", 0)
               && $inside === []
               && is_file($log) === false;
            yield assert(
               assertion: $legs['M'],
               description: 'a sink registered before start() at a root-only path is withheld like the fallback it replaces: '
                  . "root writes nothing there while it runs, and no fallback file appears (exit=$exit, entries=" . implode(',', $inside) . $probe() . ')'
            );
            // @@ N) The fallback yields, but what root found wrong with storage/logs
            //       is still said — first, in the sink that took its place
            $reset();
            @mkdir($logs, 0o700);
            lchown($logs, 3);
            lchgrp($logs, 3);
            @mkdir("$aside/app", 0o755);
            lchown("$aside/app", $runtime);
            lchgrp("$aside/app", $group);
            $exit = $boot(TCPServerCLIRootProbe::class, 'daemon', null, null, "$aside/app/{channel}.log");
            $app = "$aside/app/TCP.Server.CLI.log";
            $records = $lines($app);
            $legs['N'] = $exit === 0
               && $owned($logs, 3)
               && $owned($app, $runtime)
               && count($records) === 3
               && str_contains($records[0], 'left as found')
               && str_contains($records[0], 'No global log sinks') === false
               && str_contains($records[1], 'configured-before-store')
               && str_contains($records[2], 'held-while-root');
            yield assert(
               assertion: $legs['N'],
               description: 'with storage/logs left as found and the fallback yielding to a sink registered before start(), '
                  . 'the handover notice is still that sink\'s first record — without the fallback sentence — then each held '
                  . "record once (exit=$exit, records=" . count($records) . $probe() . ')'
            );
            // @@ G) A privileged writer follows no path another identity can steer:
            //       a link of root's on the way is fine; the same link owned by a third
            //       identity is refused; an attacker-owned STICKY directory is refused too
            //       (its owner may still replace anything in it)
            $reset();
            // ! A sticky directory of a THIRD identity on the way (the temp root
            //   under the fixture is one too — foreign, since the map covers the
            //   overflow uid): refused while nobody is guarded against, accepted
            //   once a real store() — a server configured as root with a runtime
            //   user — names that identity
            @mkdir("$aside/sticky3", 0o1777);
            lchown("$aside/sticky3", 3);
            lchgrp("$aside/sticky3", 3);
            chmod("$aside/sticky3", 0o1777);
            $Kept = new FileHandler("$aside/sticky3/keep.log");
            $unguarded = $Kept->handle(new Record(Levels::Info, 'Web', 'into-a-third-sticky-dir-unguarded'));
            Logger::$Sinks = null;
            $Guarding = new TCPServerCLIRootProbe(Modes::Daemon);
            $Guarding->configure(new TCPConfigs(host: '127.0.0.1', port: 65003, workers: 1, user: 'daemon'));
            Logger::$Sinks = null;
            MemoryHandler::release();
            $guarded = $Kept->handle(new Record(Levels::Info, 'Web', 'into-a-third-sticky-dir-guarded'));
            @mkdir("$aside/real1", 0o755);
            @mkdir("$aside/real2", 0o755);
            symlink("$aside/real1", "$aside/dir");
            $Walked = new FileHandler("$aside/dir/walk.log");
            $first = $Walked->handle(new Record(Levels::Info, 'Web', 'through-a-link-of-roots'));
            unlink("$aside/dir");
            symlink("$aside/real2", "$aside/dir");
            lchown("$aside/dir", 3);
            $second = $Walked->handle(new Record(Levels::Info, 'Web', 'through-a-foreign-link'));
            @mkdir("$aside/sticky", 0o1777);
            lchown("$aside/sticky", $runtime);
            lchgrp("$aside/sticky", $group);
            chmod("$aside/sticky", 0o1777);
            $Sticky = new FileHandler("$aside/sticky/trap.log");
            $third = $Sticky->handle(new Record(Levels::Info, 'Web', 'into-an-attacker-owned-sticky-dir'));
            $mask = umask(0o002);
            $Fresh = new FileHandler("$aside/fresh/sub/made.log");
            $fourth = $Fresh->handle(new Record(Levels::Info, 'Web', 'into-a-directory-root-just-made'));
            umask($mask);
            $made = @lstat("$aside/fresh/sub");
            $legs['G'] = $first === true
               && $fourth === true
               && is_array($made) && ((int) $made['mode'] & 0o777) === 0o755
               && is_file("$aside/real1/walk.log")
               && str_contains((string) file_get_contents("$aside/real1/walk.log"), 'through-a-link-of-roots')
               && $second === false
               && array_diff((array) @scandir("$aside/real2"), ['.', '..']) === []
               && $third === false
               && array_diff((array) @scandir("$aside/sticky"), ['.', '..']) === []
               && $unguarded === false
               && $guarded === true
               && str_contains((string) @file_get_contents("$aside/sticky3/keep.log"), 'guarded')
               && str_contains((string) @file_get_contents("$aside/sticky3/keep.log"), 'unguarded') === false;

            yield assert(
               assertion: $legs['G'],
               description: 'as root, the file sink writes through a link of root\'s, refuses a link of another identity '
                  . 'on the way, refuses an attacker-owned sticky directory — nothing is created in either — creates a '
                  . 'missing directory 0755 whatever the umask, and sticky directories of third identities on the way are '
                  . 'refused until store() names whom to guard against (first=' . var_export($first, true) . ', second='
                  . var_export($second, true) . ', third=' . var_export($third, true) . ', fourth=' . var_export($fourth, true)
                  . ', mode=' . (is_array($made) ? decoct((int) $made['mode'] & 0o777) : 'none') . ', unguarded='
                  . var_export($unguarded, true) . ', guarded=' . var_export($guarded, true) . ')'
            );
         }
         finally {
            @file_put_contents($marker, json_encode($legs));
            $purge($logs);
            $purge($aside);
         }

         return;
      }

      // ? Outside: re-run this very case as UID 0 — inside a user namespace
      //   unless already root — with the storage redirected to a fixture, and
      //   relay its verdict
      $since = '@' . (time() - 1);
      $base = Temporaries::reserve('tcp-root-handoff');
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
         // ! The runtime identity the probe demotes to must be able to reach
         //   the fixture: the reserved directory is private by default
         chmod($base, 0o711);
         file_put_contents($prepend, "<?php\ndefine('BOOTGLY_STORAGE_BASE', '$storage');\ndefine('BOOTGLY_STORAGE_DIR', '$storage/');\n");

         // ! This case's own coordinates in the runner (1-based)
         /** @var Suites $Suites */
         $Suites = include BOOTGLY_ROOT_DIR . 'tests/autoboot.php';
         $suite = (int) array_search('Bootgly/WPI/Interfaces/TCP_Server_CLI/tests/', $Suites->directories, true) + 1;
         preg_match_all('/\'(\d+(?:\.\d+)*-[^\']+)\'/', (string) file_get_contents(__DIR__ . '/autoboot.php'), $registered);
         $case = (int) array_search('1.6-store-root-handoff', $registered[1], true) + 1;

         // ! The inner run, then the fixture storage removed from INSIDE the
         //   namespace — whatever the runtime identity created there can only
         //   be removed by the namespace's root. Its own script: nothing of it
         //   may be expanded by the runner's shell.
         $inner = "$base/inner.sh";
         file_put_contents($inner, "#!/bin/bash\ncd '$base'\n"
            . 'env AI_AGENT=1 BOOTGLY_ROOT_LEG=1 ' . PHP_BINARY
            . " -d opcache.jit=0 -d auto_prepend_file='$prepend' '" . BOOTGLY_ROOT_DIR . "bootgly' test $suite $case\n"
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

      // ? The sandbox proved unavailable at run time (the probe at load said
      //   otherwise): that is the host's capability, not this contract — say so
      //   and do not fail the suite over it, unless a lane insists on the proof
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
      $expected = ['A-TCP', 'A-UDP', 'B', 'C-third', 'C-root', 'D', 'E', 'F', 'H', 'J', 'I', 'K', 'L', 'M', 'N', 'G'];
      $ran = is_array($legs) ? array_keys($legs) : [];

      // ? The records leg F held reach the system logger — read from OUTSIDE
      //   the namespace, where this user's journal is readable (inside it the
      //   mapped identity sees nothing); labelled when no journal is readable
      $readable = trim((string) shell_exec('command -v journalctl 2>/dev/null')) !== ''
         && trim((string) shell_exec('journalctl -n 0 >/dev/null 2>&1; echo $?')) === '0';
      $rescued = null;
      if ($readable) {
         // ! journald commits asynchronously: poll briefly before judging
         $deadline = microtime(true) + 5.0;
         do {
            $journal = (string) shell_exec('journalctl -t bootgly --since ' . escapeshellarg($since) . ' --no-pager -o cat 2>/dev/null');
            $rescued = str_contains($journal, 'held-while-root') && str_contains($journal, 'no-such-user-bootgly');
            if ($rescued === false) {
               usleep(250000);
            }
         }
         while ($rescued === false && microtime(true) < $deadline);
      }

      yield assert(
         assertion: is_array($verdict)
            && ($verdict['result'] ?? null) === 'passed'
            && ($cases['passed'] ?? 0) === 1
            && ($cases['skipped'] ?? 0) === 0
            && is_array($legs)
            && array_values(array_intersect($expected, $ran)) === $expected
            && array_filter($legs, static fn (mixed $passed): bool => $passed !== true) === []
            && $rescued !== false,
         description: 'every root leg passes as UID 0 (legs run: ' . implode(',', $ran) . '; journal: '
            . ($rescued === null ? 'not readable here' : ($rescued ? 'held records rescued to syslog' : 'held records MISSING from syslog')) . ')'
            . (is_array($verdict)
               ? ' (result=' . ($verdict['result'] ?? '?') . ', failures: ' . implode(' | ', $failures) . ')'
               : " (no verdict; runner said: $first)")
            . (is_array($verdict) && ($verdict['result'] ?? null) === 'passed' ? '' : ' output tail: ' . substr(trim($output), -700))
      );
   }
);
