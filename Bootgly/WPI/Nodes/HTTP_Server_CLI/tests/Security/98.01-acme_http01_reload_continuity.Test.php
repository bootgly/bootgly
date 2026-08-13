<?php

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\CertificateSnapshot;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Swaps;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC L1 (HTTP_Server_CLI audit 2026-08-02) — a daemon reload must
 * keep answering HTTP-01 while its application workers drain and re-exec.
 *
 * In the vulnerable implementation, the HTTP override terminated and reaped
 * the only HTTP-01 helper before the base reload waited for application workers
 * to drain. The bound Gate survived through the production SCM_RIGHTS relay,
 * so TCP connections entered its backlog, but no process answered them until
 * the fresh image forked a replacement helper.
 *
 * This test starts a real daemon-mode AutoTLS server in a fresh PHP session,
 * completes one TLS keep-alive response and leaves a second request head
 * incomplete. That proves the sole worker owns an in-flight connection and
 * keeps the genuine graceful-drain path open. During that window the test
 * sends a complete HTTP-01 request through the real validation Gate.
 *
 * Controls:
 * - exact 200/key-authorization before reload;
 * - a completed TLS application response before the drain blocker is armed;
 * - the old worker remains alive after reload enters;
 * - on the vulnerable baseline, the same queued challenge connection completes
 *   after the blocker closes;
 * - stable master PID, replacement worker generation, ready helper, and exact
 *   200/key-authorization after reload;
 * - the old image's AutoTLS lease is released across pcntl_exec, allowing a
 *   later production sweep to reclaim it while preserving the fresh image;
 * - orderly teardown and native Security harness routing.
 *
 * The secure assertion is uninterrupted HTTP-01 response service. A mere TCP
 * connect is not sufficient: the listener is intentionally preserved.
 */
$probe = [
   'error' => '',
   'result' => [],
];

// ! Run the lifecycle source-to-sink path outside the Security suite's shared
//   test-mode server. The same executable fixture is replayed by pcntl_exec,
//   which is essential to exercising the genuine reload implementation.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--l1-http01-reload-probe'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $storage = rtrim((string) ($_SERVER['argv'][3] ?? ''), '/');
   $mainPort = (int) ($_SERVER['argv'][4] ?? 0);
   $gatePort = (int) ($_SERVER['argv'][5] ?? 0);
   $token = (string) ($_SERVER['argv'][6] ?? '');
   $authorization = (string) ($_SERVER['argv'][7] ?? '');
   $journal = (string) ($_SERVER['argv'][8] ?? '');
   $storagePrefix = rtrim(sys_get_temp_dir(), '/') . '/bootgly-l1-http01-';

   if (
      $root === ''
      || realpath("{$root}/autoboot.php") === false
      || $storage === ''
      || str_starts_with($storage, $storagePrefix) === false
      || realpath($storage) !== $storage
      || $mainPort < 1024 || $mainPort > 65535
      || $gatePort < 1024 || $gatePort > 65535
      || $mainPort === $gatePort
      || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $token) !== 1
      || $authorization === '' || strlen($authorization) > 8192
      || dirname($journal) !== $storage
   ) {
      fwrite(STDERR, "L1 fixture received invalid launch arguments.\n");
      exit(2);
   }

   define('BOOTGLY_STORAGE_BASE', $storage);
   define('BOOTGLY_L1_RELOAD_JOURNAL', $journal);

   // This is an embedded fixture, not a Bootgly command invocation. Preserve
   // argv: the production reload replays it to rebuild this exact server.
   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";
   Display::show(Display::NONE);

   final class L1ReloadServer extends HTTP_Server_CLI
   {
      protected function reload (): void
      {
         @file_put_contents(
            BOOTGLY_L1_RELOAD_JOURNAL,
            posix_getpid() . ':' . sprintf('%.6F', microtime(true)) . "\n",
            FILE_APPEND | LOCK_EX
         );

         parent::reload();
      }

      /** Disable only outbound issuance; helper/reload lifecycle stays real. */
      protected function tick (): void
      {
         $this->checked = PHP_INT_MAX;
         parent::tick();
      }
   }

   // Keep a failed fixture bounded. The PoC releases its accepted connection
   // immediately after the timed attack leg; this limit is only a safety net.
   L1ReloadServer::$drainTimeout = 4;

   $AutoTLS = new AutoTLS(
      domains: ['localhost'],
      email: 'l1-reload@bootgly.test',
      path: "{$storage}/autotls/",
      challenges: "{$storage}/challenges/",
      port: $gatePort,
      options: ['verify_peer' => false]
   );
   $Server = new L1ReloadServer;
   $Server->configure(
      host: '127.0.0.1',
      port: $mainPort,
      workers: 1,
      secure: $AutoTLS,
      enableHTTP2: false
   );
   if (Challenges::save($token, $authorization, $AutoTLS->challenges) === false) {
      exit(3);
   }
   $Server->on(
      Events::RequestReceived,
      static function (Request $Request, Response $Response): Response {
         return $Response->send('L1-APP-CONTROL');
      }
   );
   $Server->start();
   exit(0);
}

