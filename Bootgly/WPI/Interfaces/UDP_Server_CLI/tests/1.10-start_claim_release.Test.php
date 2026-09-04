<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'UDP start releases lifecycle claims and restores signal masks',
   skip: function_exists('pcntl_sigprocmask') === false
      || defined('SIGWINCH') === false
      || function_exists('pcntl_async_signals') === false
      || function_exists('pcntl_signal') === false
      || function_exists('pcntl_signal_get_handler') === false
      || function_exists('pcntl_waitpid') === false
      || function_exists('pcntl_wifexited') === false
      || function_exists('pcntl_wexitstatus') === false
      || function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_get_status') === false
      || function_exists('proc_open') === false
      || function_exists('proc_terminate') === false
      || function_exists('stream_socket_client') === false
      || function_exists('stream_socket_server') === false,
   test: new Assertions(Case: function (): Generator {
      /** Decode the latest JSON evidence for one subprocess phase. */
      $Decode = static function (string $output, string $phase): null|array {
         $lines = explode(PHP_EOL, $output);
         for ($index = count($lines) - 1; $index >= 0; $index--) {
            $line = trim($lines[$index]);
            $offset = strpos($line, '{');
            if ($offset === false) {
               continue;
            }
            $Data = json_decode(substr($line, $offset), true);
            if (is_array($Data) && ($Data['phase'] ?? null) === $phase) {
               return $Data;
            }
         }

         return null;
      };

      /** Terminate a dedicated process group, including signal-deaf mutants. */
      $Terminate = static function ($Process, int $PID): void {
         posix_kill(-$PID, SIGTERM);
         proc_terminate($Process, SIGTERM);
         $deadline = hrtime(true) + 250_000_000;
         do {
            $Status = proc_get_status($Process);
            if ($Status['running'] === false) {
               break;
            }
            usleep(1_000);
         } while (hrtime(true) < $deadline);

         // @ SIGTERM may be the exact signal whose unmask was removed.
         posix_kill(-$PID, SIGKILL);
         $Status = proc_get_status($Process);
         if ($Status['running']) {
            proc_terminate($Process, SIGKILL);
         }
      };

      /**
       * Run one session-isolated PHP probe with bounded output and cleanup.
       *
       * @param array<string,string> $ExtraEnvironment
       * @param null|Closure $Interact Optional live interaction before stdin closes.
       *
       * @return array<string,mixed>
       */
      $Run = static function (
         string $Script,
         array $ExtraEnvironment,
         null|Closure $Interact = null,
      ) use ($Terminate): array {
         $Arguments = [
            PHP_BINARY,
            '-d',
            'display_errors=stderr',
            '-r',
            $Script,
         ];
         $Descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];
         $Environment = (array) getenv();
         foreach ($ExtraEnvironment as $name => $value) {
            $Environment[$name] = $value;
         }
         $Process = proc_open(
            $Arguments,
            $Descriptors,
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment,
         );
         if (is_resource($Process) === false) {
            return [
               'status' => -1,
               'timed_out' => false,
               'group_leaked' => false,
               'group_clean' => true,
               'stdout' => '',
               'stderr' => 'proc_open failed',
               'interaction' => [],
            ];
         }

         stream_set_blocking($Pipes[1], false);
         stream_set_blocking($Pipes[2], false);
         $ProcessStatus = proc_get_status($Process);
         $PID = (int) $ProcessStatus['pid'];
         $stdout = '';
         $stderr = '';
         $Interaction = [];
         if ($Interact instanceof Closure) {
            $Interaction = $Interact(
               $Process,
               $Pipes,
               $PID,
               $stdout,
               $stderr,
            );
         }
         if (is_resource($Pipes[0])) {
            fclose($Pipes[0]);
         }

         $timedOut = false;
         $exitCode = null;
         $deadline = hrtime(true) + 5_000_000_000;
         do {
            $stdout .= (string) stream_get_contents($Pipes[1]);
            $stderr .= (string) stream_get_contents($Pipes[2]);
            $ProcessStatus = proc_get_status($Process);
            if ($ProcessStatus['running'] === false) {
               $exitCode = (int) $ProcessStatus['exitcode'];
               break;
            }
            if (hrtime(true) >= $deadline) {
               $timedOut = true;
               $Terminate($Process, $PID);
               break;
            }
            usleep(1_000);
         } while (true);

         $stdout .= (string) stream_get_contents($Pipes[1]);
         $stderr .= (string) stream_get_contents($Pipes[2]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);
         $closeStatus = proc_close($Process);

         $groupLeaked = posix_kill(-$PID, 0);
         if ($groupLeaked) {
            posix_kill(-$PID, SIGKILL);
            $cleanupDeadline = hrtime(true) + 250_000_000;
            while (
               posix_kill(-$PID, 0)
               && hrtime(true) < $cleanupDeadline
            ) {
               usleep(1_000);
            }
         }
         $groupClean = posix_kill(-$PID, 0) === false;
         $status = $timedOut
            ? 124
            : ($exitCode !== null && $exitCode >= 0 ? $exitCode : $closeStatus);

         return [
            'status' => $status,
            'timed_out' => $timedOut,
            'group_leaked' => $groupLeaked,
            'group_clean' => $groupClean,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'interaction' => $Interaction,
         ];
      };

      $MasterScript = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   echo json_encode(['phase' => 'master', 'session' => false]);
   exit(125);
}

