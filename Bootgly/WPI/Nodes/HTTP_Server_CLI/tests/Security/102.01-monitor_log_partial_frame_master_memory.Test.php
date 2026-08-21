<?php


use Bootgly\ABI\IO\IPC\Pipe as IPCPipe;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers\Pipe as PipeHandler;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\Environments;
use Bootgly\CLI\UI\Components\Logs as LogsViewer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;

use const Bootgly\CLI;


if (! class_exists('HTTPServerCLIMonitorDrainProbe', false)) {
   class HTTPServerCLIMonitorDrainProbe extends TCPServer
   {
      public function attach (IPCPipe $Pipe): void
      {
         $this->LogPipe = $Pipe;
      }

      public function flush (LogsViewer $Viewer): int
      {
         return parent::flush($Viewer);
      }
   }
}


/**
 * Security PoC M5 — Monitor-mode log transport must never accept a partial
 * newline-delimited record or retain an unbounded unterminated fragment in the
 * master viewer.
 *
 * The request body is the attacker-influenced Throwable message. Each isolated
 * child runs the exact production HTTP exception reporter installed by
 * HTTP_Server_CLI::boot(Production), the real Logger Tap, JSON formatter and
 * nonblocking IPC Pipe. The parent drains the same chunks through Logs::feed(),
 * matching the Monitor master. A small record is the positive control.
 */
