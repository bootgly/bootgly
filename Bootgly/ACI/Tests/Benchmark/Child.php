<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_BINARY;
use function bin2hex;
use function clearstatcache;
use function dirname;
use function fclose;
use function file_get_contents;
use function filesize;
use function function_exists;
use function fwrite;
use function getenv;
use function is_array;
use function is_file;
use function is_resource;
use function is_string;
use function json_encode;
use function max;
use function microtime;
use function posix_kill;
use function preg_replace;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function rename;
use function sprintf;
use function strlen;
use function substr;
use function trim;
use function unlink;
use function usleep;
use RuntimeException;


/**
 * Execute benchmark children without sharing the harness output descriptors.
 */
final class Child
{
   private const GROUP_LAUNCHER = <<<'PHP'
$command = array_slice($argv, 1);
$executable = array_shift($command);
$ready = getenv('BOOTGLY_PROCESS_GROUP_READY');

if (!is_string($executable) || $executable === '' || !is_string($ready) || $ready === '') {
   fwrite(STDERR, "Invalid isolated benchmark child launch.\n");
   exit(125);
}
if (posix_setsid() < 0) {
   fwrite(STDERR, "Could not create isolated benchmark child process group.\n");
   exit(125);
}

$temporary = $ready . '.tmp';
if (file_put_contents($temporary, (string) getmypid(), LOCK_EX) === false || !rename($temporary, $ready)) {
   fwrite(STDERR, "Could not acknowledge isolated benchmark child process group.\n");
   exit(125);
}

putenv('BOOTGLY_PROCESS_GROUP_READY');
unset($_ENV['BOOTGLY_PROCESS_GROUP_READY'], $_SERVER['BOOTGLY_PROCESS_GROUP_READY']);
pcntl_exec($executable, $command);
fwrite(STDERR, "Could not execute isolated benchmark child.\n");
exit(127);
PHP;

   private int $sequence = 0;


   public function __construct (private readonly Artifacts $Artifacts)
   {
   }

