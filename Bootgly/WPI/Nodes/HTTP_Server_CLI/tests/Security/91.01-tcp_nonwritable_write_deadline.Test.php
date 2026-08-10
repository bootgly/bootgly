<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Connections as WPIConnections;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections as TCPConnections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC L2 (2026-08-02 deferred-write deadline) — a TCP write that
 * remains non-writable must be closed when maxWriteWallTime expires, without
 * depending on another EVENT_WRITE callback to notice that expiration.
 *
 * Both legs use real nonblocking kernel sockets, the production TCP Package,
 * a real Connection and the production Select reactor. The control first
 * defers on backpressure, then drains its peer and proves EVENT_WRITE resumes
 * and releases the exact pending bytes. The attack never drains its peer and
 * observes the connection after the configured wall-time budget.
 */
$probe = [
   'fixture_error' => '',
   'source' => [],
   'control' => [],
   'attack' => [],
   'cleanup' => [],
];

return new Test(
   description: 'TCP maxWriteWallTime must expire a permanently non-writable peer',
   Separator: new Separator(line: true),

   request: static function () use (&$probe): string {
      $OldEvent = TCPServer::$Event;
      $oldContext = TCPServer::$context;
      $oldWriteWallTime = TCPServer::$maxWriteWallTime;
      $baselinePendingBytes = TCPServer::$pendingBytes;

      /** @var null|Select $Selector */
      $Selector = null;
      /** @var null|Connection $ControlConnection */
      $ControlConnection = null;
      /** @var null|Connection $AttackConnection */
      $AttackConnection = null;
      /** @var null|TCPPackages $ControlPackage */
      $ControlPackage = null;
      /** @var null|TCPPackages $AttackPackage */
      $AttackPackage = null;
      /** @var array<int,resource> $ControlSockets */
      $ControlSockets = [];
      /** @var array<int,resource> $AttackSockets */
      $AttackSockets = [];

      /** @return array{0:resource,1:resource} */
      $Pair = static function (): array {
         $Sockets = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
         );
         if ($Sockets === false) {
            throw new RuntimeException('L2 could not create a UNIX socket pair.');
         }

         foreach ($Sockets as $Socket) {
            if (stream_set_blocking($Socket, false) === false) {
               foreach ($Sockets as $CloseSocket) {
                  @fclose($CloseSocket);
               }
               throw new RuntimeException('L2 could not make its socket pair nonblocking.');
            }
            // UNIX socket streams may report that PHP's userland write buffer
            // is not configurable. Kernel SO_SNDBUF saturation below is the
            // actual backpressure precondition, so this remains best-effort.
            @stream_set_write_buffer($Socket, 0);
         }

         return $Sockets;
      };

      /**
       * Fill one kernel send queue without growing a PHP string. A 4 MiB hard
       * cap keeps an environmental failure bounded in both memory and time.
       *
       * @param resource $Socket
       * @return array{bytes:int,zero:bool,send_buffer:bool|null}
       */
      $Fill = static function ($Socket): array {
         $sendBuffer = null;
         if (
            function_exists('socket_import_stream')
            && defined('SOL_SOCKET')
            && defined('SO_SNDBUF')
         ) {
            try {
               $NativeSocket = socket_import_stream($Socket);
               $sendBuffer = $NativeSocket === false
                  ? false
                  : socket_set_option($NativeSocket, SOL_SOCKET, SO_SNDBUF, 4096);
            }
            catch (Throwable) {
               $sendBuffer = false;
            }
         }

         $filler = str_repeat('F', 65_536);
         $bytes = 0;
         $zero = false;
         while ($bytes < 4_194_304) {
            $sent = @fwrite($Socket, $filler);
            if ($sent === false) {
               throw new RuntimeException('L2 kernel-buffer fill returned false.');
            }
            if ($sent === 0) {
               $zero = true;
               break;
            }
            $bytes += $sent;
         }

         return [
            'bytes' => $bytes,
            'zero' => $zero,
            'send_buffer' => $sendBuffer,
         ];
      };

      /** @param resource $Socket */
      $Writable = static function ($Socket): int|false {
         $reads = [];
         $writes = [$Socket];
         $excepts = [];

         return @stream_select($reads, $writes, $excepts, 0, 0);
      };

      $Retains = static function (Select $Selector, string $property, int $id): bool {
         $Reflection = new ReflectionProperty(Select::class, $property);
         $Reflection->setAccessible(true);
         $values = $Reflection->getValue($Selector);

         return is_array($values) && array_key_exists($id, $values);
      };

      try {
         $Connections = new class implements WPIConnections {
            public function connect (): bool
            {
               return false;
            }
         };
         $Selector = new Select($Connections);
         TCPServer::$Event = $Selector;
         TCPServer::$context = [];
         TCPServer::$maxWriteWallTime = 1;

         foreach ([
            'packages' => TCPPackages::class,
            'select' => Select::class,
            'connection' => Connection::class,
         ] as $name => $class) {
            $Reflection = new ReflectionClass($class);
            $file = $Reflection->getFileName();
            $probe['source'][$name] = [
               'file' => is_string($file) ? $file : '',
               'sha256' => is_string($file) ? hash_file('sha256', $file) : false,
            ];
         }

         // # Positive readiness control: genuinely defer on a full socket,
         // drain the peer before expiry, then let Select dispatch writing().
         $ControlSockets = $Pair();
         $controlFill = $Fill($ControlSockets[0]);
         $controlWritable = $Writable($ControlSockets[0]);
         $ControlConnection = new Connection($ControlSockets[0], '127.0.0.1', 41001);
         $ControlPackage = new class($ControlConnection) extends TCPPackages {};
         $controlPayload = 'L2-CONTROL';
         $controlStarted = microtime(true);
         $controlResult = $ControlPackage->writing(
            $ControlSockets[0],
            buffer: $controlPayload,
         );
         $controlID = (int) $ControlSockets[0];
         $probe['control']['armed'] = [
            'fill' => $controlFill,
            'writable' => $controlWritable,
            'result' => $controlResult,
            'pending' => $ControlPackage->pendingBuffer,
            'deadline' => $ControlPackage->pendingDeadline,
            'write_registered' => $ControlPackage->writeRegistered,
            'selector_socket' => $Retains($Selector, 'writes', $controlID),
            'selector_payload' => $Retains($Selector, 'writing', $controlID),
            'retained' => $ControlPackage->Buffers->retained,
            'worker_pending' => TCPServer::$pendingBytes,
         ];

         $Selector->defer(
            (int) hrtime(true) + 50_000_000,
            static function () use (&$probe, $ControlSockets): void {
               $drained = 0;
               while (true) {
                  $chunk = @fread($ControlSockets[1], 65_536);
                  if ($chunk === false || $chunk === '') {
                     break;
                  }
                  $drained += strlen($chunk);
               }
               $probe['control']['drained'] = $drained;
            },
         );
         $Selector->defer(
            (int) hrtime(true) + 250_000_000,
            static function () use (
               &$probe,
               $ControlSockets,
               $ControlConnection,
               $ControlPackage,
               $Selector,
               $Retains,
               $controlID,
               $controlStarted,
            ): void {
               try {
                  $payload = '';
                  while (true) {
                     $chunk = @fread($ControlSockets[1], 65_536);
                     if ($chunk === false || $chunk === '') {
                        break;
                     }
                     $payload .= $chunk;
                  }
                  $probe['control']['observed'] = [
                     'elapsed' => microtime(true) - $controlStarted,
                     'payload' => $payload,
                     'status' => $ControlConnection->status,
                     'pending' => $ControlPackage->pendingBuffer,
                     'deadline' => $ControlPackage->pendingDeadline,
                     'write_registered' => $ControlPackage->writeRegistered,
                     'selector_socket' => $Retains($Selector, 'writes', $controlID),
                     'selector_payload' => $Retains($Selector, 'writing', $controlID),
                     'retained' => $ControlPackage->Buffers->retained,
                     'worker_pending' => TCPServer::$pendingBytes,
                  ];
               }
               catch (Throwable $Throwable) {
                  $probe['fixture_error'] = $Throwable::class . ': ' . $Throwable->getMessage();
               }
               finally {
                  $Selector->loop = false;
               }
            },
         );
         $Selector->loop();

         // # Attack: the peer remains open but never reads. The only normal
         // callback registration is EVENT_WRITE on the saturated writer.
         $Selector->loop = true;
         $AttackSockets = $Pair();
         $attackFill = $Fill($AttackSockets[0]);
         $attackWritable = $Writable($AttackSockets[0]);
         $AttackConnection = new Connection($AttackSockets[0], '127.0.0.1', 41002);
         $AttackPackage = new class($AttackConnection) extends TCPPackages {};
         $attackPayload = 'L2';
         $attackStartedWall = microtime(true);
         $attackStartedNS = (int) hrtime(true);
         $attackResult = $AttackPackage->writing(
            $AttackSockets[0],
            buffer: $attackPayload,
         );
         $attackID = (int) $AttackSockets[0];
         $pendingDeadline = $AttackPackage->pendingDeadline;
         $probe['attack']['armed'] = [
            'fill' => $attackFill,
            'writable' => $attackWritable,
            'result' => $attackResult,
            'pending' => $AttackPackage->pendingBuffer,
            'offset' => $AttackPackage->pendingOffset,
            'deadline' => $pendingDeadline,
            'deadline_delta' => $pendingDeadline - $attackStartedWall,
            'write_registered' => $AttackPackage->writeRegistered,
            'selector_socket' => $Retains($Selector, 'writes', $attackID),
            'selector_payload' => $Retains($Selector, 'writing', $attackID),
            'retained' => $AttackPackage->Buffers->retained,
            'worker_pending' => TCPServer::$pendingBytes,
            'status' => $AttackConnection->status,
            'expiration' => $AttackConnection->expiration,
            'writes' => $AttackConnection->writes,
         ];

         $remaining = max(0.0, $pendingDeadline - microtime(true));
         $watchdogNS = (int) hrtime(true) + (int) (($remaining + 0.35) * 1_000_000_000);
         $Selector->defer(
            $watchdogNS,
            static function () use (
               &$probe,
               $AttackSockets,
               $AttackConnection,
               $AttackPackage,
               $ControlSockets,
               $ControlConnection,
               $ControlPackage,
               $Selector,
               $Retains,
               $Writable,
               $attackID,
               $attackStartedNS,
               $pendingDeadline,
            ): void {
               try {
                  $open = is_resource($AttackSockets[0]);
                  $probe['attack']['observed'] = [
                     'watchdog_fired' => true,
                     'elapsed_ns' => (int) hrtime(true) - $attackStartedNS,
                     'wall' => microtime(true),
                     'past_deadline' => microtime(true) > $pendingDeadline,
                     'open' => $open,
                     'writable' => $open ? $Writable($AttackSockets[0]) : null,
                     'status' => $AttackConnection->status,
                     'pending' => $AttackPackage->pendingBuffer,
                     'offset' => $AttackPackage->pendingOffset,
                     'deadline' => $AttackPackage->pendingDeadline,
                     'write_registered' => $AttackPackage->writeRegistered,
                     'selector_socket' => $Retains($Selector, 'writes', $attackID),
                     'selector_payload' => $Retains($Selector, 'writing', $attackID),
                     'retained' => $AttackPackage->Buffers->retained,
                     'worker_pending' => TCPServer::$pendingBytes,
                     'writes' => $AttackConnection->writes,
                     // ! The drained control remains alive beyond its original
                     //   deadline. A stale/cancelled generation must not close
                     //   it while the attack generation owns a later timer.
                     'control_open' => is_resource($ControlSockets[0]),
                     'control_status' => $ControlConnection->status,
                     'control_pending' => $ControlPackage->pendingBuffer,
                     'control_deadline' => $ControlPackage->pendingDeadline,
                     'control_write_registered' => $ControlPackage->writeRegistered,
                     'control_retained' => $ControlPackage->Buffers->retained,
                  ];
               }
               catch (Throwable $Throwable) {
                  $probe['fixture_error'] = $Throwable::class . ': ' . $Throwable->getMessage();
               }
               finally {
                  $Selector->loop = false;
               }
            },
         );
         $Selector->loop();

         // # Manual re-entry is a control, performed only after preserving the
         // observer snapshot. It proves the existing deadline check closes the
         // same socket when writing() is actually invoked after expiration.
         if (is_resource($AttackSockets[0])) {
            $manualResult = $AttackPackage->writing($AttackSockets[0], buffer: '');
            $probe['attack']['manual'] = [
               'invoked' => true,
               'result' => $manualResult,
               'open' => is_resource($AttackSockets[0]),
               'status' => $AttackConnection->status,
               'pending' => $AttackPackage->pendingBuffer,
               'deadline' => $AttackPackage->pendingDeadline,
               'write_registered' => $AttackPackage->writeRegistered,
               'retained' => $AttackPackage->Buffers->retained,
               'worker_pending' => TCPServer::$pendingBytes,
            ];
         }
         else {
            $probe['attack']['manual'] = ['invoked' => false];
         }
      }
      catch (Throwable $Throwable) {
         $probe['fixture_error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         try {
            if (
               $AttackConnection instanceof Connection
               && $AttackConnection->status <= TCPConnections::STATUS_ESTABLISHED
            ) {
               $AttackConnection->close();
            }
            if (
               $AttackPackage instanceof TCPPackages
               && $AttackPackage->Buffers->retained > 0
               && $AttackConnection instanceof Connection
            ) {
               $CleanupSocket = &$AttackConnection->Socket;
               $AttackPackage->writing($CleanupSocket, buffer: '');
            }
            if (
               $ControlConnection instanceof Connection
               && $ControlConnection->status <= TCPConnections::STATUS_ESTABLISHED
            ) {
               $ControlConnection->close();
            }
            if (
               $ControlPackage instanceof TCPPackages
               && $ControlPackage->Buffers->retained > 0
               && $ControlConnection instanceof Connection
            ) {
               $CleanupSocket = &$ControlConnection->Socket;
               $ControlPackage->writing($CleanupSocket, buffer: '');
            }
         }
         catch (Throwable $Throwable) {
            if ($probe['fixture_error'] === '') {
               $probe['fixture_error'] = 'L2 cleanup: '
                  . $Throwable::class . ': ' . $Throwable->getMessage();
            }
         }

         if ($Selector instanceof Select) {
            $Selector->destroy();
         }
         foreach ([$ControlSockets, $AttackSockets] as $Sockets) {
            foreach ($Sockets as $Socket) {
               if (is_resource($Socket)) {
                  @fclose($Socket);
               }
            }
         }

         unset($ControlPackage, $AttackPackage, $ControlConnection, $AttackConnection);
         gc_collect_cycles();
         $probe['cleanup'] = [
            'baseline_pending' => $baselinePendingBytes,
            'final_pending' => TCPServer::$pendingBytes,
         ];

         TCPServer::$maxWriteWallTime = $oldWriteWallTime;
         TCPServer::$context = $oldContext;
         TCPServer::$Event = $OldEvent;
      }

      return "GET /l2-write-deadline-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) {
      yield $Router->route(
         '/l2-write-deadline-harness',
         static function (Request $Request, Response $Response) {
            return $Response(code: 200, body: 'L2-HARNESS-OK');
         },
         GET,
      );
      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'L2-HARNESS-OK') === false
      ) {
         Vars::$labels = ['L2 native HTTP harness control', 'L2 evidence'];
         dump(json_encode($response), json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'L2 fixture failed: the independent HTTP harness did not complete.';
      }
      if ($probe['fixture_error'] !== '') {
         Vars::$labels = ['L2 fixture error', 'L2 evidence'];
         dump($probe['fixture_error'], json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'L2 fixture failed before a security conclusion: '
            . $probe['fixture_error'];
      }

      foreach ([
         'packages' => 'Bootgly/WPI/Interfaces/TCP_Server_CLI/Packages.php',
         'select' => 'Bootgly/WPI/Events/Select.php',
         'connection' => 'Bootgly/WPI/Interfaces/TCP_Server_CLI/Connections/Connection.php',
      ] as $name => $relative) {
         $expected = realpath(BOOTGLY_ROOT_DIR . $relative);
         $loaded = realpath((string) ($probe['source'][$name]['file'] ?? ''));
         if (
            is_string($expected) === false
            || $loaded !== $expected
            || ($probe['source'][$name]['sha256'] ?? false) !== hash_file('sha256', $expected)
         ) {
            Vars::$labels = ['L2 exact-worktree source control', 'L2 evidence'];
            dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

            return "L2 fixture failed: {$name} was not loaded from the exact worktree bytes.";
         }
      }

      $controlArmed = $probe['control']['armed'] ?? [];
      $controlObserved = $probe['control']['observed'] ?? [];
      if (
         ($controlArmed['fill']['zero'] ?? false) !== true
         || ($controlArmed['writable'] ?? null) !== 0
         || ($controlArmed['result'] ?? false) !== true
         || ($controlArmed['pending'] ?? '') !== 'L2-CONTROL'
         || ($controlArmed['write_registered'] ?? false) !== true
         || ($controlArmed['selector_socket'] ?? false) !== true
         || ($controlArmed['selector_payload'] ?? false) !== true
         || ($controlArmed['retained'] ?? -1) !== strlen('L2-CONTROL')
         || ($probe['control']['drained'] ?? 0) < 1
         || ($controlObserved['elapsed'] ?? 0.0) >= 1.0
         || ($controlObserved['payload'] ?? '') !== 'L2-CONTROL'
         || ($controlObserved['status'] ?? null) !== TCPConnections::STATUS_ESTABLISHED
         || ($controlObserved['pending'] ?? null) !== ''
         || ($controlObserved['deadline'] ?? null) !== 0.0
         || ($controlObserved['write_registered'] ?? true) !== false
         || ($controlObserved['selector_socket'] ?? true) !== false
         || ($controlObserved['selector_payload'] ?? true) !== false
         || ($controlObserved['retained'] ?? -1) !== 0
      ) {
         Vars::$labels = ['L2 real EVENT_WRITE control', 'L2 evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'L2 fixture failed: the drained control did not defer and resume '
            . 'through the production Select EVENT_WRITE path before its deadline.';
      }

      $baseline = $probe['cleanup']['baseline_pending'] ?? -1;
      $attackArmed = $probe['attack']['armed'] ?? [];
      if (
         ($attackArmed['fill']['zero'] ?? false) !== true
         || ($attackArmed['writable'] ?? null) !== 0
         || ($attackArmed['result'] ?? false) !== true
         || ($attackArmed['pending'] ?? '') !== 'L2'
         || ($attackArmed['offset'] ?? -1) !== 0
         || ($attackArmed['deadline_delta'] ?? 0.0) < 0.9
         || ($attackArmed['deadline_delta'] ?? 2.0) > 1.1
         || ($attackArmed['write_registered'] ?? false) !== true
         || ($attackArmed['selector_socket'] ?? false) !== true
         || ($attackArmed['selector_payload'] ?? false) !== true
         || ($attackArmed['retained'] ?? -1) !== 2
         || ($attackArmed['worker_pending'] ?? -1) !== $baseline + 2
         || ($attackArmed['status'] ?? null) !== TCPConnections::STATUS_ESTABLISHED
         || ($attackArmed['expiration'] ?? null) !== 15
         || ($attackArmed['writes'] ?? null) !== 0
      ) {
         Vars::$labels = ['L2 saturated deferred-write precondition', 'L2 evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'L2 fixture failed: writing()->transmit()->defer() did not arm '
            . 'the expected real-socket deadline and retained-byte state.';
      }

      $observed = $probe['attack']['observed'] ?? [];
      $manual = $probe['attack']['manual'] ?? [];
      $cleanup = $probe['cleanup'];
      $vulnerable = ($observed['watchdog_fired'] ?? false) === true
         && ($observed['elapsed_ns'] ?? 0) >= 1_200_000_000
         && ($observed['past_deadline'] ?? false) === true
         && ($observed['open'] ?? false) === true
         && ($observed['writable'] ?? null) === 0
         && ($observed['status'] ?? null) === TCPConnections::STATUS_ESTABLISHED
         && ($observed['pending'] ?? '') === 'L2'
         && ($observed['offset'] ?? -1) === 0
         && ($observed['deadline'] ?? 0.0) === ($attackArmed['deadline'] ?? -1.0)
         && ($observed['write_registered'] ?? false) === true
         && ($observed['selector_socket'] ?? false) === true
         && ($observed['selector_payload'] ?? false) === true
         && ($observed['retained'] ?? -1) === 2
         && ($observed['worker_pending'] ?? -1) === $baseline + 2
         && ($observed['writes'] ?? null) === 0
         && ($observed['control_open'] ?? false) === true
         && ($observed['control_status'] ?? null) === TCPConnections::STATUS_ESTABLISHED
         && ($observed['control_pending'] ?? null) === ''
         && ($observed['control_deadline'] ?? null) === 0.0
         && ($observed['control_write_registered'] ?? true) === false
         && ($observed['control_retained'] ?? -1) === 0
         && ($manual['invoked'] ?? false) === true
         && ($manual['result'] ?? true) === false
         && ($manual['open'] ?? true) === false
         && ($manual['status'] ?? null) === TCPConnections::STATUS_CLOSED
         && ($manual['pending'] ?? null) === ''
         && ($manual['deadline'] ?? null) === 0.0
         && ($manual['write_registered'] ?? true) === false
         && ($manual['retained'] ?? -1) === 0
         && ($cleanup['final_pending'] ?? -1) === $baseline;
      if ($vulnerable) {
         Vars::$labels = ['L2 confirmed deferred-write deadline evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'CONFIRMED L2 (2026-08-02 deferred-write deadline): '
            . 'maxWriteWallTime expired while the real peer remained non-writable, '
            . 'but Select retained the open connection and its pending bytes until '
            . 'Packages::writing() was invoked manually after the deadline.';
      }

      $safe = ($observed['watchdog_fired'] ?? false) === true
         && ($observed['past_deadline'] ?? false) === true
         && ($observed['open'] ?? true) === false
         && ($observed['status'] ?? null) === TCPConnections::STATUS_CLOSED
         && ($observed['pending'] ?? null) === ''
         && ($observed['deadline'] ?? null) === 0.0
         && ($observed['write_registered'] ?? true) === false
         && ($observed['selector_socket'] ?? true) === false
         && ($observed['selector_payload'] ?? true) === false
         && ($observed['retained'] ?? -1) === 0
         && ($observed['worker_pending'] ?? -1) === $baseline
         && ($observed['control_open'] ?? false) === true
         && ($observed['control_status'] ?? null) === TCPConnections::STATUS_ESTABLISHED
         && ($observed['control_pending'] ?? null) === ''
         && ($observed['control_deadline'] ?? null) === 0.0
         && ($observed['control_write_registered'] ?? true) === false
         && ($observed['control_retained'] ?? -1) === 0
         && ($manual['invoked'] ?? true) === false
         && ($cleanup['final_pending'] ?? -1) === $baseline;
      if ($safe) {
         return true;
      }

      Vars::$labels = ['L2 unexpected deadline state', 'L2 evidence'];
      dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

      return 'L2 probe reached an unexpected partial state; automatic deadline '
         . 'enforcement was not established and the confirmation oracle was incomplete.';
   },
);