return new Test(
   description: 'Monitor log frames must be atomic and master-side retained bytes bounded',
   Separator: new Separator(line: true),

   request: static function (): string {
      $body = str_repeat('H', 1024 * 1024 + 1);

      return "POST /m5-monitor-log-frame HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Content-Type: application/octet-stream\r\n"
         . 'Content-Length: ' . strlen($body) . "\r\n"
         . "Connection: close\r\n\r\n"
         . $body;
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m5-monitor-log-frame', static function (
         Request $Request,
         Response $Response,
      ): Response {
         if (
            ! function_exists('pcntl_fork')
            || ! function_exists('pcntl_waitpid')
            || ! function_exists('posix_kill')
         ) {
            return $Response->JSON->send(['skip' => true]);
         }

         $message = $Request->Body->raw;
         // Match TCP_Server_CLI::pipe(): one complete log record per datagram.
         $Pipe = new IPCPipe(STREAM_SOCK_DGRAM);
         if ($Pipe->open() === false) {
            return $Response->code(500)->JSON->send([
               'skip' => false,
               'error' => 'pipe-open',
            ]);
         }

         $Viewer = new LogsViewer(CLI->Terminal->Input, CLI->Terminal->Output);
         $Partial = new ReflectionProperty($Viewer, 'partial');

         $Drain = static function () use ($Pipe, $Viewer): int {
            $bytes = 0;
            while (true) {
               $chunk = $Pipe->read(65536);
               if ($chunk === false || $chunk === '') {
                  break;
               }

               $bytes += strlen($chunk);
               $Viewer->feed($chunk);
            }

            return $bytes;
         };

         $Report = static function (string $message) use ($Pipe): array {
            $PID = pcntl_fork();
            if ($PID === -1) {
               return ['pid' => -1, 'status' => -1, 'exited' => false];
            }
            if ($PID === 0) {
               Display::show(Display::NONE);
               Logger::$Sinks = null;
               Logger::$Tap = new PipeHandler($Pipe);

               // Exact production reporter registration. This runs in the
               // disposable child so its function-local registration latch
               // cannot alter the Security suite worker.
               HTTP_Server_CLI::boot(Environments::Production);
               Catcher::respond(
                  null,
                  new Response,
                  new RuntimeException($message),
               );

               exit(0);
            }

            $status = 0;
            $waited = 0;
            $timedOut = false;
            $deadline = microtime(true) + 2.0;
            do {
               $waited = pcntl_waitpid($PID, $status, WNOHANG);
               if ($waited === $PID) {
                  break;
               }
               usleep(5_000);
            }
            while (microtime(true) < $deadline);

            if ($waited !== $PID) {
               $timedOut = true;
               @posix_kill($PID, SIGTERM);
               $terminateDeadline = microtime(true) + 0.25;
               do {
                  $waited = pcntl_waitpid($PID, $status, WNOHANG);
                  if ($waited === $PID) {
                     break;
                  }
                  usleep(5_000);
               }
               while (microtime(true) < $terminateDeadline);

               if ($waited !== $PID) {
                  @posix_kill($PID, SIGKILL);
                  $waited = pcntl_waitpid($PID, $status);
               }
            }

            return [
               'pid' => $PID,
               'status' => $status,
               'timed_out' => $timedOut,
               'exited' => $waited === $PID
                  && pcntl_wifexited($status)
                  && pcntl_wexitstatus($status) === 0,
            ];
         };

         // Positive control: a complete JSON line must become one Record and
         // leave no carried fragment.
         $controlProcess = $Report('M5-CONTROL');
         $controlBytes = $Drain();
         $controlRecords = count($Viewer->Records);
         $controlPartial = strlen((string) $Partial->getValue($Viewer));

         // Repeated large, newline-terminated JSON records exceed the kernel
         // socket buffer. The vulnerable writer reports each positive short
         // fwrite as success; every newline is in the discarded suffix, so the
         // Monitor master retains every prefix as one growing partial line.
         $rounds = [];
         $processes = [];
         for ($round = 0; $round < 8; $round++) {
            $processes[] = $Report($message);
            $read = $Drain();
            $rounds[] = [
               'read' => $read,
               'partial' => strlen((string) $Partial->getValue($Viewer)),
               'records' => count($Viewer->Records),
            ];
         }

         $finalPartial = strlen((string) $Partial->getValue($Viewer));

         // The real Monitor drain must yield after one bounded batch even
         // while complete datagrams remain readable for the next turn.
         $BudgetPipe = new IPCPipe(STREAM_SOCK_DGRAM);
         $budget = ['error' => 'pipe-open'];
         if ($BudgetPipe->open()) {
            $BudgetViewer = new LogsViewer(CLI->Terminal->Input, CLI->Terminal->Output);
            $BudgetHandler = new PipeHandler($BudgetPipe);
            $BudgetRecord = new Record(Levels::Info, 'M5.Budget', 'bounded drain');
            $Class = new ReflectionClass(HTTPServerCLIMonitorDrainProbe::class);
            /** @var HTTPServerCLIMonitorDrainProbe $BudgetServer */
            $BudgetServer = $Class->newInstanceWithoutConstructor();
            $BudgetServer->attach($BudgetPipe);

            $sent = 0;
            for ($frame = 0; $frame < 32; $frame++) {
               if ($BudgetHandler->handle($BudgetRecord) === false) {
                  break;
               }
               $sent++;
            }

            $first = $BudgetServer->flush($BudgetViewer);
            $afterFirst = count($BudgetViewer->Records);
            $second = $BudgetServer->flush($BudgetViewer);
            $afterSecond = count($BudgetViewer->Records);
            $third = $BudgetServer->flush($BudgetViewer);
            $BudgetPipe->close();

            $budget = [
               'error' => '',
               'sent' => $sent,
               'first' => $first,
               'after_first' => $afterFirst,
               'second' => $second,
               'after_second' => $afterSecond,
               'third' => $third,
            ];
         }

         return $Response->JSON->send([
            'skip' => false,
            'body_bytes' => strlen($message),
            'control_process' => $controlProcess,
            'control_bytes' => $controlBytes,
            'control_records' => $controlRecords,
            'control_partial' => $controlPartial,
            'processes' => $processes,
            'rounds' => $rounds,
            'final_partial' => $finalPartial,
            'budget' => $budget,
         ]);
      }, POST);
   },

   test: static function (string $response): bool|string {
      $separator = strpos($response, "\r\n\r\n");
      $evidence = $separator === false
         ? null
         : json_decode(substr($response, $separator + 4), true);

      if (! is_array($evidence)) {
         return 'M5 fixture did not return JSON evidence; response_bytes=' . strlen($response)
            . '; response_prefix_base64=' . base64_encode(substr($response, 0, 512));
      }
      if (($evidence['skip'] ?? false) === true) {
         return true;
      }
      if (
         ($evidence['control_process']['exited'] ?? null) !== true
         || ($evidence['control_process']['timed_out'] ?? null) !== false
         || ($evidence['control_bytes'] ?? 0) < 1
         || ($evidence['control_records'] ?? null) !== 1
         || ($evidence['control_partial'] ?? null) !== 0
      ) {
         return 'M5 positive control failed before the large-record probe: '
            . json_encode($evidence);
      }

      $rounds = $evidence['rounds'] ?? null;
      $processes = $evidence['processes'] ?? null;
      if (! is_array($rounds) || count($rounds) !== 8 || ! is_array($processes)) {
         return 'M5 fixture did not execute all large-record rounds: '
            . json_encode($evidence);
      }
      foreach ($processes as $process) {
         if (
            ($process['exited'] ?? null) !== true
            || ($process['timed_out'] ?? null) !== false
         ) {
            return 'M5 production reporter child did not exit cleanly: '
               . json_encode($evidence);
         }
      }
      if (
         ($evidence['budget']['error'] ?? null) !== ''
         || ($evidence['budget']['sent'] ?? null) !== 32
         || ($evidence['budget']['first'] ?? null) !== 16
         || ($evidence['budget']['after_first'] ?? null) !== 16
         || ($evidence['budget']['second'] ?? null) !== 16
         || ($evidence['budget']['after_second'] ?? null) !== 32
         || ($evidence['budget']['third'] ?? null) !== 0
      ) {
         return 'M5 Monitor drain did not yield with readable frames left for the next turn: '
            . json_encode($evidence);
      }

      $previous = 0;
      $growing = true;
      $partialObserved = false;
      $bodyBytes = (int) ($evidence['body_bytes'] ?? 0);
      foreach ($rounds as $round) {
         $partial = (int) ($round['partial'] ?? 0);
         $read = (int) ($round['read'] ?? 0);
         $records = (int) ($round['records'] ?? -1);
         $partialObserved = $partialObserved || $partial > 0;
         if (
            $read < 1
            || $read >= $bodyBytes
            || $partial - $previous !== $read
            || $records !== 1
         ) {
            $growing = false;
         }
         $previous = $partial;
      }

      $finalPartial = (int) ($evidence['final_partial'] ?? 0);
      if ($growing && $finalPartial > 262144) {
         return 'CONFIRMED M5: eight attacker-sized exception records were accepted only in part; '
            . "the LogsViewer used by the Monitor master retained {$finalPartial} unterminated bytes with no byte cap, "
            . 'and every round increased the retained fragment. Evidence: '
            . json_encode($evidence);
      }
      if ($partialObserved || $finalPartial > 0) {
         return 'M5 unsafe behavior retained an incomplete Monitor log frame outside '
            . 'the exact confirmed growth shape: ' . json_encode($evidence);
      }

      foreach ($rounds as $round) {
         if (
            ($round['read'] ?? null) !== 0
            || ($round['partial'] ?? null) !== 0
            || ($round['records'] ?? null) !== 1
         ) {
            return 'M5 oversized record was not dropped as one atomic frame: '
               . json_encode($evidence);
         }
      }

      return true;
   },
);
