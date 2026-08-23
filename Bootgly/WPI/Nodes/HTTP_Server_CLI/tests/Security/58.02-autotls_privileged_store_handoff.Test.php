<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC C6b — a certificate store minted for a NEW configuration identity
 * must be handed to the runtime identity before the workers demote.
 *
 * `configure()` calls `forge()` while the master is still root, and a changed
 * configuration — a flipped `staging`, an edited `domains` — mints a store under
 * a fresh identity hash, `root:root` and `0700`. `prime()` then hands the storage
 * tree over, but on any boot after the first that root is already the runtime
 * identity's, and `walk()` refuses to descend into a directory that identity can
 * rewrite. That refusal is correct — it is the check/use window C6 exists to
 * close — so the new subtree is reachable only by naming it. Left behind, the
 * demoted workers cannot read `bootstrap.pem` and the startup readiness barrier
 * expires reporting readiness rather than permissions.
 *
 * The legs run the REAL `prime()` as UID 0 inside an unprivileged user namespace
 * (`unshare --user` + `newuidmap`), or directly when the suite is already root.
 * The namespace grants no host privilege: the fixture, the canary and the runtime
 * identity are mapped ids confined to a temp directory, and `BOOTGLY_STORAGE_DIR`
 * points into that fixture so the repository's own storage is never touched.
 *
 * Legs: `changed` is the defect (a store for a new identity under an already
 * handed-over root); `firstboot` and `unchanged` are the controls that must stay
 * quiet; `symlink` is the containment control — aiming the newly walked path at a
 * root-owned canary must refuse the boot and leave the canary untouched.
 *
 * ⚠️ Requires UID 0. It cannot run in CI, which is a property of the defect.
 */
/**
 * @var array{
 *    error:string,
 *    executed:bool,
 *    reason:string,
 *    uid:int,
 *    runtime:int,
 *    distinct:bool,
 *    legs:array<string,array<string,mixed>>
 * } $probe
 */
$probe = [
   'error' => '',
   'executed' => false,
   'reason' => '',
   'uid' => -1,
   'runtime' => -1,
   'distinct' => false,
   'legs' => [],
];

