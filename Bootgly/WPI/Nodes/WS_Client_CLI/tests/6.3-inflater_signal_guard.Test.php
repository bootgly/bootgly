<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suite\Test;


/**
 * H8 regression — inflater warning isolation must preserve application signals.
 */
return new Test(
   description: 'H8: WS inflater preserves warning handlers across async signals',
   skip: extension_loaded('zlib') === false
      || function_exists('proc_open') === false
      || function_exists('proc_get_status') === false
      || function_exists('proc_terminate') === false
      || function_exists('pcntl_async_signals') === false
      || function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false,

   test: function () {
      /**
       * @param array<int,string> $Options
       *
       * @return array{
       *    status: int,
       *    timed_out: bool,
       *    group_leaked: bool,
       *    group_clean: bool,
       *    stdout: string,
       *    stderr: string,
       *    data: null|array<string,mixed>
       * }
       */
      $Run = static function (string $Script, array $Options = []): array {
         $SessionScript = <<<'PHP'
if (posix_setsid() === false) {
   fwrite(STDERR, "H8 could not create the probe process group\n");
   exit(125);
}
PHP;
         $Arguments = [PHP_BINARY];
         foreach ($Options as $Option) {
            $Arguments[] = '-d';
            $Arguments[] = $Option;
         }
         $Arguments[] = '-r';
         $Arguments[] = "$SessionScript\n$Script";

         $Descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];
         $Environment = (array) getenv();
         $root = BOOTGLY_ROOT_DIR;
         $Environment['H8_AUTOBOOT'] = "{$root}autoboot.php";
         $Process = proc_open(
            $Arguments,
            $Descriptors,
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment
         );
         if (is_resource($Process) === false) {
            return [
               'status' => -1,
               'timed_out' => false,
               'group_leaked' => false,
               'group_clean' => true,
               'stdout' => '',
               'stderr' => 'proc_open failed',
               'data' => null,
            ];
         }

         fclose($Pipes[0]);
         stream_set_blocking($Pipes[1], false);
         stream_set_blocking($Pipes[2], false);
         $ProcessStatus = proc_get_status($Process);
         $processID = (int) $ProcessStatus['pid'];
         $stdoutChunks = [];
         $stderrChunks = [];
         $timedOut = false;
         $exitCode = null;
         $deadline = hrtime(true) + 5000000000;

         do {
            $stdoutChunks[] = (string) stream_get_contents($Pipes[1]);
            $stderrChunks[] = (string) stream_get_contents($Pipes[2]);
            $ProcessStatus = proc_get_status($Process);
            if ($ProcessStatus['running'] === false) {
               $exitCode = (int) $ProcessStatus['exitcode'];
               break;
            }
            if (hrtime(true) >= $deadline) {
               $timedOut = true;
               posix_kill(-$processID, 15);
               proc_terminate($Process, 15);
               $terminateDeadline = hrtime(true) + 250000000;
               do {
                  usleep(1000);
                  $ProcessStatus = proc_get_status($Process);
               } while (
                  $ProcessStatus['running']
                  && hrtime(true) < $terminateDeadline
               );
               if ($ProcessStatus['running']) {
                  posix_kill(-$processID, 9);
                  proc_terminate($Process, 9);
               }
               else {
                  // @ The direct child exited; kill any descendant that kept
                  //   the dedicated process group alive.
                  posix_kill(-$processID, 9);
               }
               break;
            }

            usleep(1000);
         } while (true);

         $stdoutChunks[] = (string) stream_get_contents($Pipes[1]);
         $stderrChunks[] = (string) stream_get_contents($Pipes[2]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);
         $closeStatus = proc_close($Process);
         $groupLeaked = posix_kill(-$processID, 0);
         if ($groupLeaked) {
            posix_kill(-$processID, 9);
            usleep(10000);
         }
         $groupClean = posix_kill(-$processID, 0) === false;
         $status = $timedOut
            ? 124
            : ($exitCode !== null && $exitCode >= 0 ? $exitCode : $closeStatus);
         $stdout = implode('', $stdoutChunks);
         $stderr = implode('', $stderrChunks);
         $Data = json_decode(trim($stdout), true);

         return [
            'status' => $status,
            'timed_out' => $timedOut,
            'group_leaked' => $groupLeaked,
            'group_clean' => $groupClean,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'data' => is_array($Data) ? $Data : null,
         ];
      };

      $DisabledScript = <<<'PHP'
use Bootgly\WPI\Modules\WS\Inflater;

require getenv('H8_AUTOBOOT');

$Evidence = [
   'async_function' => function_exists('pcntl_async_signals'),
   'dispatch_function' => function_exists('pcntl_signal_dispatch'),
   'output_intact' => false,
   'inactive_output_intact' => false,
   'inactive_escaped' => '',
   'warning_count' => 0,
   'handler_restored' => false,
   'handler_cleanup' => false,
   'reporting_restored' => false,
   'reporting_cleanup' => false,
   'async_restored' => false,
   'async_cleanup' => false,
   'escaped' => '',
];

if ($Evidence['async_function'] === false) {
   $Evidence['skipped'] = true;
   echo json_encode($Evidence);
   exit(0);
}

$payload = str_repeat(hash('sha256', 'H8-disabled-dispatch-control', true), 512);
$Deflator = deflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
$compressed = is_object($Deflator)
   ? deflate_add($Deflator, $payload, ZLIB_SYNC_FLUSH)
   : false;
if (is_string($compressed) && str_ends_with($compressed, "\x00\x00\xff\xff")) {
   $compressed = (string) substr($compressed, 0, -4);
}

$reporting = error_reporting();
$previousAsync = pcntl_async_signals(true);
$warnings = 0;
$Handler = static function (
   int $level,
   string $message,
   string $file,
   int $line
) use (&$warnings): bool {
   $warnings++;

   return true;
};
$Inspector = static function (
   int $level,
   string $message,
   string $file,
   int $line
): bool {
   return true;
};
$PreviousHandler = set_error_handler($Handler, E_WARNING);

try {
   $Inflator = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
   if (is_object($Inflator) === false || is_string($compressed) === false) {
      throw new RuntimeException('could not initialize the valid DEFLATE control');
   }

   $output = Inflater::inflate($Inflator, $compressed, strlen($payload));
   $Evidence['output_intact'] = $output === $payload;
}
catch (Throwable $Throwable) {
   $class = $Throwable::class;
   $message = $Throwable->getMessage();
   $Evidence['escaped'] = "$class: $message";

   try {
      pcntl_async_signals(false);
      $InactiveInflator = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
      if (is_object($InactiveInflator) === false || is_string($compressed) === false) {
         throw new RuntimeException('could not initialize the inactive signal control');
      }

      $inactiveOutput = Inflater::inflate(
         $InactiveInflator,
         $compressed,
         strlen($payload)
      );
      $Evidence['inactive_output_intact'] = $inactiveOutput === $payload;
   }
   catch (Throwable $InactiveThrowable) {
      $class = $InactiveThrowable::class;
      $message = $InactiveThrowable->getMessage();
      $Evidence['inactive_escaped'] = "$class: $message";
   }
   finally {
      pcntl_async_signals(true);
   }
}
finally {
   $CurrentHandler = set_error_handler($Inspector, E_WARNING);
   $Evidence['handler_restored'] = $CurrentHandler === $Handler;
   restore_error_handler();

   $Evidence['warning_count'] = $warnings;
   $Evidence['reporting_restored'] = error_reporting() === $reporting;
   $Evidence['async_restored'] = pcntl_async_signals() === true;

   pcntl_async_signals($previousAsync);
   $Evidence['async_cleanup'] = pcntl_async_signals() === $previousAsync;
   restore_error_handler();

   $CurrentHandler = set_error_handler($Inspector, E_WARNING);
   $Evidence['handler_cleanup'] = $CurrentHandler === $PreviousHandler;
   restore_error_handler();

   error_reporting($reporting);
   $Evidence['reporting_cleanup'] = error_reporting() === $reporting;
}

$Evidence['skipped'] = false;
echo json_encode($Evidence);
PHP;

      $Disabled = $Run(
         $DisabledScript,
         ['disable_functions=pcntl_signal_dispatch']
      );
      $DisabledEvidence = $Disabled['data'];
      $disabledDiagnostic = json_encode($Disabled);

      yield assert(
         assertion: $Disabled['status'] === 0
            && $Disabled['timed_out'] === false
            && $Disabled['group_leaked'] === false
            && $Disabled['group_clean'] === true
            && $Disabled['stderr'] === ''
            && is_array($DisabledEvidence)
            && ($DisabledEvidence['skipped'] ?? true) === false
            && $DisabledEvidence['async_function'] === true
            && $DisabledEvidence['dispatch_function'] === false
            && $DisabledEvidence['output_intact'] === false
            && $DisabledEvidence['inactive_output_intact'] === true
            && $DisabledEvidence['inactive_escaped'] === ''
            && $DisabledEvidence['warning_count'] === 0
            && $DisabledEvidence['handler_restored'] === true
            && $DisabledEvidence['handler_cleanup'] === true
            && $DisabledEvidence['reporting_restored'] === true
            && $DisabledEvidence['reporting_cleanup'] === true
            && $DisabledEvidence['async_restored'] === true
            && $DisabledEvidence['async_cleanup'] === true
            && $DisabledEvidence['escaped']
               === 'LogicException: Async PCNTL requires pcntl_signal_dispatch',
         description: "H8 must fail closed when async signal dispatch is disabled: $disabledDiagnostic"
      );

      $stressFunctions = [
         'pcntl_fork',
         'pcntl_signal',
         'pcntl_signal_dispatch',
         'pcntl_signal_get_handler',
         'pcntl_waitpid',
         'pcntl_wifexited',
         'pcntl_wexitstatus',
         'posix_getpid',
         'posix_getppid',
         'stream_socket_pair',
      ];
      $missingStressFunctions = [];
      foreach ($stressFunctions as $function) {
         if (function_exists($function) === false) {
            $missingStressFunctions[] = $function;
         }
      }
      if ($missingStressFunctions !== []) {
         $missing = json_encode($missingStressFunctions);
         yield assert(
            assertion: true,
            description: "Skipped H8 async signal stress; missing=$missing"
         );
         return;
      }

      $SignalScript = <<<'PHP'
use Bootgly\WPI\Modules\WS\Inflater;

require getenv('H8_AUTOBOOT');

$Functions = [
   'pcntl_async_signals',
   'pcntl_fork',
   'pcntl_signal',
   'pcntl_signal_dispatch',
   'pcntl_signal_get_handler',
   'pcntl_waitpid',
   'pcntl_wifexited',
   'pcntl_wexitstatus',
   'posix_getpid',
   'posix_getppid',
   'posix_kill',
   'posix_setsid',
   'stream_socket_pair',
];
$MissingFunctions = [];
foreach ($Functions as $function) {
   if (function_exists($function) === false) {
      $MissingFunctions[] = $function;
   }
}
if ($MissingFunctions !== []) {
   echo json_encode([
      'supported' => false,
      'missing' => $MissingFunctions,
      'warm_control' => false,
      'rounds' => [],
   ]);
   exit(0);
}

$payload = str_repeat(hash('sha256', 'H8-signal-control', true), 262144);
$Deflator = deflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
$compressed = is_object($Deflator)
   ? deflate_add($Deflator, $payload, ZLIB_SYNC_FLUSH)
   : false;
if (is_string($compressed) && str_ends_with($compressed, "\x00\x00\xff\xff")) {
   $compressed = (string) substr($compressed, 0, -4);
}

$WarmInflator = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
$warm = is_object($WarmInflator) && is_string($compressed)
   ? Inflater::inflate($WarmInflator, $compressed, strlen($payload))
   : false;
$Rounds = [];

for ($iteration = 0; $iteration < 3; $iteration++) {
   $signal = $iteration % 2 === 0 ? SIGUSR1 : SIGUSR2;
   $Round = [
      'iteration' => $iteration,
      'signal' => $signal,
      'signals' => 0,
      'signals_during_inflate' => 0,
      'application_handler_warnings' => 0,
      'unexpected_warnings' => 0,
      'output_intact' => false,
      'child_reaped' => false,
      'child_timeout' => false,
      'child_status' => null,
      'error_handler_restored' => false,
      'error_handler_cleanup' => false,
      'signal_handler_restored' => false,
      'signal_handler_cleanup' => false,
      'reporting_restored' => false,
      'reporting_cleanup' => false,
      'async_restored' => false,
      'async_cleanup' => false,
      'escaped' => '',
   ];
   $Sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
   $PID = -1;
   $waited = -1;
   $reporting = error_reporting();
   $previousAsync = pcntl_async_signals(true);
   $PreviousSignal = pcntl_signal_get_handler($signal);
   $processID = posix_getpid();
   $warningPath = "/h8-signal-warning-$processID-$iteration";
   $signals = 0;
   $signalsDuringInflate = 0;
   $applicationWarnings = 0;
   $unexpectedWarnings = 0;

   $Application = static function (
      int $level,
      string $message,
      string $file,
      int $line
   ) use (
      &$applicationWarnings,
      &$unexpectedWarnings,
      $warningPath
   ): bool {
      if ($level === E_WARNING && str_contains($message, $warningPath)) {
         $applicationWarnings++;
      }
      else {
         $unexpectedWarnings++;
      }

      return true;
   };
   $Inspector = static function (
      int $level,
      string $message,
      string $file,
      int $line
   ): bool {
      return true;
   };
   $Signal = static function () use (
      &$signals,
      &$signalsDuringInflate,
      $warningPath
   ): void {
      $signals++;
      foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $Frame) {
         if (
            ($Frame['class'] ?? '') === Inflater::class
            && ($Frame['function'] ?? '') === 'inflate'
         ) {
            $signalsDuringInflate++;
            break;
         }
      }
      $Missing = fopen($warningPath, 'rb');
   };
   $PreviousError = set_error_handler($Application, E_WARNING);

   try {
      if (is_array($Sockets) === false) {
         throw new RuntimeException('could not create the signal start channel');
      }
      if (pcntl_signal($signal, $Signal) === false) {
         throw new RuntimeException('could not install the signal callback');
      }

      $PID = pcntl_fork();
      if ($PID === -1) {
         throw new RuntimeException('could not fork the signal emitter');
      }
      if ($PID === 0) {
         fclose($Sockets[0]);
         $started = fread($Sockets[1], 1);
         $parentPID = posix_getppid();
         if ($started !== 'G') {
            exit(2);
         }
         for ($index = 0; $index < 20000; $index++) {
            if (posix_kill($parentPID, $signal) === false) {
               exit(3);
            }
         }
         fclose($Sockets[1]);
         exit(0);
      }

      fclose($Sockets[1]);
      $Sockets[1] = null;
      $Inflator = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
      if (is_object($Inflator) === false || is_string($compressed) === false) {
         throw new RuntimeException('could not initialize the signal control');
      }

      if (fwrite($Sockets[0], 'G') !== 1) {
         throw new RuntimeException('could not start the signal emitter');
      }

      $output = Inflater::inflate($Inflator, $compressed, strlen($payload));
      $Round['output_intact'] = $output === $payload;

      $deadline = hrtime(true) + 2000000000;
      do {
         $waited = pcntl_waitpid($PID, $status, WNOHANG);
         if ($waited === 0) {
            usleep(1000);
         }
      } while ($waited === 0 && hrtime(true) < $deadline);

      if ($waited !== $PID) {
         $Round['child_timeout'] = true;
         posix_kill($PID, SIGKILL);
         $waited = pcntl_waitpid($PID, $status);
      }
      $Round['child_reaped'] = $waited === $PID;
      $Round['child_status'] = $status;
      if (function_exists('pcntl_signal_dispatch')) {
         pcntl_signal_dispatch();
      }

      $CurrentError = set_error_handler($Inspector, E_WARNING);
      $Round['error_handler_restored'] = $CurrentError === $Application;
      restore_error_handler();
      $Round['signal_handler_restored'] =
         pcntl_signal_get_handler($signal) === $Signal;
      $Round['reporting_restored'] = error_reporting() === $reporting;
      $Round['async_restored'] = pcntl_async_signals() === true;
   }
   catch (Throwable $Throwable) {
      $class = $Throwable::class;
      $message = $Throwable->getMessage();
      $Round['escaped'] = "$class: $message";
   }
   finally {
      if (is_array($Sockets)) {
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               fclose($Socket);
            }
         }
      }
      if ($PID > 0 && $waited !== $PID) {
         posix_kill($PID, SIGKILL);
         pcntl_waitpid($PID, $status);
      }

      pcntl_signal($signal, $PreviousSignal);
      $Round['signal_handler_cleanup'] =
         pcntl_signal_get_handler($signal) === $PreviousSignal;
      pcntl_async_signals($previousAsync);
      $Round['async_cleanup'] = pcntl_async_signals() === $previousAsync;

      restore_error_handler();
      $CurrentError = set_error_handler($Inspector, E_WARNING);
      $Round['error_handler_cleanup'] = $CurrentError === $PreviousError;
      restore_error_handler();

      error_reporting($reporting);
      $Round['reporting_cleanup'] = error_reporting() === $reporting;
      $Round['signals'] = $signals;
      $Round['signals_during_inflate'] = $signalsDuringInflate;
      $Round['application_handler_warnings'] = $applicationWarnings;
      $Round['unexpected_warnings'] = $unexpectedWarnings;
   }

   $Rounds[] = $Round;
}

