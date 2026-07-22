<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;
if (! class_exists('HTTPServerCLIAbortiveCloseProbe', false)) {
   class HTTPServerCLIAbortiveCloseProbe
   {
      public string $error = '';
      public bool $markerSeen = false;
      public int $resetConnections = 0;
      public int $lingerConfigured = 0;
      public bool $blockResponse = false;
      public bool $healthResponse = false;
      public bool $workerTerminated = false;
      public string $workerState = '';
      public int $prePID = 0;
      public int $postPID = 0;
      public int $healthAttempts = 0;
   }
}

/**
 * Security PoC C1 — an abortive close queued while the sole worker is busy
 * must not escape Connections::connect() and terminate that worker.
 *
 * The block route writes a readiness marker and holds the single-threaded
 * worker long enough for three completed loopback TCP handshakes to be queued.
 * Each client then enables SO_LINGER(1, 0) and closes, producing a TCP RST
 * before the worker can accept it. The route response records the worker PID.
 * A subsequent health request records the serving PID after the listener has
 * consumed the queued resets.
 *
 * Secure behavior keeps the same worker alive and serves both controls. On the
 * vulnerable implementation, Connection returns partially initialized after
 * stream_socket_get_name() fails; Connections::connect() reads its uninitialized
 * $ip property, the Error escapes the event callback, and the master reforks.
 */
$Probe = new HTTPServerCLIAbortiveCloseProbe;

