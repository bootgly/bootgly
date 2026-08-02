<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Process;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M2 (2026-08-01 process identity) — constructing a default
 * outbound HTTP client in a forked server worker must not overwrite the
 * existing server Process master identity.
 *
 * The dangerous shutdown path runs in a fresh PHP session. A real two-worker
 * On vulnerable code Process::fork() gives worker #1 the inherited topology
 * [worker #0, self]; remediated workers must receive no sibling authority.
 * Worker #0 consumes a supervisor-sent SIGINT canary before the supervisor
 * releases worker #1. Worker #1 then constructs a MODE_TEST control client and
 * a default client before returning naturally through the server's genuine
 * shutdown callback. Both workers block SIGINT so the callback's sibling and
 * self deliveries can be observed without terminating unrelated processes.
 */
$probe = [
   'fixture_error' => '',
   'source' => [],
   'isolation' => [],
   'supervisor' => [],
   'sentinel' => [],
   'actor' => [],
   'tail' => [],
   'wait' => [],
   'child' => [],
];

// ! The source-to-sink shutdown path must never run in the Security suite's
// shared worker. Execute this file directly in a fresh, session-isolated PHP
// process and return one JSON evidence document to the native test harness.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--m2-process-identity-probe'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $storage = rtrim((string) ($_SERVER['argv'][3] ?? ''), '/');
   $storagePrefix = rtrim(sys_get_temp_dir(), '/') . '/bootgly-m2-process-';

   if (
      $root === ''
      || realpath($root) === false
      || realpath($root . '/autoboot.php') === false
      || $storage === ''
      || str_starts_with($storage, $storagePrefix) === false
      || is_dir($storage) === false
   ) {
      fwrite(STDERR, "M2 probe received an invalid root or storage directory.\n");
      exit(2);
   }

   // @ Boot the framework as an embedded probe, not as this unregistered test
   // file acting as a CLI script. CLI still initializes its Commands object,
   // which the real server/client constructors require, but routes no command.
   unset($_SERVER['SCRIPT_FILENAME']);
   $_SERVER['argv'] = [];
   $_SERVER['argc'] = 0;

   define('BOOTGLY_STORAGE_BASE', $storage);
   define('BOOTGLY_STORAGE_DIR', $storage . DIRECTORY_SEPARATOR);
   require $root . '/autoboot.php';

   /** @var null|HTTP_Server_CLI $Server */
   $Server = null;
   /** @var null|Process $ServerProcess */
   $ServerProcess = null;
   /** @var array<int,resource> $SentinelSockets */
   $SentinelSockets = [];
   /** @var array<int,resource> $ActorSockets */
   $ActorSockets = [];
   /** @var array<int,int> $PIDs */
   $PIDs = [];
   /** @var array<int,bool> $reaped */
   $reaped = [];

   try {
      foreach ([
         'pcntl_fork',
         'pcntl_sigprocmask',
         'pcntl_sigtimedwait',
         'pcntl_waitpid',
         'posix_getpgid',
         'posix_getsid',
         'posix_setsid',
      ] as $function) {
         if (function_exists($function) === false) {
            throw new RuntimeException("M2 requires {$function}().");
         }
      }

      $PID = posix_getpid();
      $priorSID = posix_getsid(0);
      $priorPGID = posix_getpgid(0);
      $session = posix_setsid();
      $alreadyIsolated = $session < 1
         && $priorSID === $PID
         && $priorPGID === $PID;
      if ($session < 1 && $alreadyIsolated === false) {
         throw new RuntimeException(
            'M2 could not create an isolated process session: '
            . json_encode([
               'pid' => $PID,
               'sid' => $priorSID,
               'pgid' => $priorPGID,
               'errno' => posix_get_last_error(),
               'error' => posix_strerror(posix_get_last_error()),
            ], JSON_UNESCAPED_SLASHES),
         );
      }
      $session = $session > 0 ? $session : $PID;

      $nonce = bin2hex(random_bytes(16));
      $SentinelSockets = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      $ActorSockets = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($SentinelSockets === false || $ActorSockets === false) {
         throw new RuntimeException('M2 could not create its evidence channels.');
      }

      $Server = new HTTP_Server_CLI(Modes::Test);
      $ServerProcess = $Server->Process;

      $Sources = [
         'process' => new ReflectionClass(Process::class),
         'tcp_server' => new ReflectionClass(TCP_Server_CLI::class),
         'tcp_client' => new ReflectionClass(TCP_Client_CLI::class),
         'http_client' => new ReflectionClass(HTTP_Client_CLI::class),
      ];
      foreach ($Sources as $name => $Reflection) {
         $file = $Reflection->getFileName();
         $probe['source'][$name] = [
            'file' => is_string($file) ? $file : '',
            'sha256' => is_string($file) ? hash_file('sha256', $file) : false,
         ];
      }

      $probe['isolation'] = [
         'pid' => posix_getpid(),
         'sid' => posix_getsid(0),
         'pgid' => posix_getpgid(0),
         'setsid' => $session,
         'already_isolated' => $alreadyIsolated,
         'prior_sid' => $priorSID,
         'prior_pgid' => $priorPGID,
         'storage' => BOOTGLY_STORAGE_BASE,
         'storage_mode' => fileperms(BOOTGLY_STORAGE_BASE) & 0777,
      ];
      $probe['supervisor'] = [
         'nonce' => $nonce,
         'role_before_fork' => $ServerProcess->level,
         'control_sent' => false,
      ];

      $ServerProcess->fork(
         2,
         static function (Process $Process, int $index) use (
            $SentinelSockets,
            $ActorSockets,
            $nonce,
         ): void {
            if ($index === 0) {
               fclose($SentinelSockets[0]);
               fclose($ActorSockets[0]);
               fclose($ActorSockets[1]);
               $SentinelSocket = $SentinelSockets[1];

               $previousMask = [];
               $blocked = pcntl_sigprocmask(
                  SIG_BLOCK,
                  [SIGINT],
                  $previousMask,
               );
               fwrite($SentinelSocket, json_encode([
                  'phase' => 'ready',
                  'nonce' => $nonce,
                  'pid' => posix_getpid(),
                  'blocked' => $blocked,
                  'previous_mask' => $previousMask,
               ], JSON_UNESCAPED_SLASHES) . "\n");

               $controlInfo = [];
               $controlSignal = pcntl_sigtimedwait(
                  [SIGINT],
                  $controlInfo,
                  3,
                  0,
               );
               fwrite($SentinelSocket, json_encode([
                  'phase' => 'control',
                  'nonce' => $nonce,
                  'signal' => $controlSignal,
                  'info' => $controlInfo,
               ], JSON_UNESCAPED_SLASHES) . "\n");
               fwrite($SentinelSocket, json_encode([
                  'phase' => 'armed',
                  'nonce' => $nonce,
               ], JSON_UNESCAPED_SLASHES) . "\n");

               $attackInfo = [];
               $attackSignal = pcntl_sigtimedwait(
                  [SIGINT],
                  $attackInfo,
                  4,
                  0,
               );
               fwrite($SentinelSocket, json_encode([
                  'phase' => 'result',
                  'nonce' => $nonce,
                  'signal' => $attackSignal,
                  'info' => $attackInfo,
               ], JSON_UNESCAPED_SLASHES) . "\n");

               return;
            }

            fclose($ActorSockets[0]);
            fclose($SentinelSockets[0]);
            fclose($SentinelSockets[1]);
            $ActorSocket = $ActorSockets[1];

            $previousMask = [];
            $blocked = pcntl_sigprocmask(
               SIG_BLOCK,
               [SIGINT],
               $previousMask,
            );
            $command = trim((string) fgets($ActorSocket));
            $roleBefore = $Process->level;
            $topology = $Process->Children->PIDs;

            $ControlClient = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
            $roleAfterControl = $Process->level;

            // @ Registered after the server hook and before the default-client
            // hook: it observes the server hook's self-directed pending SIGINT.
            register_shutdown_function(
               static function () use ($ActorSocket, $nonce): void {
                  $signalInfo = [];
                  $signal = pcntl_sigtimedwait(
                     [SIGINT],
                     $signalInfo,
                     0,
                     1,
                  );
                  fwrite($ActorSocket, json_encode([
                     'phase' => 'tail',
                     'nonce' => $nonce,
                     'signal' => $signal,
                     'info' => $signalInfo,
                  ], JSON_UNESCAPED_SLASHES) . "\n");
               },
            );

            $HTTPClient = new HTTP_Client_CLI;
            $ClientProcess = $HTTPClient->Process;
            $roleAfterDefault = $Process->level;
            $ClientProcess->State->clean();
            $clientCleanupReached = true;

            fwrite($ActorSocket, json_encode([
               'phase' => 'actor',
               'nonce' => $nonce,
               'command' => $command,
               'pid' => posix_getpid(),
               'blocked' => $blocked,
               'previous_mask' => $previousMask,
               'role_before' => $roleBefore,
               'role_after_control' => $roleAfterControl,
               'role_after_default' => $roleAfterDefault,
               'topology' => $topology,
               'client_process' => $ClientProcess instanceof Process,
               'client_level' => $ClientProcess->level,
               'client_children' => $ClientProcess->Children->PIDs,
               'client_cleanup_reached' => $clientCleanupReached,
            ], JSON_UNESCAPED_SLASHES) . "\n");
         },
      );

      $PIDs = array_values($ServerProcess->Children->PIDs);
      if (count($PIDs) !== 2) {
         throw new RuntimeException('M2 did not create exactly two Process workers.');
      }
      [$sentinelPID, $actorPID] = $PIDs;
      $probe['supervisor']['children'] = $PIDs;

      fclose($SentinelSockets[1]);
      unset($SentinelSockets[1]);
      fclose($ActorSockets[1]);
      unset($ActorSockets[1]);
      $SentinelSocket = $SentinelSockets[0];
      $ActorSocket = $ActorSockets[0];
      stream_set_timeout($SentinelSocket, 6);
      stream_set_timeout($ActorSocket, 6);

      /** @return array<string,mixed> */
      $Read = static function ($Socket, string $phase): array {
         $line = fgets($Socket);
         if ($line === false) {
            $metadata = stream_get_meta_data($Socket);
            throw new RuntimeException(
               "M2 evidence channel ended before {$phase}: "
               . json_encode($metadata, JSON_UNESCAPED_SLASHES),
            );
         }
         $decoded = json_decode($line, true);
         if (
            is_array($decoded) === false
            || ($decoded['phase'] ?? null) !== $phase
         ) {
            throw new RuntimeException(
               "M2 received invalid {$phase} evidence: " . trim($line),
            );
         }

         return $decoded;
      };

      $probe['sentinel']['ready'] = $Read($SentinelSocket, 'ready');
      $probe['supervisor']['control_sent'] = posix_kill($sentinelPID, SIGINT);
      $probe['sentinel']['control'] = $Read($SentinelSocket, 'control');
      $probe['sentinel']['armed'] = $Read($SentinelSocket, 'armed');

      $written = fwrite($ActorSocket, "GO {$nonce}\n");
      $probe['supervisor']['go_written'] = $written === strlen("GO {$nonce}\n");
      $probe['actor'] = $Read($ActorSocket, 'actor');
      $probe['tail'] = $Read($ActorSocket, 'tail');
      $probe['sentinel']['result'] = $Read($SentinelSocket, 'result');

      foreach ($PIDs as $PID) {
         $result = pcntl_waitpid($PID, $status);
         $reaped[$PID] = $result === $PID;
         $probe['wait'][(string) $PID] = [
            'result' => $result,
            'exited' => pcntl_wifexited($status),
            'exit_code' => pcntl_wifexited($status)
               ? pcntl_wexitstatus($status)
               : -1,
            'signaled' => pcntl_wifsignaled($status),
            'signal' => pcntl_wifsignaled($status)
               ? pcntl_wtermsig($status)
               : 0,
         ];
         $ServerProcess->Children->remove($PID);
      }
   }
   catch (Throwable $Throwable) {
      $probe['fixture_error'] = $Throwable::class . ': '
         . $Throwable->getMessage();
   }
   finally {
      foreach ($PIDs as $PID) {
         if (($reaped[$PID] ?? false) === false) {
            @posix_kill($PID, SIGKILL);
            @pcntl_waitpid($PID, $status);
         }
         $ServerProcess?->Children->remove($PID);
      }
      foreach ([$SentinelSockets, $ActorSockets] as $Sockets) {
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               fclose($Socket);
            }
         }
      }
   }

   $JSON = json_encode($probe, JSON_UNESCAPED_SLASHES);
   if (is_string($JSON) === false) {
      fwrite(STDERR, "M2 probe could not encode its evidence.\n");
      exit(3);
   }
   fwrite(STDOUT, $JSON);
   exit(0);
}

