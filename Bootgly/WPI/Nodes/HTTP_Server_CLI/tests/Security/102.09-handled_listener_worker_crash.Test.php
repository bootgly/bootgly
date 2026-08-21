<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M7 — a synchronous Handled-listener failure must not escape the
 * request boundary and terminate the serving worker.
 *
 * Ordinary handler and middleware failures run inside Encoder_'s main catcher.
 * RequestEvents::Handled is emitted later, after Session persistence and file
 * cleanup. Its synchronous, pre-wire Throwable branch explicitly rethrows, and
 * Packages::write()/Select::loop() provide no final per-connection containment.
 * An application listener whose validation, audit or response-decoration logic
 * can throw on attacker-controlled input can therefore turn one request into a
 * worker exit and supervisor refork.
 *
 * This fixture installs a real application Handled listener which throws only
 * for the attacker-selected `/attack` route. A marker written immediately before
 * the throw proves that exact extension point executed in the known healthy PID.
 * The no-throw control exercises the same production encoder and listener first.
 * Secure behavior is one bounded 500 followed by recovery on the same PID; the
 * vulnerable path produces no wire and leaves that PID dead/zombie or replaced.
 */
$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public string $error = '';
   public bool $control = false;
   public int $prePID = 0;
   public bool $marker = false;
   public string $attackWire = '';
   public int $attackBytes = 0;
   public string $attackStatus = '';
   public bool $workerTerminated = false;
   public string $workerState = '';
   public bool $recovery = false;
   public int $postPID = 0;
   public int $recoveryAttempts = 0;
   public bool $cleanup = false;
};