return new Specification(
   description: 'Abortive TCP closes must not terminate and refork the serving worker',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      $token = bin2hex(random_bytes(8));
      $markerPath = sys_get_temp_dir() . '/bootgly-security-c1-' . $token . '.ready';

      $Read = static function ($Stream, float $timeout = 3.0): string {
         stream_set_blocking($Stream, false);

         $response = '';
         $expected = null;
         $deadline = microtime(true) + $timeout;

         while (microtime(true) < $deadline) {
            $chunk = @fread($Stream, 65535);
            if ($chunk !== false && $chunk !== '') {
               $response .= $chunk;

               $separator = strpos($response, "\r\n\r\n");
               if ($separator !== false && $expected === null) {
                  $head = substr($response, 0, $separator + 2);
                  if (preg_match('#\r\nContent-Length: ([0-9]+)\r\n#i', $head, $matches) === 1) {
                     $expected = $separator + 4 + (int) $matches[1];
                  }
               }

               if ($expected !== null && strlen($response) >= $expected) {
                  return substr($response, 0, $expected);
               }
            }

            if (@feof($Stream)) {
               break;
            }

            usleep(5_000);
         }

         return $response;
      };

      $Decode = static function (string $wire): null|array {
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         $decoded = json_decode(substr($wire, $separator + 4), true);

         return is_array($decoded) ? $decoded : null;
      };

      $Block = null;

      try {
         if (
            function_exists('socket_import_stream') === false
            || function_exists('socket_set_option') === false
            || function_exists('posix_kill') === false
         ) {
            throw new RuntimeException('C1 requires the sockets and POSIX extensions.');
         }

         $Block = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 5,
         );
         if (! is_resource($Block)) {
            throw new RuntimeException(
               "Could not open the C1 blocking control: {$errorNumber} {$errorMessage}"
            );
         }
         stream_set_blocking($Block, true);

         $blockRequest = "GET /c1/block HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "X-C1-Token: {$token}\r\n"
            . "Host: localhost\r\n"
            . "Connection: close\r\n\r\n";
         if (@fwrite($Block, $blockRequest) !== strlen($blockRequest)) {
            throw new RuntimeException('Could not write the complete C1 blocking control.');
         }

         $markerDeadline = microtime(true) + 2.0;
         while (! is_file($markerPath) && microtime(true) < $markerDeadline) {
            usleep(5_000);
         }
         $Probe->markerSeen = is_file($markerPath);
         if ($Probe->markerSeen !== true) {
            throw new RuntimeException('The C1 worker-block marker was not created.');
         }

         for ($index = 0; $index < 3; $index++) {
            $Reset = @stream_socket_client(
               "tcp://{$hostPort}",
               $errorNumber,
               $errorMessage,
               timeout: 2,
            );
            if (! is_resource($Reset)) {
               throw new RuntimeException(
                  "Could not open C1 reset connection #{$index}: {$errorNumber} {$errorMessage}"
               );
            }
            $Probe->resetConnections++;

            $RSTSocket = @socket_import_stream($Reset);
            if (! $RSTSocket instanceof Socket) {
               fclose($Reset);
               throw new RuntimeException("Could not import C1 reset connection #{$index}.");
            }
            if (
               @socket_set_option(
                  $RSTSocket,
                  SOL_SOCKET,
                  SO_LINGER,
                  ['l_onoff' => 1, 'l_linger' => 0],
               ) !== true
            ) {
               fclose($Reset);
               throw new RuntimeException("Could not configure SO_LINGER for C1 reset #{$index}.");
            }
            $Probe->lingerConfigured++;
            fclose($Reset);
         }

         $blockWire = $Read($Block, 4.0);
         $block = $Decode($blockWire);
         $Probe->blockResponse = is_array($block)
            && ($block['phase'] ?? null) === 'block';
         $blockPID = $block['pid'] ?? null;
         $Probe->prePID = is_int($blockPID) ? $blockPID : 0;
         fclose($Block);
         $Block = null;

         // @ The suite master is currently inside its nested client loop, so a
         //   crashed worker is reaped immediately but refork is deferred until
         //   that loop unwinds. Poll the kernel identity first; on secure code
         //   the original worker remains alive and serves the health control.
         $deathDeadline = microtime(true) + 2.0;
         do {
            $processStatus = @file_get_contents('/proc/' . $Probe->prePID . '/status');
            if ($processStatus === false) {
               $Probe->workerState = 'absent';
               $Probe->workerTerminated = true;
               break;
            }
            if (preg_match('/^State:\s+([A-Z])/m', $processStatus, $matches) === 1) {
               $Probe->workerState = $matches[1];
               if ($matches[1] === 'Z' || $matches[1] === 'X') {
                  $Probe->workerTerminated = true;
                  break;
               }
            }
            if (@posix_kill($Probe->prePID, 0) === false) {
               $Probe->workerState = 'unreachable';
               $Probe->workerTerminated = true;
               break;
            }

            usleep(10_000);
         }
         while (microtime(true) < $deathDeadline);

         if ($Probe->workerTerminated === false) {
            $healthDeadline = microtime(true) + 4.0;
            do {
               $Probe->healthAttempts++;

               $Health = @stream_socket_client(
                  "tcp://{$hostPort}",
                  $errorNumber,
                  $errorMessage,
                  timeout: 1,
               );
               if (! is_resource($Health)) {
                  usleep(50_000);
                  continue;
               }
               stream_set_blocking($Health, true);

               $healthRequest = "GET /c1/health HTTP/1.1\r\n"
                  . "X-Bootgly-Test: {$testIndex}\r\n"
                  . "Host: localhost\r\n"
                  . "Connection: close\r\n\r\n";
               if (@fwrite($Health, $healthRequest) !== strlen($healthRequest)) {
                  fclose($Health);
                  usleep(50_000);
                  continue;
               }

               $healthWire = $Read($Health, 1.0);
               fclose($Health);

               $health = $Decode($healthWire);
               $healthPID = $health['pid'] ?? null;
               if (
                  is_array($health)
                  && ($health['phase'] ?? null) === 'health'
                  && is_int($healthPID)
                  && $healthPID > 0
               ) {
                  $Probe->healthResponse = true;
                  $Probe->postPID = $healthPID;
                  break;
               }

               usleep(50_000);
            }
            while (microtime(true) < $healthDeadline);
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         if (is_resource($Block)) {
            fclose($Block);
         }
         @unlink($markerPath);
      }

      return "GET /c1/harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/c1/block', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $token = $Request->Header->get('X-C1-Token') ?? '';
         if (preg_match('/^[a-f0-9]{16}$/D', $token) !== 1) {
            return $Response->code(400)->JSON->send([
               'phase' => 'block',
               'error' => 'invalid token',
            ]);
         }

         $markerPath = sys_get_temp_dir() . '/bootgly-security-c1-' . $token . '.ready';
         if (file_put_contents($markerPath, (string) getmypid()) === false) {
            return $Response->code(500)->JSON->send([
               'phase' => 'block',
               'error' => 'marker write failed',
            ]);
         }

         usleep(750_000);

         return $Response->JSON->send([
            'phase' => 'block',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/c1/health', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'health',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/c1/harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'harness',
            'pid' => getmypid(),
         ]);
      }, GET);
   },

   test: static function (string $response) use ($Probe): bool|string {
      $separator = strpos($response, "\r\n\r\n");
      $harness = $separator === false
         ? null
         : json_decode(substr($response, $separator + 4), true);

      if ($Probe->error !== '') {
         Vars::$labels = ['C1 fixture evidence'];
         dump(json_encode($Probe));

         return 'C1 fixture error: ' . $Probe->error;
      }
      if (
         $Probe->markerSeen !== true
         || $Probe->resetConnections !== 3
         || $Probe->lingerConfigured !== 3
         || $Probe->blockResponse !== true
         || $Probe->prePID <= 0
      ) {
         Vars::$labels = ['C1 source and control evidence'];
         dump(json_encode($Probe));

         return 'C1 fixture did not prove the blocked worker, queued SO_LINGER resets, and post-probe health control: '
            . json_encode($Probe);
      }

      if ($Probe->workerTerminated === true) {
         Vars::$labels = ['C1 confirmed evidence'];
         dump(json_encode($Probe));

         return 'CONFIRMED C1: queued SO_LINGER resets terminated the serving worker PID '
            . $Probe->prePID
            . ' after its normal blocking-route control completed; the kernel PID identity no longer existed.';
      }

      if (
         $Probe->healthResponse !== true
         || $Probe->postPID <= 0
      ) {
         Vars::$labels = ['C1 post-probe health evidence'];
         dump(json_encode($Probe));

         return 'C1 worker remained present but did not complete the post-reset health control: '
            . json_encode($Probe);
      }
      $harnessPID = is_array($harness) ? ($harness['pid'] ?? null) : null;
      if (
         ! is_array($harness)
         || ($harness['phase'] ?? null) !== 'harness'
         || ! is_int($harnessPID)
         || $harnessPID !== $Probe->postPID
      ) {
         Vars::$labels = ['C1 harness evidence'];
         dump(json_encode([
            'probe' => $Probe,
            'harness' => $harness,
         ]));

         return 'C1 native harness did not complete on the post-probe serving worker.';
      }

      if ($Probe->prePID !== $Probe->postPID) {
         Vars::$labels = ['C1 confirmed evidence'];
         dump(json_encode($Probe));

         return 'CONFIRMED C1: three abortive TCP closes terminated worker PID '
            . $Probe->prePID . '; a replacement PID ' . $Probe->postPID
            . ' before the health and native harness controls completed.';
      }

      return true;
   },
);