require getenv('H7_START_AUTOBOOT');

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;

final class H7StartMasterProbe extends UDP_Server_CLI
{
   public static bool $signalDelivered = false;
   public static bool $signalAfterRelease = false;


   /** Queue one safe signal while start() owns the blocked lifecycle mask. */
   public static function boot (mixed $Environment): void
   {
      posix_kill((int) getmypid(), SIGWINCH);
   }
}

Display::show(Display::NONE);
$InheritedMask = [];
pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $InheritedMask);
pcntl_sigprocmask(SIG_SETMASK, $InheritedMask);
$InheritedAsync = pcntl_async_signals(true);
$PreviousProbeHandler = pcntl_signal_get_handler(SIGWINCH);
pcntl_signal(
   SIGWINCH,
   static function (): void {
      $Starting = new ReflectionProperty(Connections::class, 'Starting');
      H7StartMasterProbe::$signalDelivered = true;
      H7StartMasterProbe::$signalAfterRelease = $Starting->getValue() === null;
   },
   false,
);
$Evidence = [
   'phase' => 'master',
   'session' => true,
   'start' => false,
   'starting_null' => false,
   'signal_delivered' => false,
   'signal_after_release' => false,
   'mask_restored' => false,
   'state_clean' => false,
   'cleanup_mask_restored' => false,
   'cleanup_handler_restored' => false,
   'error' => '',
];
$Server = null;
$State = null;

try {
   pcntl_sigprocmask(SIG_SETMASK, [SIGUSR1]);
   $OriginalMask = [];
   pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $OriginalMask);
   sort($OriginalMask);

   $Server = new H7StartMasterProbe(Modes::Test);
   $Server->configure(new Configs(
      host: '127.0.0.1',
      port: (int) getenv('H7_START_PORT'),
      workers: 0,
      connectionIdleTimeout: 0,
   ));
   $Server->on(
      Events::DatagramReceive,
      static fn (string $input): string => "master:{$input}",
   );
   $Evidence['start'] = $Server->start();

   $Starting = new ReflectionProperty(Connections::class, 'Starting');
   $Evidence['starting_null'] = $Starting->getValue() === null;
   $Evidence['signal_delivered'] = H7StartMasterProbe::$signalDelivered;
   $Evidence['signal_after_release'] = H7StartMasterProbe::$signalAfterRelease;
   $AfterMask = [];
   pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $AfterMask);
   sort($AfterMask);
   $Evidence['mask_restored'] = $AfterMask === $OriginalMask;
   $State = $Server->Process->State;
}
catch (Throwable $Throwable) {
   $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
}
finally {
   if ($Server instanceof UDP_Server_CLI) {
      try {
         $Server->stop();
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
   }
   if ($State !== null) {
      $PIDState = is_file($State->pidFile)
         ? trim((string) file_get_contents($State->pidFile))
         : '';
      $CommandState = is_file($State->commandFile)
         ? trim((string) file_get_contents($State->commandFile))
         : '';
      $Lock = @fopen($State->pidLockFile, 'c+');
      $lockFree = is_resource($Lock) && @flock($Lock, LOCK_EX | LOCK_NB);
      if ($lockFree) {
         @flock($Lock, LOCK_UN);
      }
      if (is_resource($Lock)) {
         fclose($Lock);
      }
      $Evidence['state_clean'] = $PIDState === ''
         && $CommandState === ''
         && $lockFree;
   }

   pcntl_sigprocmask(SIG_SETMASK, $InheritedMask);
   $CleanupMask = [];
   pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $CleanupMask);
   pcntl_sigprocmask(SIG_SETMASK, $InheritedMask);
   sort($CleanupMask);
   sort($InheritedMask);
   $Evidence['cleanup_mask_restored'] = $CleanupMask === $InheritedMask;
   pcntl_signal(
      SIGWINCH,
      $PreviousProbeHandler === false ? SIG_DFL : $PreviousProbeHandler,
      false,
   );
   $Evidence['cleanup_handler_restored'] = pcntl_signal_get_handler(SIGWINCH)
      === ($PreviousProbeHandler === false ? SIG_DFL : $PreviousProbeHandler);
   pcntl_async_signals($InheritedAsync);
   echo json_encode($Evidence) . PHP_EOL;
}
PHP;

      $LiveScript = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   echo json_encode(['phase' => 'ready', 'session' => false]) . PHP_EOL;
   exit(125);
}