echo json_encode([
   'supported' => true,
   'missing' => [],
   'payload_bytes' => strlen($payload),
   'compressed_bytes' => is_string($compressed) ? strlen($compressed) : 0,
   'warm_control' => $warm === $payload,
   'rounds' => $Rounds,
]);
PHP;

      $Signals = [
         'dispatch' => $Run($SignalScript),
      ];
      foreach ($Signals as $mode => $Signal) {
         $SignalEvidence = $Signal['data'];
         $secure = $Signal['status'] === 0
            && $Signal['timed_out'] === false
            && $Signal['group_leaked'] === false
            && $Signal['group_clean'] === true
            && $Signal['stderr'] === ''
            && is_array($SignalEvidence)
            && ($SignalEvidence['supported'] ?? false) === true;
         if ($secure) {
            $secure = $SignalEvidence['warm_control'] === true
               && $SignalEvidence['payload_bytes'] === 8388608
               && $SignalEvidence['compressed_bytes'] > 4096
               && count($SignalEvidence['rounds']) === 3;

            foreach ($SignalEvidence['rounds'] as $Round) {
               $secure = $secure
                  && $Round['signals'] > 0
                  && $Round['signals_during_inflate'] > 0
                  && $Round['signals']
                     === $Round['application_handler_warnings']
                  && $Round['unexpected_warnings'] === 0
                  && $Round['output_intact'] === true
                  && $Round['child_reaped'] === true
                  && $Round['child_timeout'] === false
                  && pcntl_wifexited($Round['child_status'])
                  && pcntl_wexitstatus($Round['child_status']) === 0
                  && $Round['error_handler_restored'] === true
                  && $Round['error_handler_cleanup'] === true
                  && $Round['signal_handler_restored'] === true
                  && $Round['signal_handler_cleanup'] === true
                  && $Round['reporting_restored'] === true
                  && $Round['reporting_cleanup'] === true
                  && $Round['async_restored'] === true
                  && $Round['async_cleanup'] === true
                  && $Round['escaped'] === '';
            }
         }
         $signalDiagnostic = json_encode($Signal);

         yield assert(
            assertion: $secure,
            description: "H8 $mode signals must reach the application warning handler after inflate isolation: $signalDiagnostic"
         );
      }
   }
);