   /**
    * Execute one argv command and retain its stdout, stderr and exit status.
    *
    * @param array<int,string> $command
    * @param array<string,string>|null $environment Null inherits the current environment.
    *
    * @return array{
    *    exit:int,
    *    stdout:string,
    *    stderr:string,
    *    status:string
    * }
    */
   public function run (
      array $command,
      string $scope,
      null|array $environment = null,
      null|string $input = null,
      null|float $timeout = null,
      float $grace = 2.0,
   ): array
   {
      if (self::validate($command) === false) {
         throw new RuntimeException('A benchmark child command must be a non-empty argv string list');
      }
      if ($timeout !== null && $timeout < 0) {
         throw new \InvalidArgumentException('Benchmark child timeout can not be negative');
      }
      if ($grace < 0) {
         throw new \InvalidArgumentException('Benchmark child termination grace can not be negative');
      }
      foreach (['pcntl_exec', 'posix_kill', 'posix_setsid'] as $function) {
         if (self::verify($function) === false) {
            throw new RuntimeException("Benchmark child process-group isolation requires {$function}()");
         }
      }

      $scope = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $scope), '-.');
      if ($scope === '') {
         $scope = 'child';
      }

      $this->sequence++;
      $token = sprintf('%04d-%s-%s', $this->sequence, $scope, bin2hex(random_bytes(8)));
      $stdoutRelative = "children/{$token}/stdout.log";
      $stderrRelative = "children/{$token}/stderr.log";
      $statusRelative = "children/{$token}/status.json";
      $stagingRelative = "children/.capture-{$token}";
      $stdoutCapture = $this->Artifacts->resolve("{$stagingRelative}/stdout.log");
      $stderrCapture = $this->Artifacts->resolve("{$stagingRelative}/stderr.log");
      $staging = dirname($stdoutCapture);
      $groupReady = $staging . DIRECTORY_SEPARATOR . '.group.ready';
      $final = $this->Artifacts->directory . DIRECTORY_SEPARATOR . 'children'
         . DIRECTORY_SEPARATOR . $token;

      $descriptors = [
         0 => ['pipe', 'r'],
         1 => ['file', $stdoutCapture, 'xb'],
         2 => ['file', $stderrCapture, 'xb'],
      ];
      $pipes = [];
      $launchEnvironment = (array) ($environment ?? getenv());
      $launchEnvironment['BOOTGLY_PROCESS_GROUP_READY'] = $groupReady;
      $processCommand = [
         PHP_BINARY,
         '-r',
         self::GROUP_LAUNCHER,
         '--',
         ...$command,
      ];
      $Process = proc_open(
         $processCommand,
         $descriptors,
         $pipes,
         null,
         $launchEnvironment,
         ['bypass_shell' => true]
      );

      if (is_resource($Process) === false) {
         // Keep any descriptor evidence in the staging directory. Its unique
         // name makes the failed publication attributable to this child.
         $this->record("children/{$token}.failure.json", [
            'state' => 'start-failed',
            'exit' => 127,
            'stdout' => $this->Artifacts->relate("{$stagingRelative}/stdout.log"),
            'stderr' => $this->Artifacts->relate("{$stagingRelative}/stderr.log"),
         ]);
         throw new RuntimeException("Can not start benchmark child: {$scope}");
      }

      $processStatus = proc_get_status($Process);
      $PID = $processStatus['pid'];
      $groupDeadline = microtime(true) + 2.0;
      $groupStarted = false;

      do {
         if (
            is_file($groupReady)
            && trim((string) @file_get_contents($groupReady)) === (string) $PID
         ) {
            $groupStarted = true;
            break;
         }

         $processStatus = proc_get_status($Process);
         if (!$processStatus['running']) {
            break;
         }

         usleep(1_000);
      } while (microtime(true) < $groupDeadline);

      @unlink($groupReady . '.tmp');
      @unlink($groupReady);

      if (!$groupStarted) {
         if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
         }
         @proc_terminate($Process, 9);
         $closedExit = proc_close($Process);
         $this->record("children/{$token}.failure.json", [
            'state' => 'process-group-failed',
            'exit' => $closedExit >= 0 ? $closedExit : 125,
            'stdout' => $this->Artifacts->relate("{$stagingRelative}/stdout.log"),
            'stderr' => $this->Artifacts->relate("{$stagingRelative}/stderr.log"),
         ]);
         throw new RuntimeException("Can not establish isolated benchmark child process group: {$scope}");
      }

      if (isset($pipes[0]) && is_resource($pipes[0])) {
         if ($input !== null) {
            $length = strlen($input);
            $offset = 0;
            while ($offset < $length) {
               $bytes = fwrite($pipes[0], substr($input, $offset));
               if ($bytes === false || $bytes === 0) {
                  break;
               }
               $offset += $bytes;
            }
         }
         fclose($pipes[0]);
      }

      $terminal = $this->wait($Process, $PID, $timeout, $grace);
      $exit = $terminal['exit'];
      // ! Publish both channels with one directory rename. Readers can observe
      //   either the complete pair or no final child directory, never one
      //   channel without the other.
      if (@rename($staging, $final) === false) {
         $this->record("children/{$token}.failure.json", [
            'state' => 'publication-failed',
            'exit' => $exit,
            'stdout' => $this->Artifacts->relate("{$stagingRelative}/stdout.log"),
            'stderr' => $this->Artifacts->relate("{$stagingRelative}/stderr.log"),
         ]);
         throw new RuntimeException("Can not atomically publish child output: {$scope}");
      }
      $stdout = $final . DIRECTORY_SEPARATOR . 'stdout.log';
      $stderr = $final . DIRECTORY_SEPARATOR . 'stderr.log';
      clearstatcache(true, $stdout);
      clearstatcache(true, $stderr);

      $JSON = json_encode([
         'state' => $terminal['state'],
         'exit' => $exit,
         'timed-out' => $terminal['timed-out'],
         'signal' => $terminal['signal'],
         'process-group' => $PID,
         'isolation' => 'session-process-group',
         'stdout' => $this->Artifacts->relate($stdoutRelative),
         'stdout-bytes' => is_file($stdout) ? filesize($stdout) : 0,
         'stderr' => $this->Artifacts->relate($stderrRelative),
         'stderr-bytes' => is_file($stderr) ? filesize($stderr) : 0,
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
      $status = $this->Artifacts->write($statusRelative, $JSON);

      return [
         'exit' => $exit,
         'stdout' => $this->Artifacts->relate($stdoutRelative),
         'stderr' => $this->Artifacts->relate($stderrRelative),
         'status' => $status,
      ];
   }

   /**
    * Wait for one child, with optional TERM/KILL timeout escalation.
    *
    * @param resource $Process
    * @return array{exit:int,state:string,timed-out:bool,signal:null|int}
    */
   private function wait (mixed $Process, int $PGID, null|float $timeout, float $grace): array
   {
      $deadline = $timeout === null ? null : microtime(true) + $timeout;
      $observedExit = null;
      $signal = null;
      $timedOut = false;
      $termination = 'completed';
      $cleanupFailed = false;

      $Poll = static function () use (&$Process, &$observedExit, &$signal, $PGID): bool {
         if (is_resource($Process)) {
            $status = proc_get_status($Process);
            if (!$status['running']) {
               $observedExit = $status['exitcode'] >= 0
                  ? $status['exitcode']
                  : $observedExit;
               $signal = $status['signaled']
                  ? $status['termsig']
                  : $signal;
               $closedExit = proc_close($Process);
               $Process = null;
               $observedExit ??= $closedExit >= 0 ? $closedExit : null;
            }
            else {
               return true;
            }
         }

         return @posix_kill(-$PGID, 0);
      };
      $Signal = static function (int $number) use (&$Process, $PGID): void {
         if (@posix_kill(-$PGID, $number)) {
            return;
         }
         if (is_resource($Process)) {
            @proc_terminate($Process, $number);
         }
      };

      $running = $Poll();
      while ($running) {
         if ($deadline !== null && microtime(true) >= $deadline) {
            $timedOut = true;
            $termination = 'terminated';
            $Signal(15);
            $termDeadline = microtime(true) + $grace;
            do {
               usleep(10_000);
               $running = $Poll();
            } while ($running && microtime(true) < $termDeadline);

            if ($running) {
               $termination = 'killed';
               $Signal(9);
               $killDeadline = microtime(true) + max(1.0, $grace);
               do {
                  usleep(10_000);
                  $running = $Poll();
               } while ($running && microtime(true) < $killDeadline);
            }

            $cleanupFailed = $running;
            break;
         }

         usleep(10_000);
         $running = $Poll();
      }

      if ($cleanupFailed) {
         throw new RuntimeException(
            "Benchmark child process group {$PGID} survived TERM/KILL escalation; staged output was not published"
         );
      }

      return [
         'exit' => $timedOut
            ? 124
            : ($observedExit ?? ($signal !== null ? 128 + $signal : 255)),
         'state' => $timedOut ? $termination : ($signal !== null ? 'signaled' : 'completed'),
         'timed-out' => $timedOut,
         'signal' => $signal,
      ];
   }

   /**
    * Keep runtime argv validation at the public process boundary.
    *
    * @param array<int,mixed> $command
    */
   private static function validate (array $command): bool
   {
      if ($command === []) {
         return false;
      }

      foreach ($command as $argument) {
         if (!is_string($argument)) {
            return false;
         }
      }

      return true;
   }

   /**
    * Preserve runtime portability checks without specializing the function name.
    */
   private static function verify (string $function): bool
   {
      return function_exists($function);
   }

   /**
    * Best-effort failure metadata must not destroy the original child error.
    *
    * @param array<string,int|string> $metadata
    */
   private function record (string $relative, array $metadata): void
   {
      try {
         $JSON = json_encode(
            $metadata,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
         ) . "\n";
         $this->Artifacts->write($relative, $JSON);
      }
      catch (\Throwable) {
         // The unique staging directory remains the primary failure evidence.
      }
   }
}