require getenv('H7_START_AUTOBOOT');

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;

final class H7StartWorkerProbe extends UDP_Server_CLI
{
   public static bool $signalDelivered = false;
   public static bool $signalAfterRelease = false;


   /** Keep the application boot seam inert; the child probes instance(). */
   public static function boot (mixed $Environment): void
   {
   }

   /** Queue one safe signal after the worker socket is live but before release. */
   public function instance ()
   {
      $Socket = parent::instance();
      posix_kill((int) getmypid(), SIGWINCH);

      return $Socket;
   }
}

Display::show(Display::NONE);
$InheritedMask = [];
pcntl_sigprocmask(SIG_BLOCK, [SIGUSR2], $InheritedMask);
pcntl_sigprocmask(SIG_SETMASK, $InheritedMask);
$InheritedAsync = pcntl_async_signals(true);
$PreviousProbeHandler = pcntl_signal_get_handler(SIGWINCH);
pcntl_signal(
   SIGWINCH,
   static function (): void {
      $Starting = new ReflectionProperty(Connections::class, 'Starting');
      H7StartWorkerProbe::$signalDelivered = true;
      H7StartWorkerProbe::$signalAfterRelease = $Starting->getValue() === null;
   },
   false,
);
$Server = null;
$State = null;
$WorkerPID = 0;
$Ready = [
   'phase' => 'ready',
   'session' => true,
   'start' => false,
   'starting_null' => false,
   'mask_restored' => false,
   'worker' => 0,
   'error' => '',
];