return new Test(
   description: 'a certificate store minted for a new configuration identity is handed to the runtime identity',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $directory = sys_get_temp_dir()
         . '/bootgly-security-c6b-' . bin2hex(random_bytes(6));
      $process = null;
      $pipes = [];

      try {
         if (function_exists('proc_open') === false || function_exists('posix_geteuid') === false) {
            throw new RuntimeException('C6b requires proc_open and POSIX support.');
         }
         if (mkdir($directory, 0755, true) === false) {
            throw new RuntimeException('C6b could not create its fixture directory.');
         }

         $victim = $directory . '/victim.php';
         $runner = $directory . '/run.sh';
         $result = $directory . '/result.json';

         $victimSource = <<<'VICTIM'
<?php
/**
 * C6b leg — executes as UID 0 and drives the REAL prime() over four fixtures.
 */
$root   = $argv[1];
$base   = $argv[2];
$result = $argv[3];

define('BOOTGLY_ROOT_BASE', rtrim($root, '/'));
define('BOOTGLY_ROOT_DIR', rtrim($root, '/') . '/');
// ! Into the fixture, never the repository's own storage.
define('BOOTGLY_STORAGE_BASE', $base . '/storage');
define('BOOTGLY_STORAGE_DIR', $base . '/storage/');

spl_autoload_register(static function (string $class): void {
   $file = BOOTGLY_ROOT_DIR . str_replace('\\', '/', $class) . '.php';
   if (is_file($file)) { include $file; }
});

$report = static function (array $data) use ($result): never {
   file_put_contents($result, json_encode($data));
   exit(0);
};

if (posix_getuid() !== 0) {
   $report(['reason' => 'leg is not UID 0', 'uid' => posix_getuid()]);
}

// ! The demoted runtime identity. Derived, never hardcoded: `daemon` is uid 1 on
//   Debian and 2 on Fedora, and the fixture must agree with what own() chowns to.
$entry = posix_getpwnam('daemon');
$RUNTIME = is_array($entry) ? (int) $entry['uid'] : 1;

/** Force a whole tree to one identity — fixture setup only. */
$force = static function (string $path, int $uid) use (&$force): void {
   if (is_dir($path) && is_link($path) === false) {
      foreach (array_diff((array) @scandir($path), ['.', '..']) as $child) {
         $force(rtrim($path, '/') . '/' . $child, $uid);
      }
   }
   @lchown($path, $uid);
   @lchgrp($path, $uid);
};
/**
 * Clear a tree from INSIDE the namespace. The harness cannot do it from outside:
 * its `rm -rf` runs as the suite user, which cannot traverse a 0700 directory
 * owned by a mapped sub-id — it fails silently and the previous leg's tree
 * survives, which quietly turns every control into a re-run of the last one.
 */
$purge = static function (string $path) use (&$purge): void {
   if (is_dir($path) && is_link($path) === false) {
      foreach (array_diff((array) @scandir($path), ['.', '..']) as $child) {
         $purge(rtrim($path, '/') . '/' . $child);
      }
      @rmdir($path);

      return;
   }
   @unlink($path);
};
/** Every path under one tree still owned by root, relative to it. */
$audit = static function (string $path, string $strip) use (&$audit): array {
   $found = [];
   $status = @lstat($path);
   if ($status !== false && $status['uid'] === 0) {
      $found[] = str_replace($strip, '', $path) ?: '(store root)';
   }
   if (is_dir($path) && is_link($path) === false) {
      foreach (array_diff((array) @scandir($path), ['.', '..']) as $child) {
         $found = array_merge($found, $audit(rtrim($path, '/') . '/' . $child, $strip));
      }
   }

   return $found;
};

$domains = ['example.com'];
$email = 'ops@example.com';
$canary = $base . '/CANARY';
$legs = [];
$distinct = false;

foreach (['changed', 'firstboot', 'unchanged', 'symlink'] as $mode) {
   $purge(BOOTGLY_STORAGE_DIR);
   $purge($canary);

   // # The first boot: a staging store, then the whole tree handed over
   $Staging = new Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS($domains, $email, staging: true);
   @mkdir($Staging->Certificates->path, 0700, true);
   file_put_contents($Staging->Certificates->path . 'bootstrap.pem', 'staging');

   // ?: `firstboot` leaves the tree root-owned, which is what the very first
   //    boot finds — the walk descends and hands everything over.
   if ($mode !== 'firstboot') {
      $force(BOOTGLY_STORAGE_DIR, $RUNTIME);
   }

   // # The next boot with a changed configuration: forge() runs as root and
   //   mints a store for the new identity inside a root already handed over
   $Production = new Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS($domains, $email, staging: false);
   $distinct = $Staging->Certificates->path !== $Production->Certificates->path;

   @mkdir($Production->Certificates->path, 0700, true);
   file_put_contents($Production->Certificates->path . 'bootstrap.pem', 'production');
   file_put_contents($Production->Certificates->path . 'current.json', '{}');

   // ?: `unchanged` models a boot whose configuration did not change — the store
   //    already belongs to the runtime identity and there is nothing to transfer.
   $force($Production->Certificates->path, $mode === 'unchanged' ? $RUNTIME : 0);
   @chmod($Production->Certificates->path, 0700);

   // ?: `symlink` aims the newly walked path at a root-owned canary OUTSIDE the
   //    managed tree — the escalation primitive C6 exists to refuse.
   if ($mode === 'symlink') {
      @mkdir($canary, 0700, true);
      file_put_contents($canary . '/shadow', 'root-owned');
      $force($canary, 0);
      @chmod($canary, 0700);

      $purge($Production->Certificates->path);
      @symlink($canary, rtrim($Production->Certificates->path, '/'));
   }

   // @ Drive the real prime(). A Gate resource makes bind() return early and a
   //   non-zero helper skips guard(), so only the ownership handoff runs.
   $Reflection = new ReflectionClass(Bootgly\WPI\Nodes\HTTP_Server_CLI::class);
   $Server = $Reflection->newInstanceWithoutConstructor();
   $Reflection->getProperty('user')->setValue($Server, 'daemon');
   $Reflection->getProperty('AutoTLS')->setValue($Server, $Production);
   $Reflection->getProperty('Gate')->setValue($Server, fopen('php://memory', 'r'));
   $Reflection->getProperty('helper')->setValue($Server, 1);

   try {
      $Reflection->getMethod('prime')->invoke($Server);
      $primed = 'ok';
   }
   catch (Throwable $Throwable) {
      $primed = 'refused';
   }

   $legs[$mode] = [
      'primed' => $primed,
      'root_owned' => $audit($Production->path, $Production->path),
      'canary' => $mode === 'symlink'
         ? [(int) (@lstat($canary)['uid'] ?? -1), (int) (@lstat($canary . '/shadow')['uid'] ?? -1)]
         : null,
   ];
}

$report([
   'reason' => '',
   'uid' => posix_getuid(),
   'runtime' => $RUNTIME,
   'distinct' => $distinct,
   'legs' => $legs,
]);
VICTIM;

         $runnerSource = <<<'RUNNER'
#!/bin/bash
# Runs the C6b legs as UID 0: directly when already root, otherwise inside an
# unprivileged user namespace that grants no host privilege whatsoever.
set -u
ROOT="$1"; BASE="$2"; RESULT="$3"; VICTIM="$4"

mkdir -p "$BASE"

if [ "$(id -u)" = "0" ]; then
   exec php "$VICTIM" "$ROOT" "$BASE" "$RESULT"
fi

command -v unshare   >/dev/null 2>&1 || { echo "no-unshare";   exit 3; }
command -v newuidmap >/dev/null 2>&1 || { echo "no-newuidmap"; exit 3; }
grep -q "^$(id -un):" /etc/subuid 2>/dev/null || { echo "no-subuid"; exit 3; }

SUB_UID=$(grep "^$(id -un):" /etc/subuid | head -1 | cut -d: -f2)
SUB_UID_N=$(grep "^$(id -un):" /etc/subuid | head -1 | cut -d: -f3)
SUB_GID=$(grep "^$(id -un):" /etc/subgid | head -1 | cut -d: -f2)
SUB_GID_N=$(grep "^$(id -un):" /etc/subgid | head -1 | cut -d: -f3)

FIFO="$BASE/.gate"; mkfifo "$FIFO" || { echo "no-fifo"; exit 3; }

unshare --user bash -c "read gate < '$FIFO'; exec php '$VICTIM' '$ROOT' '$BASE' '$RESULT'" &
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
            throw new RuntimeException('C6b could not install its fixtures.');
         }

         $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];
         $process = proc_open(
            ['/bin/bash', $runner, BOOTGLY_ROOT_DIR, $directory . '/fixture', $result, $victim],
            $descriptors,
            $pipes,
            $directory,
         );
         if (is_resource($process) === false) {
            throw new RuntimeException('C6b could not start its leg.');
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
            $probe['reason'] = trim(strtok($output, "\n") ?: 'leg produced no result');

            return "GET /c6b/store-handoff HTTP/1.1\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n"
               . "Host: localhost\r\nConnection: close\r\n\r\n";
         }

         $decoded = json_decode((string) file_get_contents($result), true);
         if (is_array($decoded) === false) {
            throw new RuntimeException('C6b leg produced an unreadable result.');
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

      return "GET /c6b/store-handoff HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response): Response {
      return $Response(body: 'C6b handler control');
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'C6b handler control') === false
      ) {
         return 'C6b fixture did not traverse the selected native HTTP handler.';
      }
      if ($probe['error'] !== '') {
         return 'C6b fixture error: ' . $probe['error'];
      }
      if ($probe['executed'] !== true) {
         return 'C6b legs did not execute as UID 0 (' . $probe['reason']
            . '); this environment cannot confirm or regress the privileged handoff.';
      }
      if ($probe['uid'] !== 0 || $probe['runtime'] <= 0) {
         return 'C6b reported an unusable identity pair: ' . json_encode($probe);
      }

      // ? The fixture must actually reproduce the trigger — two configuration
      //   identities, two stores. Without this the legs below are vacuous.
      if ($probe['distinct'] !== true) {
         return 'C6b fixture did not mint a second store: flipping `staging` produced one path.';
      }

      $legs = $probe['legs'];

      // # The entry: a store minted for a new identity under an already
      //   handed-over root is handed over too, or the demoted workers cannot
      //   read the credential the barrier is waiting for.
      if (($legs['changed']['primed'] ?? '') !== 'ok') {
         return 'C6b: prime() refused a legitimate changed configuration: ' . json_encode($legs['changed']);
      }
      if (($legs['changed']['root_owned'] ?? null) !== []) {
         return 'CONFIRMED C6b: prime() reported success and left the newly minted certificate '
            . 'store root-owned, unreadable to the demoted workers — '
            . json_encode($legs['changed']['root_owned'])
            . ' (runtime uid ' . $probe['runtime'] . ').';
      }

      // # Controls that must stay quiet
      foreach (['firstboot', 'unchanged'] as $control) {
         if (($legs[$control]['primed'] ?? '') !== 'ok') {
            return "C6b control `{$control}` refused a boot it must accept: " . json_encode($legs[$control]);
         }
         if (($legs[$control]['root_owned'] ?? null) !== []) {
            return "C6b control `{$control}` left the store root-owned: " . json_encode($legs[$control]);
         }
      }

      // # Containment: walking a new path must not become a way out of the tree
      if (($legs['symlink']['primed'] ?? '') !== 'refused') {
         return 'C6b containment control failed: a symlinked certificate store was not refused: '
            . json_encode($legs['symlink']);
      }
      if (($legs['symlink']['canary'] ?? null) !== [0, 0]) {
         return 'CONFIRMED C6b escalation: the handoff followed a symlinked store and re-owned a '
            . 'root-owned tree OUTSIDE the managed store — canary now '
            . json_encode($legs['symlink']['canary']) . '.';
      }

      return true;
   },
);