return new Specification(
   description: 'Outbound clients must not corrupt a forked server worker identity',
   Separator: new Separator(line: true),
   skip: function_exists('proc_open') === false
      || function_exists('pcntl_fork') === false
      || function_exists('pcntl_sigtimedwait') === false
      || function_exists('posix_setsid') === false,

   request: static function () use (&$probe): string {
      $process = null;
      $pipes = [];
      $storage = rtrim(sys_get_temp_dir(), '/')
         . '/bootgly-m2-process-'
         . posix_getpid()
         . '-'
         . bin2hex(random_bytes(8));

      /** @return array<string,mixed> */
      $Terminate = static function (mixed $process): array {
         $status = proc_get_status($process);
         if (is_array($status) === false || ($status['running'] ?? false) === false) {
            return is_array($status)
               ? $status
               : ['running' => false, 'exitcode' => -1];
         }

         $PID = (int) ($status['pid'] ?? 0);
         $PGID = $PID > 1 ? posix_getpgid($PID) : false;
         if ($PID > 1 && $PGID === $PID) {
            posix_kill(-$PID, SIGTERM);
         }
         else {
            proc_terminate($process);
         }
         for ($attempt = 0; $attempt < 50; $attempt++) {
            usleep(10000);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
               return $status;
            }
         }

         if ($PID > 1 && posix_getpgid($PID) === $PID) {
            posix_kill(-$PID, SIGKILL);
         }
         else {
            proc_terminate($process, SIGKILL);
         }
         for ($attempt = 0; $attempt < 100; $attempt++) {
            usleep(10000);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
               return $status;
            }
         }

         return $status;
      };

      $Remove = static function (string $directory): void {
         $prefix = rtrim(sys_get_temp_dir(), '/') . '/bootgly-m2-process-';
         if (
            str_starts_with($directory, $prefix) === false
            || is_dir($directory) === false
         ) {
            return;
         }

         $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
               $directory,
               FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
         );
         foreach ($Iterator as $Entry) {
            $path = $Entry->getPathname();
            if ($Entry->isLink() || $Entry->isFile()) {
               unlink($path);
            }
            else if ($Entry->isDir()) {
               rmdir($path);
            }
         }
         rmdir($directory);
      };

      try {
         if (mkdir($storage, 0700, true) === false) {
            throw new RuntimeException('M2 could not create isolated storage.');
         }

         $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];
         $process = proc_open(
            [
               PHP_BINARY,
               '-d',
               'opcache.enable_cli=0',
               __FILE__,
               '--m2-process-identity-probe',
               BOOTGLY_ROOT_BASE,
               $storage,
            ],
            $descriptors,
            $pipes,
            BOOTGLY_ROOT_BASE,
         );
         if (is_resource($process) === false) {
            throw new RuntimeException('M2 could not start its isolated probe.');
         }

         stream_set_blocking($pipes[1], false);
         stream_set_blocking($pipes[2], false);
         $output = '';
         $error = '';
         $timedOut = false;
         $status = [];
         $deadline = microtime(true) + 12.0;
         do {
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) {
               $output .= $chunk;
            }
            $chunk = stream_get_contents($pipes[2]);
            if ($chunk !== false) {
               $error .= $chunk;
            }

            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
               break;
            }
            usleep(10000);
         }
         while (microtime(true) < $deadline);

         if (($status['running'] ?? false) === true) {
            $timedOut = true;
            $status = $Terminate($process);
         }

         foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false) {
               if ($index === 1) {
                  $output .= $chunk;
               }
               else {
                  $error .= $chunk;
               }
            }
            fclose($pipes[$index]);
            unset($pipes[$index]);
         }

         $statusCode = (int) ($status['exitcode'] ?? -1);
         $closedCode = ($status['running'] ?? false) === false
            ? proc_close($process)
            : -1;
         if (($status['running'] ?? false) === false) {
            $process = null;
         }
         $exitCode = $statusCode >= 0 ? $statusCode : $closedCode;

         if ($timedOut) {
            throw new RuntimeException('M2 isolated probe exceeded 12 seconds.');
         }
         $decoded = json_decode($output, true);
         if (is_array($decoded) === false) {
            throw new RuntimeException(
               'M2 isolated probe returned unreadable evidence: '
               . trim($error !== '' ? $error : $output),
            );
         }
         $probe = $decoded;
         $probe['child'] = [
            'exit_code' => $exitCode,
            'stderr' => trim($error),
            'timed_out' => false,
            'storage' => $storage,
         ];
         if ($exitCode !== 0 || $probe['child']['stderr'] !== '') {
            $probe['fixture_error'] = 'M2 child exit/stderr control failed.';
         }
      }
      catch (Throwable $Throwable) {
         $probe['fixture_error'] = $Throwable::class . ': '
            . $Throwable->getMessage();
      }
      finally {
         foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
               fclose($pipe);
            }
         }
         if (is_resource($process)) {
            $status = $Terminate($process);
            if (($status['running'] ?? false) === false) {
               proc_close($process);
            }
         }
         $Remove($storage);
      }

      return "GET /m2/process-identity HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) {
      yield $Router->route(
         '/m2/process-identity',
         static fn (Request $Request, Response $Response): Response =>
            $Response(code: 200, body: 'M2-PROCESS-CONTROL'),
         GET,
      );
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'M2-PROCESS-CONTROL') === false
      ) {
         Vars::$labels = ['M2 native HTTP harness control'];
         dump($response);

         return 'M2 fixture failed: the independent HTTP control did not complete.';
      }
      if (($probe['fixture_error'] ?? '') !== '') {
         Vars::$labels = ['M2 fixture error', 'M2 evidence'];
         dump($probe['fixture_error'], json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'M2 fixture failed before process-identity validation: '
            . $probe['fixture_error'];
      }

      $expectedSources = [
         'process' => BOOTGLY_ROOT_DIR . 'Bootgly/ACI/Process.php',
         'tcp_server' => BOOTGLY_ROOT_DIR . 'Bootgly/WPI/Interfaces/TCP_Server_CLI.php',
         'tcp_client' => BOOTGLY_ROOT_DIR . 'Bootgly/WPI/Interfaces/TCP_Client_CLI.php',
         'http_client' => BOOTGLY_ROOT_DIR . 'Bootgly/WPI/Nodes/HTTP_Client_CLI.php',
      ];
      foreach ($expectedSources as $name => $expectedFile) {
         $expected = realpath($expectedFile);
         $loaded = realpath((string) ($probe['source'][$name]['file'] ?? ''));
         if (
            is_string($expected) === false
            || $loaded !== $expected
            || ($probe['source'][$name]['sha256'] ?? false)
               !== hash_file('sha256', $expected)
         ) {
            Vars::$labels = ['M2 exact-worktree source control', 'M2 evidence'];
            dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

            return "M2 fixture failed: {$name} did not load from this worktree.";
         }
      }

      $isolation = $probe['isolation'] ?? [];
      $supervisor = $probe['supervisor'] ?? [];
      $sentinel = $probe['sentinel'] ?? [];
      $actor = $probe['actor'] ?? [];
      $tail = $probe['tail'] ?? [];
      $PIDs = array_values($supervisor['children'] ?? []);
      $nonce = (string) ($supervisor['nonce'] ?? '');
      $normalWait = count($PIDs) === 2;
      foreach ($PIDs as $PID) {
         $wait = $probe['wait'][(string) $PID] ?? [];
         $normalWait = $normalWait
            && ($wait['result'] ?? null) === $PID
            && ($wait['exited'] ?? false) === true
            && ($wait['exit_code'] ?? -1) === 0
            && ($wait['signaled'] ?? true) === false;
      }

      $controls = ($probe['child']['exit_code'] ?? -1) === 0
         && ($probe['child']['stderr'] ?? null) === ''
         && ($probe['child']['timed_out'] ?? true) === false
         && ($isolation['pid'] ?? 0) > 1
         && ($isolation['sid'] ?? 0) === $isolation['pid']
         && ($isolation['pgid'] ?? 0) === $isolation['pid']
         && ($isolation['setsid'] ?? 0) === $isolation['pid']
         && ($isolation['storage'] ?? '') === ($probe['child']['storage'] ?? null)
         && ($isolation['storage_mode'] ?? 0) === 0700
         && ($supervisor['role_before_fork'] ?? '') === 'master'
         && ($supervisor['control_sent'] ?? false) === true
         && ($supervisor['go_written'] ?? false) === true
         && $nonce !== ''
         && ($sentinel['ready']['nonce'] ?? '') === $nonce
         && ($sentinel['ready']['pid'] ?? 0) === ($PIDs[0] ?? -1)
         && ($sentinel['ready']['blocked'] ?? false) === true
         && ($sentinel['control']['nonce'] ?? '') === $nonce
         && ($sentinel['control']['signal'] ?? -1) === SIGINT
         && ($sentinel['armed']['nonce'] ?? '') === $nonce
         && ($sentinel['result']['nonce'] ?? '') === $nonce
         && ($actor['nonce'] ?? '') === $nonce
         && ($actor['command'] ?? '') === "GO {$nonce}"
         && ($actor['pid'] ?? 0) === ($PIDs[1] ?? -1)
         && ($actor['blocked'] ?? false) === true
         && ($actor['role_before'] ?? '') === 'child'
         && ($actor['role_after_control'] ?? '') === 'child'
         && ($actor['client_process'] ?? false) === true
         && ($actor['client_level'] ?? '') === 'master'
         && ($actor['client_children'] ?? null) === []
         && ($actor['client_cleanup_reached'] ?? false) === true
         && ($tail['nonce'] ?? '') === $nonce
         && $normalWait;

      if ($controls === false) {
         Vars::$labels = ['M2 process and signal controls', 'M2 evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'M2 fixture failed: isolation, fork, control-client, signal-canary, '
            . 'or exact-reap controls did not hold.';
      }

      $vulnerable = ($actor['role_after_default'] ?? '') === 'master'
         && ($actor['topology'] ?? null) === $PIDs
         && ($sentinel['result']['signal'] ?? -1) === SIGINT
         && ($tail['signal'] ?? -1) === SIGINT;
      if ($vulnerable) {
         Vars::$labels = ['M2 confirmed process-identity evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'CONFIRMED M2 (2026-08-01 process identity): constructing a '
            . 'default HTTP client reclassified the real forked server worker '
            . 'from child to master; its genuine server shutdown hook then sent '
            . 'SIGINT to the inherited sibling and to itself.';
      }

      $secure = ($actor['role_after_default'] ?? '') === 'child'
         && (
            ($sentinel['result']['signal'] ?? 0) === false
            || ($sentinel['result']['signal'] ?? 0) === -1
         )
         && (
            ($tail['signal'] ?? 0) === false
            || ($tail['signal'] ?? 0) === -1
         );
      if ($secure) {
         return true;
      }

      Vars::$labels = ['M2 unexpected process-identity state', 'M2 evidence'];
      dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

      return 'M2 probe reached a partial state: safe process ownership was not '
         . 'established, but the complete cross-worker shutdown signal path did '
         . 'not reproduce.';
   },
);