try {
   pcntl_sigprocmask(SIG_SETMASK, [SIGUSR2]);
   $OriginalMask = [];
   pcntl_sigprocmask(SIG_BLOCK, [SIGUSR2], $OriginalMask);
   sort($OriginalMask);

   $Server = new H7StartWorkerProbe(Modes::Test);
   $Server->configure(new Configs(
      host: '127.0.0.1',
      port: (int) getenv('H7_START_PORT'),
      workers: 1,
      connectionIdleTimeout: 0,
   ));
   $Server->on(
      Events::DatagramReceive,
      static function (string $input): string {
         $Mask = [];
         pcntl_sigprocmask(SIG_BLOCK, [SIGUSR2], $Mask);
         sort($Mask);
         $mask = $Mask === [SIGUSR2] ? 'mask-ok' : 'mask-leaked';
         $signal = H7StartWorkerProbe::$signalDelivered
            ? (
               H7StartWorkerProbe::$signalAfterRelease
                  ? 'signal-ok'
                  : 'signal-early'
            )
            : 'signal-missing';

         return "h7-start:{$mask}:{$signal}:{$input}";
      },
   );
   $Ready['start'] = $Server->start();
   $Starting = new ReflectionProperty(Connections::class, 'Starting');
   $Ready['starting_null'] = $Starting->getValue() === null;
   $AfterMask = [];
   pcntl_sigprocmask(SIG_BLOCK, [SIGUSR2], $AfterMask);
   sort($AfterMask);
   $Ready['mask_restored'] = $AfterMask === $OriginalMask;
   $PIDs = $Server->Process->Children->PIDs;
   $WorkerPID = (int) ($PIDs[0] ?? 0);
   $Ready['worker'] = $WorkerPID;
   $State = $Server->Process->State;
}
catch (Throwable $Throwable) {
   $Ready['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
}

echo json_encode($Ready) . PHP_EOL;
flush();
stream_set_blocking(STDIN, false);
$command = '';
$commandDeadline = hrtime(true) + 6_000_000_000;
while (str_contains($command, "stop\n") === false) {
   $chunk = fread(STDIN, 8192);
   if (is_string($chunk) && $chunk !== '') {
      $command .= $chunk;
      continue;
   }
   if (feof(STDIN) || hrtime(true) >= $commandDeadline) {
      break;
   }
   usleep(1_000);
}

$Final = [
   'phase' => 'final',
   'commanded' => str_contains($command, "stop\n"),
   'term_sent' => false,
   'reaped' => false,
   'forced' => false,
   'exited_zero' => false,
   'worker_gone' => $WorkerPID <= 0,
   'state_clean' => false,
   'cleanup_mask_restored' => false,
   'cleanup_handler_restored' => false,
];
if ($Server instanceof UDP_Server_CLI) {
   $Server->Process->stopping = true;
}
if ($WorkerPID > 0) {
   $Final['term_sent'] = posix_kill($WorkerPID, SIGTERM);
   $waitStatus = 0;
   $waited = 0;
   $waitDeadline = hrtime(true) + 1_000_000_000;
   do {
      $waited = pcntl_waitpid($WorkerPID, $waitStatus, WNOHANG);
      if ($waited === $WorkerPID) {
         break;
      }
      usleep(1_000);
   } while (hrtime(true) < $waitDeadline);

   if ($waited !== $WorkerPID) {
      $Final['forced'] = true;
      posix_kill($WorkerPID, SIGKILL);
      $waited = pcntl_waitpid($WorkerPID, $waitStatus);
   }
   $Final['reaped'] = $waited === $WorkerPID;
   $Final['exited_zero'] = $Final['reaped']
      && pcntl_wifexited($waitStatus)
      && pcntl_wexitstatus($waitStatus) === 0;
   $Final['worker_gone'] = posix_kill($WorkerPID, 0) === false;
   if ($Server instanceof UDP_Server_CLI) {
      $Server->Process->Children->remove($WorkerPID);
   }
}

if ($State !== null) {
   $cleaned = $State->clean();
   $PIDState = is_file($State->pidFile)
      ? trim((string) file_get_contents($State->pidFile))
      : '';
   $CommandState = is_file($State->commandFile)
      ? trim((string) file_get_contents($State->commandFile))
      : '';
   $Lock = @fopen($State->pidLockFile, 'c+');
   $lockFree = is_resource($Lock) && @flock($Lock, LOCK_EX | LOCK_NB);
   if ($lockFree) {
      @flock($Lock, LOCK_UN);
   }
   if (is_resource($Lock)) {
      fclose($Lock);
   }
   $Final['state_clean'] = $cleaned
      && $PIDState === ''
      && $CommandState === ''
      && $lockFree;
}

pcntl_sigprocmask(SIG_SETMASK, $InheritedMask);
$CleanupMask = [];
pcntl_sigprocmask(SIG_BLOCK, [SIGUSR2], $CleanupMask);
pcntl_sigprocmask(SIG_SETMASK, $InheritedMask);
sort($CleanupMask);
sort($InheritedMask);
$Final['cleanup_mask_restored'] = $CleanupMask === $InheritedMask;
pcntl_signal(
   SIGWINCH,
   $PreviousProbeHandler === false ? SIG_DFL : $PreviousProbeHandler,
   false,
);
$Final['cleanup_handler_restored'] = pcntl_signal_get_handler(SIGWINCH)
   === ($PreviousProbeHandler === false ? SIG_DFL : $PreviousProbeHandler);
pcntl_async_signals($InheritedAsync);
echo json_encode($Final) . PHP_EOL;
PHP;

      $MasterReservation = stream_socket_server(
         'udp://127.0.0.1:0',
         $masterCode,
         $masterMessage,
         STREAM_SERVER_BIND,
      );
      $LiveReservation = stream_socket_server(
         'udp://127.0.0.1:0',
         $liveCode,
         $liveMessage,
         STREAM_SERVER_BIND,
      );
      $MasterName = is_resource($MasterReservation)
         ? stream_socket_get_name($MasterReservation, false)
         : false;
      $LiveName = is_resource($LiveReservation)
         ? stream_socket_get_name($LiveReservation, false)
         : false;
      $MasterPort = is_string($MasterName)
         ? (int) substr($MasterName, (int) strrpos($MasterName, ':') + 1)
         : 0;
      $LivePort = is_string($LiveName)
         ? (int) substr($LiveName, (int) strrpos($LiveName, ':') + 1)
         : 0;
      if (is_resource($MasterReservation)) {
         fclose($MasterReservation);
      }
      if (is_resource($LiveReservation)) {
         fclose($LiveReservation);
      }

      $ParentMask = [];
      pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $ParentMask);
      pcntl_sigprocmask(SIG_SETMASK, $ParentMask);
      $ParentMaskExpected = $ParentMask;
      sort($ParentMaskExpected);
      $ParentHandlers = [
         SIGCHLD => pcntl_signal_get_handler(SIGCHLD),
         SIGTERM => pcntl_signal_get_handler(SIGTERM),
      ];
      $parentAsync = pcntl_async_signals();

      $MasterResult = [];
      $LiveResult = [];
      $ParentState = [];
      try {
         $Environment = [
            'H7_START_AUTOBOOT' => BOOTGLY_ROOT_DIR . 'autoboot.php',
         ];
         $MasterResult = $Run(
            $MasterScript,
            [
               ...$Environment,
               'H7_START_PORT' => (string) $MasterPort,
            ],
         );
         $MasterResult['data'] = $Decode(
            (string) $MasterResult['stdout'],
            'master',
         );

         $Interaction = static function (
            $Process,
            array &$Pipes,
            int $PID,
            string &$stdout,
            string &$stderr,
         ) use ($Decode, $LivePort): array {
            $Ready = null;
            $readyDeadline = hrtime(true) + 3_000_000_000;
            do {
               $stdout .= (string) stream_get_contents($Pipes[1]);
               $stderr .= (string) stream_get_contents($Pipes[2]);
               $Ready = $Decode($stdout, 'ready');
               if ($Ready !== null) {
                  break;
               }
               $Status = proc_get_status($Process);
               if ($Status['running'] === false) {
                  break;
               }
               usleep(1_000);
            } while (hrtime(true) < $readyDeadline);

            $payload = 'claim-release-control';
            $response = null;
            $sent = false;
            $attempts = 0;
            $responseDeadline = hrtime(true) + 2_000_000_000;
            while ($Ready !== null && hrtime(true) < $responseDeadline) {
               $attempts++;
               $Client = @stream_socket_client(
                  "udp://127.0.0.1:{$LivePort}",
                  $code,
                  $message,
                  0.2,
                  STREAM_CLIENT_CONNECT,
               );
               if (is_resource($Client)) {
                  stream_set_blocking($Client, false);
                  $written = @fwrite($Client, $payload);
                  $sent = $sent || $written === strlen($payload);
                  $read = [$Client];
                  $write = null;
                  $except = null;
                  $selected = @stream_select(
                     $read,
                     $write,
                     $except,
                     0,
                     100_000,
                  );
                  if ($selected === 1) {
                     $reply = @fread($Client, 65_535);
                     if (is_string($reply) && $reply !== '') {
                        $response = $reply;
                     }
                  }
                  fclose($Client);
               }
               if ($response !== null) {
                  break;
               }
               usleep(10_000);
            }

            $commanded = is_resource($Pipes[0])
               && fwrite($Pipes[0], "stop\n") === 5;
            if (is_resource($Pipes[0])) {
               fflush($Pipes[0]);
            }

            return [
               'ready' => $Ready,
               'sent' => $sent,
               'attempts' => $attempts,
               'response' => $response,
               'commanded' => $commanded,
               'group' => $PID,
            ];
         };
         $LiveResult = $Run(
            $LiveScript,
            [
               ...$Environment,
               'H7_START_PORT' => (string) $LivePort,
            ],
            $Interaction,
         );
         $LiveResult['final'] = $Decode(
            (string) $LiveResult['stdout'],
            'final',
         );

         $ParentMaskAfter = [];
         pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $ParentMaskAfter);
         pcntl_sigprocmask(SIG_SETMASK, $ParentMaskAfter);
         sort($ParentMaskAfter);
         $ParentHandlersAfter = [
            SIGCHLD => pcntl_signal_get_handler(SIGCHLD),
            SIGTERM => pcntl_signal_get_handler(SIGTERM),
         ];
         $ParentState = [
            'mask' => $ParentMaskAfter === $ParentMaskExpected,
            'handlers' => $ParentHandlersAfter === $ParentHandlers,
            'async' => pcntl_async_signals() === $parentAsync,
         ];
      }
      finally {
         pcntl_sigprocmask(SIG_SETMASK, $ParentMask);
         foreach ($ParentHandlers as $signal => $Handler) {
            pcntl_signal(
               $signal,
               $Handler === false ? SIG_DFL : $Handler,
               false,
            );
         }
         pcntl_async_signals($parentAsync);
      }

      $MasterData = $MasterResult['data'] ?? null;
      $MasterObserved = [
         'ports' => $MasterPort > 0
            && $LivePort > 0
            && $MasterPort !== $LivePort,
         'status' => $MasterResult['status'] ?? null,
         'timed_out' => $MasterResult['timed_out'] ?? null,
         'group_leaked' => $MasterResult['group_leaked'] ?? null,
         'group_clean' => $MasterResult['group_clean'] ?? null,
         'session' => $MasterData['session'] ?? null,
         'start' => $MasterData['start'] ?? null,
         'starting_null' => $MasterData['starting_null'] ?? null,
         'signal_delivered' => $MasterData['signal_delivered'] ?? null,
         'signal_after_release' => $MasterData['signal_after_release'] ?? null,
         'mask_restored' => $MasterData['mask_restored'] ?? null,
         'state_clean' => $MasterData['state_clean'] ?? null,
         'cleanup_mask' => $MasterData['cleanup_mask_restored'] ?? null,
         'cleanup_handler' => $MasterData['cleanup_handler_restored'] ?? null,
         'error' => $MasterData['error'] ?? null,
      ];
      yield new Assertion(description: 'workers=0 releases the master start claim and mask')
         ->expect(
            $MasterObserved,
            Op::Identical,
            [
               'ports' => true,
               'status' => 0,
               'timed_out' => false,
               'group_leaked' => false,
               'group_clean' => true,
               'session' => true,
               'start' => true,
               'starting_null' => true,
               'signal_delivered' => true,
               'signal_after_release' => true,
               'mask_restored' => true,
               'state_clean' => true,
               'cleanup_mask' => true,
               'cleanup_handler' => true,
               'error' => '',
            ],
         )
         ->assert();

      $Ready = $LiveResult['interaction']['ready'] ?? null;
      $Final = $LiveResult['final'] ?? null;
      $LiveObserved = [
         'status' => $LiveResult['status'] ?? null,
         'timed_out' => $LiveResult['timed_out'] ?? null,
         'group_leaked' => $LiveResult['group_leaked'] ?? null,
         'group_clean' => $LiveResult['group_clean'] ?? null,
         'session' => $Ready['session'] ?? null,
         'start' => $Ready['start'] ?? null,
         'starting_null' => $Ready['starting_null'] ?? null,
         'mask_restored' => $Ready['mask_restored'] ?? null,
         'worker' => isset($Ready['worker']) && $Ready['worker'] > 0,
         'sent' => $LiveResult['interaction']['sent'] ?? null,
         'response' => $LiveResult['interaction']['response'] ?? null,
         'commanded' => $LiveResult['interaction']['commanded'] ?? null,
         'stop_received' => $Final['commanded'] ?? null,
         'term_sent' => $Final['term_sent'] ?? null,
         'reaped' => $Final['reaped'] ?? null,
         'forced' => $Final['forced'] ?? null,
         'exited_zero' => $Final['exited_zero'] ?? null,
         'worker_gone' => $Final['worker_gone'] ?? null,
         'state_clean' => $Final['state_clean'] ?? null,
         'cleanup_mask' => $Final['cleanup_mask_restored'] ?? null,
         'cleanup_handler' => $Final['cleanup_handler_restored'] ?? null,
         'error' => $Ready['error'] ?? null,
      ];
      yield new Assertion(description: 'worker releases start, serves UDP and exits on SIGTERM')
         ->expect(
            $LiveObserved,
            Op::Identical,
            [
               'status' => 0,
               'timed_out' => false,
               'group_leaked' => false,
               'group_clean' => true,
               'session' => true,
               'start' => true,
               'starting_null' => true,
               'mask_restored' => true,
               'worker' => true,
               'sent' => true,
               'response' => 'h7-start:mask-ok:signal-ok:claim-release-control',
               'commanded' => true,
               'stop_received' => true,
               'term_sent' => true,
               'reaped' => true,
               'forced' => false,
               'exited_zero' => true,
               'worker_gone' => true,
               'state_clean' => true,
               'cleanup_mask' => true,
               'cleanup_handler' => true,
               'error' => '',
            ],
         )
         ->assert();

      yield new Assertion(description: 'subprocess probes preserve parent signal state')
         ->expect(
            $ParentState,
            Op::Identical,
            [
               'mask' => true,
               'handlers' => true,
               'async' => true,
            ],
         )
         ->assert();
   }),
);