return new Test(
   description: 'A synchronous Handled-listener Throwable must not terminate the worker',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /m7-handled/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use ($Probe): string {
         $token = bin2hex(random_bytes(12));
         $marker = sys_get_temp_dir() . "/bootgly-security-m7-{$token}.hit";

         $Read = static function ($Socket, float $timeout = 2.0): string {
            stream_set_blocking($Socket, false);
            $wire = '';
            $expected = null;
            $deadline = microtime(true) + $timeout;

            while (microtime(true) < $deadline) {
               $read = [$Socket];
               $write = null;
               $except = null;
               $ready = stream_select($read, $write, $except, 0, 25_000);
               if ($ready === false) {
                  break;
               }
               if ($ready === 1) {
                  $chunk = @fread($Socket, 65_535);
                  if ($chunk === false || ($chunk === '' && feof($Socket))) {
                     break;
                  }
                  $wire .= $chunk;
               }

               $separator = strpos($wire, "\r\n\r\n");
               if ($separator !== false && $expected === null) {
                  $head = substr($wire, 0, $separator + 2);
                  if (
                     preg_match(
                        '#\r\nContent-Length:[ \t]*([0-9]+)[ \t]*\r\n#i',
                        $head,
                        $matches,
                     ) === 1
                  ) {
                     $expected = $separator + 4 + (int) $matches[1];
                  }
               }
               if ($expected !== null && strlen($wire) >= $expected) {
                  return substr($wire, 0, $expected);
               }
            }

            return $wire;
         };

         $Send = static function (
            string $path,
            array $headers = [],
            float $timeout = 2.0,
         ) use ($hostPort, $Read, $testIndex): string {
            $Socket = @stream_socket_client(
               "tcp://{$hostPort}",
               $errorNumber,
               $errorMessage,
               timeout: 2,
            );
            if ($Socket === false) {
               return '';
            }
            stream_set_blocking($Socket, true);

            $request = "GET {$path} HTTP/1.1\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n"
               . "Host: localhost\r\n";
            foreach ($headers as $name => $value) {
               $request .= "{$name}: {$value}\r\n";
            }
            $request .= "Connection: close\r\n\r\n";

            $offset = 0;
            while ($offset < strlen($request)) {
               $written = @fwrite($Socket, substr($request, $offset));
               if (! is_int($written) || $written < 1) {
                  fclose($Socket);
                  return '';
               }
               $offset += $written;
            }

            $wire = $Read($Socket, $timeout);
            fclose($Socket);

            return $wire;
         };

         $Decode = static function (string $wire): null|array {
            $separator = strpos($wire, "\r\n\r\n");
            if ($separator === false) {
               return null;
            }

            $decoded = json_decode(substr($wire, $separator + 4), true);

            return is_array($decoded) ? $decoded : null;
         };

         try {
            if (function_exists('posix_kill') === false) {
               throw new RuntimeException('M7 requires the POSIX extension.');
            }

            // Positive control: same production Encoder_, same Handled listener,
            // but no attacker-selected branch and therefore a normal response.
            $controlWire = $Send('/m7-handled/control');
            $control = $Decode($controlWire);
            $controlPID = $control['pid'] ?? null;
            if (
               ! is_array($control)
               || ($control['phase'] ?? null) !== 'control'
               || ! is_int($controlPID)
               || $controlPID < 1
               || str_contains($controlWire, 'HTTP/1.1 200 OK') === false
            ) {
               throw new RuntimeException('M7 no-throw control did not reach a healthy worker.');
            }
            $Probe->control = true;
            $Probe->prePID = $controlPID;

            $Probe->attackWire = $Send(
               '/m7-handled/attack',
               ['X-M7-Token' => $token],
            );
            $Probe->attackBytes = strlen($Probe->attackWire);
            if (
               preg_match('#^HTTP/1\.1 ([0-9]{3})#', $Probe->attackWire, $matches) === 1
            ) {
               $Probe->attackStatus = $matches[1];
            }

            $markerPID = is_file($marker)
               ? trim((string) @file_get_contents($marker))
               : '';
            $Probe->marker = ctype_digit($markerPID)
               && (int) $markerPID === $Probe->prePID;
            if ($Probe->marker === false) {
               throw new RuntimeException(
                  'M7 attack did not reach the synchronous Handled throw marker.'
               );
            }

            $deathDeadline = microtime(true) + 2.0;
            do {
               $status = @file_get_contents('/proc/' . $Probe->prePID . '/status');
               if ($status === false) {
                  $Probe->workerState = 'absent';
                  $Probe->workerTerminated = true;
                  break;
               }
               if (preg_match('/^State:\s+([A-Z])/m', $status, $matches) === 1) {
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

            // Recovery doubles as reachable-state cleanup. On a secure build it
            // restores the original Emitter/Encoder in the same worker. After a
            // crash, the reforked worker inherited the untouched master state.
            $recoveryDeadline = microtime(true) + 3.0;
            do {
               $Probe->recoveryAttempts++;
               $recoveryWire = $Send('/m7-handled/recovery', timeout: 0.75);
               $recovery = $Decode($recoveryWire);
               $recoveryPID = $recovery['pid'] ?? null;
               if (
                  is_array($recovery)
                  && ($recovery['phase'] ?? null) === 'recovery'
                  && is_int($recoveryPID)
                  && $recoveryPID > 0
               ) {
                  $Probe->recovery = true;
                  $Probe->postPID = $recoveryPID;
                  $Probe->cleanup = ($recovery['restored'] ?? false) === true
                     || $recoveryPID !== $Probe->prePID;
                  break;
               }
               usleep(50_000);
            }
            while (microtime(true) < $recoveryDeadline);
         }
         catch (Throwable $Throwable) {
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         finally {
            // If an earlier control failed after setup, make one best-effort
            // restoration request so this case cannot contaminate later cases.
            if ($Probe->recovery === false) {
               $recoveryWire = $Send('/m7-handled/recovery', timeout: 0.75);
               $recovery = $Decode($recoveryWire);
               $recoveryPID = $recovery['pid'] ?? null;
               if (
                  is_array($recovery)
                  && ($recovery['phase'] ?? null) === 'recovery'
                  && is_int($recoveryPID)
                  && $recoveryPID > 0
               ) {
                  $Probe->recovery = true;
                  $Probe->postPID = $recoveryPID;
                  $Probe->cleanup = ($recovery['restored'] ?? false) === true
                     || ($Probe->prePID > 0 && $recoveryPID !== $Probe->prePID);
               }
            }
            @unlink($marker);
         }

         return "GET /m7-handled/harness HTTP/1.1\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe): Generator {
      yield $Router->route('/m7-handled/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;

         Emitter::$Instance = new Emitter;
         Emitter::$Instance->listen(
            RequestEvents::Handled,
            static function (Emission $Emission): void {
               $Request = $Emission->payload[0] ?? null;
               if (
                  $Request instanceof Request === false
                  || $Request->URI !== '/m7-handled/attack'
               ) {
                  return;
               }

               $token = $Request->Header->get('X-M7-Token');
               if (
                  is_string($token) === false
                  || preg_match('/^[a-f0-9]{24}$/D', $token) !== 1
               ) {
                  return;
               }

               $marker = sys_get_temp_dir() . "/bootgly-security-m7-{$token}.hit";
               if (file_put_contents($marker, (string) getmypid()) === false) {
                  throw new RuntimeException('M7-HANDLED-MARKER-WRITE-FAILED');
               }

               throw new RuntimeException('M7-HANDLED-LISTENER-THROW');
            },
         );
         Server::$Encoder = new Encoder_;

         return $Response(body: 'M7-HANDLED-SETUP');
      }, GET);

      yield $Router->route('/m7-handled/control', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'control',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/m7-handled/attack', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->code(202)->JSON->send([
            'phase' => 'attack-handler',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/m7-handled/recovery', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $restored = false;
         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
            $restored = true;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
            $restored = true;
         }

         return $Response->JSON->send([
            'phase' => 'recovery',
            'pid' => getmypid(),
            'restored' => $restored,
         ]);
      }, GET);

      yield $Router->route('/m7-handled/harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'harness',
            'pid' => getmypid(),
         ]);
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'M7-HANDLED-SETUP') === false
      ) {
         return 'M7 Handled-listener setup or native harness response failed.';
      }

      $harnessWire = $responses[1] ?? '';
      $separator = strpos($harnessWire, "\r\n\r\n");
      $harness = $separator === false
         ? null
         : json_decode(substr($harnessWire, $separator + 4), true);
      $harnessPID = is_array($harness) ? ($harness['pid'] ?? null) : null;
      if ($Probe->postPID === 0 && is_int($harnessPID)) {
         $Probe->postPID = $harnessPID;
      }

      if ($Probe->error !== '') {
         Vars::$labels = ['M7 fixture evidence'];
         dump(json_encode($Probe));

         return 'M7 fixture error: ' . $Probe->error;
      }
      if (
         $Probe->control !== true
         || $Probe->prePID < 1
         || $Probe->marker !== true
      ) {
         return 'M7 controls did not prove the healthy worker and exact Handled '
            . 'throw source: ' . json_encode($Probe);
      }

      if (
         $Probe->attackBytes === 0
         && (
            $Probe->workerTerminated
            || ($Probe->postPID > 0 && $Probe->postPID !== $Probe->prePID)
         )
      ) {
         Vars::$labels = ['M7 confirmed evidence'];
         dump(json_encode([
            'probe' => $Probe,
            'harness' => $harness,
         ]));

         $outcome = $Probe->postPID > 0 && $Probe->postPID !== $Probe->prePID
            ? 'was replaced by worker PID ' . $Probe->postPID
            : 'terminated with process state ' . $Probe->workerState;

         return 'CONFIRMED M7: attacker-selected input reached the synchronous '
            . 'Handled listener in worker PID ' . $Probe->prePID
            . ", produced zero response bytes, and {$outcome}. "
            . 'Evidence: ' . json_encode($Probe);
      }

      if (
         $Probe->attackStatus !== '500'
         || $Probe->attackBytes > 65_536
         || substr_count($Probe->attackWire, 'HTTP/1.1 ') !== 1
         || preg_match(
            '#\r\nConnection:[ \t]*close[ \t]*\r\n#i',
            $Probe->attackWire,
         ) !== 1
         || $Probe->workerTerminated
         || $Probe->recovery !== true
         || $Probe->postPID !== $Probe->prePID
         || $Probe->cleanup !== true
         || ! is_array($harness)
         || ($harness['phase'] ?? null) !== 'harness'
         || $harnessPID !== $Probe->prePID
      ) {
         return 'M7 worker did not provide one bounded 500 and same-worker '
            . 'recovery after the Handled Throwable: ' . json_encode([
               'probe' => $Probe,
               'harness' => $harness,
            ]);
      }

      return true;
   },
);