return new Test(
   description: 'ACME HTTP-01 responses must remain available throughout daemon reload',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $storage = rtrim(sys_get_temp_dir(), '/')
         . '/bootgly-l1-http01-' . getmypid() . '-' . bin2hex(random_bytes(5));
      $journal = "{$storage}/reload.journal";
      $state = '';
      $mainPort = 0;
      $gatePort = 0;
      $masterPID = 0;
      $helperPID = 0;
      $workerPIDs = [];
      /** @var array<int,array{parent:int,start:string}> $observedIdentities */
      $observedIdentities = [];
      $Hold = false;
      $DuringSocket = false;

      $result = [
         'launcher' => false,
         'expected_authorization' => '',
         'initial_topology' => false,
         'initial_swap_namespaces' => [],
         'pre_challenge' => [],
         'application_control' => [],
         'reload_signaled' => false,
         'reload_entered' => false,
         'old_helper_died' => false,
         'old_worker_live' => false,
         'old_worker_live_after_deadline' => false,
         'during' => [],
         'queued_after_release' => [],
         'reloaded_topology' => false,
         'old_helper_retired' => false,
         'post_challenge' => [],
         'swap_reexec' => [],
         'stopped' => false,
      ];

      $Reserve = static function (): int {
         $Listener = @stream_socket_server('tcp://127.0.0.1:0', $code, $message);
         if ($Listener === false) {
            return 0;
         }
         $address = stream_socket_get_name($Listener, false);
         fclose($Listener);
         $separator = is_string($address) ? strrpos($address, ':') : false;

         return $separator === false ? 0 : (int) substr($address, $separator + 1);
      };
      $Wait = static function (Closure $Condition, float $seconds = 8.0): bool {
         $deadline = microtime(true) + $seconds;
         do {
            if ($Condition()) {
               return true;
            }
            usleep(25000);
         } while (microtime(true) < $deadline);

         return false;
      };
      $Alive = static function (int $PID, null|int $parentPID = null): bool {
         if ($PID < 2) {
            return false;
         }
         $status = @file_get_contents("/proc/{$PID}/status");
         if (is_string($status) === false || preg_match('/^State:\s+Z/m', $status) === 1) {
            return false;
         }
         if ($parentPID === null) {
            return true;
         }

         return preg_match('/^PPid:\t(\d+)$/m', $status, $matches) === 1
            && (int) $matches[1] === $parentPID;
      };
      /** @return null|array{parent:int,start:string} */
      $Identify = static function (int $PID): null|array {
         if ($PID < 2) {
            return null;
         }
         $stat = @file_get_contents("/proc/{$PID}/stat");
         $separator = is_string($stat) ? strrpos($stat, ') ') : false;
         if ($separator === false) {
            return null;
         }
         // Fields after comm begin at #3 (state). PPID is #4 and starttime #22.
         $fields = preg_split('/\s+/', trim(substr($stat, $separator + 2)));
         if (
            is_array($fields) === false
            || preg_match('/^[0-9]+$/D', (string) ($fields[1] ?? '')) !== 1
            || preg_match('/^[0-9]+$/D', (string) ($fields[19] ?? '')) !== 1
         ) {
            return null;
         }

         return [
            'parent' => (int) $fields[1],
            'start' => (string) $fields[19],
         ];
      };
      $Observe = static function (int $PID) use (&$observedIdentities, $Identify): bool {
         if (isset($observedIdentities[$PID])) {
            return $Identify($PID) === $observedIdentities[$PID];
         }
         $identity = $Identify($PID);
         if ($identity === null) {
            return false;
         }
         $observedIdentities[$PID] = $identity;

         return true;
      };
      $Own = static function (int $PID) use (&$observedIdentities, $Identify): bool {
         return isset($observedIdentities[$PID])
            && $Identify($PID) === $observedIdentities[$PID];
      };
      $Gone = static function (int $PID) use ($Identify): bool {
         for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($Identify($PID) !== null || @posix_kill($PID, 0)) {
               return false;
            }
            usleep(10000);
         }

         return true;
      };
      $ReadState = static function (string $file): null|array {
         $JSON = @file_get_contents($file);
         $decoded = is_string($JSON) ? json_decode($JSON, true) : null;

         return is_array($decoded) ? $decoded : null;
      };
      $Parse = static function (string $wire): array {
         $separator = strpos($wire, "\r\n\r\n");
         $status = 0;
         $length = null;
         if (preg_match('/^HTTP\/1\.[01] ([0-9]{3})/D', $wire, $matches) === 1) {
            $status = (int) $matches[1];
         }
         if (
            $separator !== false
            && preg_match('/\r\nContent-Length:[ \t]*([0-9]+)[ \t]*(?=\r\n)/i', substr($wire, 0, $separator + 2), $matches) === 1
         ) {
            $length = (int) $matches[1];
         }
         $body = $separator === false ? '' : substr($wire, $separator + 4);
         $complete = $separator !== false
            && $length !== null
            && strlen($body) >= $length;

         return [
            'complete' => $complete,
            'status' => $status,
            'length' => $length,
            'body' => $complete ? substr($body, 0, $length) : $body,
            'wire_bytes' => strlen($wire),
         ];
      };
      $Read = static function ($Socket, float $seconds, string $wire = '') use ($Parse): array {
         stream_set_blocking($Socket, false);
         $deadline = microtime(true) + $seconds;
         do {
            $parsed = $Parse($wire);
            if ($parsed['complete']) {
               return $parsed + ['wire' => $wire, 'timed_out' => false];
            }
            if (feof($Socket)) {
               return $parsed + ['wire' => $wire, 'timed_out' => false];
            }

            $remaining = max(0.0, min(0.1, $deadline - microtime(true)));
            $secondsPart = (int) $remaining;
            $microseconds = (int) (($remaining - $secondsPart) * 1_000_000);
            $read = [$Socket];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, $secondsPart, $microseconds);
            if ($selected === 1) {
               $chunk = @fread($Socket, 8192);
               if (is_string($chunk) && $chunk !== '') {
                  $wire .= $chunk;
               }
            }
         } while (microtime(true) < $deadline);

         return $Parse($wire) + ['wire' => $wire, 'timed_out' => true];
      };
      $Write = static function ($Socket, string $bytes): bool {
         stream_set_blocking($Socket, true);
         stream_set_timeout($Socket, 2);
         $offset = 0;
         $length = strlen($bytes);
         while ($offset < $length) {
            $written = @fwrite($Socket, substr($bytes, $offset));
            if ($written === false || $written === 0) {
               stream_set_blocking($Socket, false);
               return false;
            }
            $offset += $written;
         }
         stream_set_blocking($Socket, false);

         return true;
      };
      $Open = static function (string $scheme, int $port, mixed $Context = null): mixed {
         $URL = "{$scheme}://127.0.0.1:{$port}";
         if ($Context === null) {
            return @stream_socket_client($URL, $code, $message, 2.0, STREAM_CLIENT_CONNECT);
         }

         return @stream_socket_client(
            $URL,
            $code,
            $message,
            2.0,
            STREAM_CLIENT_CONNECT,
            $Context
         );
      };
      $Exact = static function (array $response, int $status, string $body): bool {
         return ($response['complete'] ?? false) === true
            && ($response['status'] ?? 0) === $status
            && ($response['body'] ?? null) === $body;
      };
      $Summary = static function (array $response): array {
         return [
            'connected' => $response['connected'] ?? false,
            'written' => $response['written'] ?? false,
            'complete' => $response['complete'] ?? false,
            'status' => $response['status'] ?? 0,
            'body' => $response['body'] ?? '',
            'wire_bytes' => $response['wire_bytes'] ?? 0,
            'timed_out' => $response['timed_out'] ?? false,
         ];
      };
      $Scan = static function (string $base): array {
         $names = @scandir($base);
         if (is_array($names) === false) {
            return [];
         }

         return array_values(array_filter(
            $names,
            static fn (string $name): bool => preg_match('/^[a-f0-9]{16}$/D', $name) === 1
               && is_link("{$base}{$name}") === false
               && is_dir("{$base}{$name}")
         ));
      };

      try {
         if (
            PHP_OS_FAMILY !== 'Linux'
            || ! function_exists('pcntl_exec')
            || ! function_exists('posix_kill')
            || ! extension_loaded('openssl')
            || ! extension_loaded('sockets')
         ) {
            throw new RuntimeException('required Linux pcntl/posix/OpenSSL/sockets capabilities are unavailable');
         }
         if (mkdir($storage, 0700, true) === false) {
            throw new RuntimeException('could not create isolated storage');
         }

         $mainPort = $Reserve();
         do {
            $gatePort = $Reserve();
         } while ($gatePort === $mainPort && $gatePort !== 0);
         if ($mainPort < 1024 || $gatePort < 1024) {
            throw new RuntimeException('could not reserve two unprivileged loopback ports');
         }
         $state = "{$storage}/pids/L1ReloadServer.{$mainPort}.json";
         $token = 'L1-' . bin2hex(random_bytes(12));
         $authorization = "{$token}.bootgly-proof";
         $result['expected_authorization'] = $authorization;
         $stdout = "{$storage}/launcher.stdout";
         $stderr = "{$storage}/launcher.stderr";

         $Launcher = proc_open(
            [
               PHP_BINARY,
               __FILE__,
               '--l1-http01-reload-probe',
               BOOTGLY_ROOT_BASE,
               $storage,
               (string) $mainPort,
               (string) $gatePort,
               $token,
               $authorization,
               $journal,
            ],
            [
               ['file', '/dev/null', 'r'],
               ['file', $stdout, 'a'],
               ['file', $stderr, 'a'],
            ],
            $Pipes,
            BOOTGLY_ROOT_BASE
         );
         if (is_resource($Launcher) === false) {
            throw new RuntimeException('could not launch daemon fixture');
         }
         $launcherStatus = proc_close($Launcher);
         $result['launcher'] = $launcherStatus === 0;
         if ($result['launcher'] === false) {
            $detail = trim((string) @file_get_contents($stderr));
            throw new RuntimeException("daemon launcher exited {$launcherStatus}: {$detail}");
         }

         $result['initial_topology'] = $Wait(function () use (
            $ReadState,
            $Alive,
            $state,
            &$masterPID,
            &$helperPID,
            &$workerPIDs,
            $Observe
         ): bool {
            $topology = $ReadState($state);
            $masterPID = is_int($topology['master'] ?? null) ? $topology['master'] : 0;
            $workerPIDs = is_array($topology['workers'] ?? null)
               ? array_values(array_filter($topology['workers'], 'is_int'))
               : [];
            $helperPID = is_int($topology['AutoTLS']['helper'] ?? null)
               ? $topology['AutoTLS']['helper']
               : 0;
            $identified = $Observe($masterPID) && $Observe($helperPID);
            foreach ($workerPIDs as $PID) {
               $identified = $Observe($PID) && $identified;
            }

            return $identified
               && $masterPID > 1
               && count($workerPIDs) === 1
               && $Alive($workerPIDs[0], $masterPID)
               && $helperPID > 1
               && $Alive($helperPID, $masterPID)
               && ($topology['AutoTLS']['ready'] ?? false) === true;
         }, 12.0);
         if ($result['initial_topology'] === false) {
            throw new RuntimeException('daemon did not publish a ready worker/helper topology');
         }
         $swapBase = "{$storage}/autotls/swaps/";
         $result['initial_swap_namespaces'] = $Scan($swapBase);
         if (count($result['initial_swap_namespaces']) !== 1) {
            throw new RuntimeException('initial image did not own exactly one isolated swap namespace');
         }

         $challengeRequest = "GET /.well-known/acme-challenge/{$token} HTTP/1.1\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n";

         $PreSocket = $Open('tcp', $gatePort);
         $pre = ['connected' => is_resource($PreSocket), 'written' => false];
         if (is_resource($PreSocket)) {
            $pre['written'] = $Write($PreSocket, $challengeRequest);
            $pre += $Read($PreSocket, 3.0);
            fclose($PreSocket);
         }
         $result['pre_challenge'] = $Summary($pre);
         if ($Exact($pre, 200, $authorization) === false) {
            throw new RuntimeException('pre-reload challenge control did not return the exact authorization');
         }

         $Context = stream_context_create([
            'ssl' => [
               'verify_peer' => false,
               'verify_peer_name' => false,
               'allow_self_signed' => true,
               'peer_name' => 'localhost',
            ],
         ]);
         $Hold = $Open('tls', $mainPort, $Context);
         $application = ['connected' => is_resource($Hold), 'written' => false];
         if (is_resource($Hold)) {
            $application['written'] = $Write(
               $Hold,
               "GET /control HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n"
            );
            $application += $Read($Hold, 3.0);
         }
         $result['application_control'] = $Summary($application);
         if ($Exact($application, 200, 'L1-APP-CONTROL') === false) {
            throw new RuntimeException('TLS application keep-alive control did not complete');
         }
         if (
            $Write(
               $Hold,
               "GET /hold HTTP/1.1\r\nHost: localhost\r\nX-L1-Hold: accepted\r\n"
            ) === false
         ) {
            throw new RuntimeException('could not arm the incomplete-head drain blocker');
         }
         usleep(250000);

         $result['reload_signaled'] = $Own($masterPID)
            && posix_kill($masterPID, SIGUSR2);
         $result['reload_entered'] = $result['reload_signaled']
            && $Wait(static fn (): bool => is_file($journal) && filesize($journal) > 0, 3.0);
         if ($result['reload_entered'] === false) {
            throw new RuntimeException('SIGUSR2 did not enter the production reload override');
         }

         $result['old_helper_died'] = $Wait(
            static fn (): bool => $Alive($helperPID) === false,
            2.0
         );
         $result['old_worker_live'] = $Alive($workerPIDs[0], $masterPID);
         if ($result['old_worker_live'] === false) {
            throw new RuntimeException('the accepted TLS blocker did not keep the old worker in graceful drain');
         }

         $DuringSocket = $Open('tcp', $gatePort);
         $during = ['connected' => is_resource($DuringSocket), 'written' => false];
         if (is_resource($DuringSocket)) {
            $during['written'] = $Write($DuringSocket, $challengeRequest);
            $during += $Read($DuringSocket, 1.25);
         }
         $result['during'] = $Summary($during);
         $result['old_worker_live_after_deadline'] = $Own($workerPIDs[0])
            && $Alive($workerPIDs[0], $masterPID);
         if ($result['old_worker_live_after_deadline'] === false) {
            throw new RuntimeException('the old worker did not remain in graceful drain through the attack deadline');
         }

         // Release the genuine worker drain only after the attack deadline.
         if (is_resource($Hold)) {
            fclose($Hold);
            $Hold = false;
         }

         if (is_resource($DuringSocket)) {
            if ($Exact($during, 200, $authorization)) {
               $queued = $during;
            }
            else {
               $queued = $Read($DuringSocket, 12.0, (string) ($during['wire'] ?? ''));
            }
            $result['queued_after_release'] = $Summary($queued);
            fclose($DuringSocket);
            $DuringSocket = false;
         }

         $newWorkerPIDs = [];
         $newHelperPID = 0;
         $result['reloaded_topology'] = $Wait(function () use (
            $ReadState,
            $Alive,
            $state,
            $masterPID,
            $workerPIDs,
            $helperPID,
            &$newWorkerPIDs,
            &$newHelperPID,
            $Observe,
            $Gone
         ): bool {
            $topology = $ReadState($state);
            $newWorkerPIDs = is_array($topology['workers'] ?? null)
               ? array_values(array_filter($topology['workers'], 'is_int'))
               : [];
            $newHelperPID = is_int($topology['AutoTLS']['helper'] ?? null)
               ? $topology['AutoTLS']['helper']
               : 0;
            $identified = $Observe($newHelperPID);
            foreach ($newWorkerPIDs as $PID) {
               $identified = $Observe($PID) && $identified;
            }
            if (
               $identified === false
               || ($topology['master'] ?? null) !== $masterPID
               || count($newWorkerPIDs) !== 1
               || array_intersect($workerPIDs, $newWorkerPIDs) !== []
               || ($topology['AutoTLS']['ready'] ?? false) !== true
               || $newHelperPID < 2
               || $newHelperPID === $helperPID
               || $Alive($newHelperPID, $masterPID) === false
            ) {
               return false;
            }

            return $Alive($newWorkerPIDs[0], $masterPID)
               && $Gone($helperPID);
         }, 12.0);
         $result['old_helper_retired'] = $Gone($helperPID);
         if ($result['reloaded_topology'] === false) {
            throw new RuntimeException('fresh image did not publish replacement worker/helper topology');
         }

         $PostSocket = $Open('tcp', $gatePort);
         $post = ['connected' => is_resource($PostSocket), 'written' => false];
         if (is_resource($PostSocket)) {
            $post['written'] = $Write($PostSocket, $challengeRequest);
            $post += $Read($PostSocket, 3.0);
            fclose($PostSocket);
         }
         $result['post_challenge'] = $Summary($post);
         if ($Exact($post, 200, $authorization) === false) {
            throw new RuntimeException('post-reload challenge control did not return the exact authorization');
         }

         // The old master explicitly closes its lease before pcntl_exec. The
         // relay retains it across the handoff, then exits. A fresh independent
         // publication must therefore collect the old image while the new
         // image's still-locked namespace remains byte-for-byte addressable.
         $beforeProbe = $Scan($swapBase);
         $oldNamespaces = $result['initial_swap_namespaces'];
         $liveNamespaces = array_values(array_diff($beforeProbe, $oldNamespaces));
         $productionBounded = count($liveNamespaces) === 1
            && array_intersect($oldNamespaces, $beforeProbe) === []
            && count($beforeProbe) === 1;
         $probeInstance = bin2hex(random_bytes(8));
         $SwapProbe = new Swaps($swapBase, $probeInstance);
         $Snapshot = new CertificateSnapshot(
            str_repeat('1', 32),
            '/tmp/l2-reexec-certificate.pem',
            '/tmp/l2-reexec-key.pem',
            str_repeat('2', 64),
            str_repeat('3', 64),
            time() - 1,
            time() + 3600,
            false,
            ['localhost']
         );
         $probeAttempt = $SwapProbe->request($Snapshot);
         $SwapProbe->sweep();
         $afterProbe = $Scan($swapBase);
         $SwapProbe->release();
         $result['swap_reexec'] = [
            'before_probe' => $beforeProbe,
            'old' => $oldNamespaces,
            'live' => $liveNamespaces,
            'probe' => $probeInstance,
            'after_probe' => $afterProbe,
            'published' => is_string($probeAttempt),
            'production_bounded' => $productionBounded,
            'bounded' => $productionBounded
               && is_string($probeAttempt)
               && count($liveNamespaces) === 1
               && array_intersect($oldNamespaces, $afterProbe) === []
               && array_diff($liveNamespaces, $afterProbe) === []
               && in_array($probeInstance, $afterProbe, true)
               && count($afterProbe) === 2,
         ];
         if ($result['swap_reexec']['bounded'] === false) {
            throw new RuntimeException('old AutoTLS namespace lease survived reexec or the live namespace was reclaimed');
         }

         if ($Own($masterPID) === false || posix_kill($masterPID, SIGTERM) === false) {
            throw new RuntimeException('the fixture master identity changed before orderly stop');
         }
         $result['stopped'] = $Wait(
            static fn (): bool => $Own($masterPID) === false && $ReadState($state) === null,
            10.0
         );
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         if (is_resource($Hold)) {
            fclose($Hold);
         }
         if (is_resource($DuringSocket)) {
            fclose($DuringSocket);
         }

         if ($masterPID > 1 && $Own($masterPID)) {
            posix_kill($masterPID, SIGTERM);
            $Wait(static fn (): bool => $Own($masterPID) === false, 3.0);
         }
         if ($masterPID > 1 && $Own($masterPID)) {
            posix_kill($masterPID, SIGKILL);
         }
         foreach (array_keys($observedIdentities) as $PID) {
            if ($PID !== $masterPID && $Own($PID)) {
               posix_kill($PID, SIGKILL);
            }
         }

         if (is_dir($storage) && str_starts_with($storage, rtrim(sys_get_temp_dir(), '/') . '/bootgly-l1-http01-')) {
            $Iterator = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS),
               RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($Iterator as $Entry) {
               $Entry->isLink() || ! $Entry->isDir()
                  ? @unlink($Entry->getPathname())
                  : @rmdir($Entry->getPathname());
            }
            @rmdir($storage);
         }
      }

      $probe['result'] = $result;

      return "GET /l1-http01-reload-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route(
         '/l1-http01-reload-harness',
         static function (Request $Request, Response $Response): Response {
            return $Response(body: 'L1-HARNESS-OK');
         },
         GET
      );

      yield $Router->route('/*', static function (Request $Request, Response $Response): Response {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'L1-HARNESS-OK')) {
         return 'L1 fixture failed: native Security harness route was not selected.';
      }
      if ($probe['error'] !== '') {
         return 'L1 fixture failed: ' . $probe['error']
            . '; evidence=' . json_encode($probe['result'], JSON_UNESCAPED_SLASHES);
      }

      $result = $probe['result'];
      $authorization = (string) ($result['expected_authorization'] ?? '');
      $required = ($result['launcher'] ?? false)
         && ($result['initial_topology'] ?? false)
         && ($result['reload_signaled'] ?? false)
         && ($result['reload_entered'] ?? false)
         && ($result['old_worker_live'] ?? false)
         && ($result['old_worker_live_after_deadline'] ?? false)
         && ($result['reloaded_topology'] ?? false)
         && ($result['old_helper_retired'] ?? false)
         && ($result['swap_reexec']['bounded'] ?? false)
         && ($result['stopped'] ?? false)
         && ($result['pre_challenge']['complete'] ?? false)
         && ($result['pre_challenge']['status'] ?? 0) === 200
         && ($result['pre_challenge']['body'] ?? null) === $authorization
         && ($result['application_control']['complete'] ?? false)
         && ($result['application_control']['status'] ?? 0) === 200
         && ($result['application_control']['body'] ?? null) === 'L1-APP-CONTROL'
         && ($result['post_challenge']['complete'] ?? false)
         && ($result['post_challenge']['status'] ?? 0) === 200
         && ($result['post_challenge']['body'] ?? null) === $authorization;
      if ($required === false) {
         return 'L1 fixture failed: a lifecycle/control invariant was not proved; evidence='
            . json_encode($result, JSON_UNESCAPED_SLASHES);
      }

      $during = $result['during'];
      $continuous = ($during['connected'] ?? false)
         && ($during['written'] ?? false)
         && ($during['complete'] ?? false)
         && ($during['status'] ?? 0) === 200
         && ($during['body'] ?? null) === $authorization;
      if ($continuous) {
         return true;
      }

      $queued = $result['queued_after_release'];
      if (
         ($result['old_helper_died'] ?? false)
         && ($during['connected'] ?? false)
         && ($during['written'] ?? false)
         && ($during['complete'] ?? false) === false
         && ($queued['complete'] ?? false)
         && ($queued['status'] ?? 0) === 200
         && ($queued['body'] ?? null) === $authorization
      ) {
         return 'CONFIRMED L1: the preserved HTTP-01 Gate accepted the validation connection during '
            . 'daemon reload, but no helper answered before the 1.25-second deadline while the real '
            . 'application worker remained in graceful drain; the same queued connection returned '
            . 'HTTP 200 with the exact challenge authorization only after the drain blocker was released. '
            . 'Evidence=' . json_encode($result, JSON_UNESCAPED_SLASHES);
      }

      return 'L1 fixture failed: the during-reload result was neither continuous service nor the '
         . 'expected helper-free pause; evidence=' . json_encode($result, JSON_UNESCAPED_SLASHES);
   },
);
