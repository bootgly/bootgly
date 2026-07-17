<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


/**
 * H4 PoC/regression — malformed TLS must not leave a closed descriptor in
 * Select or prevent the same worker from serving the next valid TLS client.
 */

return new Specification(
   description: 'Malformed TLS must be removed without poisoning the select loop',

   test: function (): bool|string {
      $Attack = null;

      $Complete = static function (string $response): bool {
         $separator = strpos($response, "\r\n\r\n");
         if ($separator === false) {
            return false;
         }
         if (! preg_match('/\r\nContent-Length:\s*(\d+)\r\n/i', $response, $match)) {
            return false;
         }

         return strlen($response) - ($separator + 4) >= (int) $match[1];
      };

      $Fetch = static function (string $path) use ($Complete): array {
         $TLSContext = stream_context_create([
            'ssl' => [
               'verify_peer' => false,
               'verify_peer_name' => false,
               'allow_self_signed' => true,
               'peer_name' => 'localhost',
               'alpn_protocols' => 'http/1.1',
            ],
         ]);
         $TLSClient = @stream_socket_client(
            'ssl://127.0.0.1:8086',
            $errorNumber,
            $errorMessage,
            2,
            STREAM_CLIENT_CONNECT,
            $TLSContext
         );
         if (! is_resource($TLSClient)) {
            return [
               'response' => '',
               'error' => "{$errorNumber} {$errorMessage}",
            ];
         }

         stream_set_blocking($TLSClient, false);
         @fwrite(
            $TLSClient,
            "GET {$path} HTTP/1.1\r\n"
               . "Host: localhost:8086\r\n"
               . "Connection: close\r\n\r\n"
         );
         $response = '';
         $deadlineNS = (int) hrtime(true) + 1_500_000_000;
         while ((int) hrtime(true) < $deadlineNS) {
            $chunk = @fread($TLSClient, 65536);
            if ($chunk !== false && $chunk !== '') {
               $response .= $chunk;
               if ($Complete($response)) {
                  break;
               }
            }
            usleep(5_000);
         }
         @fclose($TLSClient);

         return [
            'response' => $response,
            'error' => '',
         ];
      };

      $Decode = static function (string $response): null|array {
         $separator = strpos($response, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         $State = json_decode(substr($response, $separator + 4), true);

         return is_array($State) ? $State : null;
      };

      $CPU = static function (int $PID): null|int {
         $stat = @file_get_contents("/proc/{$PID}/stat");
         if ($stat === false) {
            return null;
         }
         $end = strrpos($stat, ') ');
         if ($end === false) {
            return null;
         }
         $fields = preg_split('/\s+/', trim(substr($stat, $end + 2)));
         if (! is_array($fields) || ! isset($fields[11], $fields[12])) {
            return null;
         }

         return (int) $fields[11] + (int) $fields[12];
      };

      $Inspect = static function (array $State): null|string {
         $types = array_merge(
            $State['streams']['reads'] ?? [],
            $State['streams']['writes'] ?? [],
            $State['streams']['excepts'] ?? []
         );
         $invalid = array_values(array_filter(
            $types,
            static fn (mixed $type): bool => $type !== 'resource (stream)'
         ));
         $statuses = $State['connection_statuses'] ?? [];
         $handshaking = $State['connection_handshaking'] ?? [];
         $connectionCount = (int) ($State['connection_count'] ?? -1);
         $readingCount = (int) ($State['reading_count'] ?? -1);
         $IPCount = array_sum($State['ip_connections'] ?? []);

         if ($invalid !== []) {
            return 'event registry retained non-live streams: ' . json_encode($invalid);
         }
         if ((int) ($State['pending_handshakes'] ?? -1) !== 0) {
            return 'pending-handshake count did not return to zero';
         }
         if ($connectionCount !== 1 || $readingCount !== 1 || $IPCount !== 1) {
            return 'live connection counters are unbalanced';
         }
         if ($statuses !== [Connections::STATUS_ESTABLISHED] || $handshaking !== [false]) {
            return 'the recovery connection was not fully established';
         }
         if (count($State['streams']['reads'] ?? []) !== 2) {
            return 'read registry does not contain exactly listener + recovery peer';
         }

         return null;
      };

      try {
         // @ A/B control: capture a healthy worker PID and registry before the
         //   malformed connection reaches the real one-worker TLS server.
         $Baseline = $Fetch('/h4-state');
         $BaselineState = $Decode($Baseline['response']);
         if ($BaselineState === null) {
            return 'H4 control could not inspect the healthy TLS worker: '
               . json_encode($Baseline);
         }
         $baselineIssue = $Inspect($BaselineState);
         if ($baselineIssue !== null) {
            return "H4 control state is invalid: {$baselineIssue}";
         }

         $PID = (int) ($BaselineState['pid'] ?? 0);
         $CPUBefore = $PID > 0 ? $CPU($PID) : null;

         // @ Attack: plaintext is syntactically impossible as a TLS record.
         //   The server-side crypto step must reject and close this peer.
         $Attack = @stream_socket_client(
            'tcp://127.0.0.1:8086',
            $errorNumber,
            $errorMessage,
            2
         );
         if (! is_resource($Attack)) {
            return "Malformed TLS peer could not connect: {$errorNumber} {$errorMessage}";
         }
         stream_set_blocking($Attack, false);
         @fwrite(
            $Attack,
            "GET /h4-poison HTTP/1.1\r\nHost: localhost:8086\r\n\r\n"
         );

         $closed = false;
         $closeDeadlineNS = (int) hrtime(true) + 1_000_000_000;
         while ((int) hrtime(true) < $closeDeadlineNS) {
            @fread($Attack, 1024);
            if (feof($Attack)) {
               $closed = true;
               break;
            }
            usleep(5_000);
         }
         if (! $closed) {
            return 'CONFIRMED: malformed TLS peer was not rejected within one second.';
         }

         // @ A poisoned Select loop spins here; the fixed worker blocks idle.
         usleep(800_000);
         $CPUAfter = $PID > 0 ? $CPU($PID) : null;
         $CPUDelta = is_int($CPUBefore) && is_int($CPUAfter)
            ? $CPUAfter - $CPUBefore
            : null;

         // @ Recovery must use the same process; a refork would conceal a
         //   poisoned/dead worker rather than prove cleanup correctness.
         $Recovery = $Fetch('/h4-state');
         $RecoveryState = $Decode($Recovery['response']);
         if ($RecoveryState === null) {
            return 'CONFIRMED: malformed TLS prevented same-worker recovery. Evidence: '
               . json_encode([
                  'attack_closed' => $closed,
                  'cpu_ticks' => $CPUDelta,
                  'recovery' => $Recovery,
               ]);
         }
         if ((int) ($RecoveryState['pid'] ?? 0) !== $PID) {
            return 'CONFIRMED: recovery was served only after worker replacement. Evidence: '
               . json_encode([
                  'baseline_pid' => $PID,
                  'recovery_pid' => $RecoveryState['pid'] ?? null,
               ]);
         }

         $recoveryIssue = $Inspect($RecoveryState);
         if ($recoveryIssue !== null) {
            return "CONFIRMED: malformed TLS poisoned worker state: {$recoveryIssue}. Evidence: "
               . json_encode($RecoveryState);
         }
         if ($CPUDelta !== null && $CPUDelta >= 50) {
            return "Malformed TLS caused suspicious worker CPU growth ({$CPUDelta} ticks).";
         }

         return true;
      }
      finally {
         if (is_resource($Attack)) {
            @fclose($Attack);
         }
      }
   }
);
