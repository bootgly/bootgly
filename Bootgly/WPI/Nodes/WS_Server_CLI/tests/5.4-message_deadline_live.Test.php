<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\WS_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI\Configs;
use Bootgly\WPI\Nodes\WS_Server_CLI\Events;


// ! Embedded fixture: a real server configured through Configs, so the
//   wall time reaches the Session through adopt() and is enforced by the
//   supervisor a real worker runs.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--ws-message-deadline-probe'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $port = (int) ($_SERVER['argv'][3] ?? 0);
   $idle = ($_SERVER['argv'][4] ?? '') === 'idle';
   if (realpath("{$root}/autoboot.php") === false || $port < 1024 || $port > 65535) {
      exit(2);
   }
   @posix_setsid();

   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";
   Display::show(Display::NONE);

   // ! A spec-only class: its state inodes are this spec's alone to remove
   final class MessageDeadlineProbe extends WS_Server_CLI
   {
   }

   $Server = new MessageDeadlineProbe(Modes::Foreground);
   // ? Without a heartbeat (and a long idle timeout) the supervisor tick
   //   still bounds the deadline
   $Server->configure($idle
      ? new Configs(host: '127.0.0.1', port: $port, workers: 1, heartbeatInterval: 0, idleTimeout: 3600, maxMessageWallTime: 2)
      : new Configs(
         host: '127.0.0.1',
         port: $port,
         workers: 1,
         heartbeatInterval: 2,
         maxMessageWallTime: 2,
         maxWorkerPendingBytes: 262_144,
      ));
   $Server->on(Events::MessageReceived, static fn ($Session, $Message): string => 'ack');
   $Server->start();
   exit(0);
}


/**
 * M4 (liveness, live) — a peer that keeps dripping bytes into a frame it never
 * finishes is closed with 1008 once `maxMessageWallTime` passes, although its
 * bytes keep the heartbeat from ever probing it; a silent peer is still reaped
 * by the heartbeat and a peer that completes its messages stays open. A peer
 * whose partial frame outgrows the configured `maxWorkerPendingBytes` is
 * closed with 1009.
 */
return new Test(
   description: 'M4: a live server closes a dripped, never-finished frame with 1008 at maxMessageWallTime',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      // @ Reserve a free loopback port.
      $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }

      /** A masked client frame: header and payload. */
      $Frame = static function (int $opcode, bool $fin, string $payload, null|int $announce = null): string {
         $length = $announce ?? strlen($payload);
         $wire = chr(($fin ? 0x80 : 0x00) | $opcode);
         if ($length < 126) {
            $wire .= chr(0x80 | $length);
         }
         else if ($length < 65536) {
            $wire .= chr(0x80 | 126) . pack('n', $length);
         }
         else {
            $wire .= chr(0x80 | 127) . pack('J', $length);
         }
         // ! A zero mask key keeps the dripped bytes trivially valid
         return "{$wire}\x00\x00\x00\x00{$payload}";
      };
      /** A handshaken peer socket on `$port`, or false. */
      $Connect = static function (int $port) {
         $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 1.0);
         if ($Client === false) {
            return false;
         }
         stream_set_timeout($Client, 1);
         fwrite($Client, "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\n"
            . "Connection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n");
         $head = '';
         while (str_contains($head, "\r\n\r\n") === false) {
            $chunk = fread($Client, 1024);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $head .= $chunk;
         }
         if (str_starts_with($head, 'HTTP/1.1 101') === false) {
            fclose($Client);

            return false;
         }
         stream_set_blocking($Client, false);

         return $Client;
      };

      $Process = proc_open(
         [PHP_BINARY, __FILE__, '--ws-message-deadline-probe', BOOTGLY_ROOT_BASE, (string) $port],
         [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
         $Pipes,
         BOOTGLY_ROOT_BASE,
      );
      $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;

      $Observed = [];
      /** @var array<string,resource> $Peers */
      $Peers = [];
      try {
         // @ Wait for the server
         $deadline = hrtime(true) + 8_000_000_000;
         $Drip = false;
         while ($Drip === false && hrtime(true) < $deadline) {
            usleep(50_000);
            $Drip = $Connect($port);
         }
         $Silent = $Connect($port);
         $Chatty = $Connect($port);
         $Big = $Connect($port);
         $Observed['connected'] = $Drip !== false && $Silent !== false && $Chatty !== false && $Big !== false;

         $closed = ['drip' => null, 'silent' => null, 'chatty' => null, 'big' => null];
         $codes = ['drip' => null, 'big' => null];
         if ($Observed['connected']) {
            $Peers = ['drip' => $Drip, 'silent' => $Silent, 'chatty' => $Chatty, 'big' => $Big];

            // @ big: a partial frame larger than the worker budget (256 KiB)
            stream_set_blocking($Big, true);
            @fwrite($Big, $Frame(0x2, true, str_repeat('B', 307_200), announce: 1_048_576));
            stream_set_blocking($Big, false);

            // @ drip: a 1 MiB frame announced, a few bytes sent
            fwrite($Drip, $Frame(0x2, true, str_repeat('D', 64), announce: 1_048_576));
            $started = hrtime(true);
            $buffers = ['drip' => '', 'silent' => '', 'chatty' => '', 'big' => ''];

            // @@ Six seconds: drip one byte and send one whole message every 300 ms
            $tick = 0;
            while (hrtime(true) - $started < 6_000_000_000) {
               usleep(300_000);
               $tick++;
               if ($closed['drip'] === null) {
                  @fwrite($Drip, 'd');
               }
               if ($closed['chatty'] === null) {
                  @fwrite($Chatty, $Frame(0x1, true, "m{$tick}"));
               }
               foreach ($Peers as $role => $Peer) {
                  if ($closed[$role] !== null) {
                     continue;
                  }
                  $chunk = @fread($Peer, 65_536);
                  if (is_string($chunk) && $chunk !== '') {
                     $buffers[$role] .= $chunk;
                  }
                  if (feof($Peer)) {
                     $closed[$role] = (int) ((hrtime(true) - $started) / 1_000_000);
                  }
               }
            }

            // ? Each close frame: 0x88, length, code
            foreach (['drip', 'big'] as $role) {
               $at = strpos($buffers[$role], "\x88");
               if ($at !== false && strlen($buffers[$role]) >= $at + 4) {
                  $codes[$role] = (int) unpack('n', substr($buffers[$role], $at + 2, 2))[1];
               }
            }
         }

         $Observed['drip closed 1008'] = $closed['drip'] !== null && $codes['drip'] === 1008;
         $Observed['drip closed after the wall time'] = $closed['drip'] !== null && $closed['drip'] >= 1_900;
         $Observed['silent reaped'] = $closed['silent'] !== null;
         $Observed['chatty open'] = $closed['chatty'] === null;
         $Observed['big closed 1009'] = $closed['big'] !== null && $codes['big'] === 1009;
      }
      finally {
         foreach ($Peers as $Peer) {
            @fclose($Peer);
         }
         // ! The whole session group; never PID 0/-1 (this runner's group)
         if ($master > 0) {
            posix_kill(-$master, SIGKILL);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $cleanup = hrtime(true) + 1_000_000_000;
         while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }
         $Observed['group clean'] = $master > 0 && posix_kill(-$master, 0) === false;

         // @ This run's state inodes, by their literal prefix
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "MessageDeadlineProbe.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      }

      yield new Assertion(description: 'a dripped frame closes 1008 after maxMessageWallTime, an oversized hold 1009; heartbeat and whole messages behave as before')
         ->expect($Observed, Op::Identical, [
            'connected' => true,
            'drip closed 1008' => true,
            'drip closed after the wall time' => true,
            'silent reaped' => true,
            'chatty open' => true,
            'big closed 1009' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ Heartbeat off, idle timeout of an hour: the tick follows the wall time
      $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }
      $Process = proc_open(
         [PHP_BINARY, __FILE__, '--ws-message-deadline-probe', BOOTGLY_ROOT_BASE, (string) $port, 'idle'],
         [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
         $Pipes,
         BOOTGLY_ROOT_BASE,
      );
      $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
      $Observed = [];
      $Drip = false;
      try {
         $deadline = hrtime(true) + 8_000_000_000;
         while ($Drip === false && hrtime(true) < $deadline) {
            usleep(50_000);
            $Drip = $Connect($port);
         }
         $closed = null;
         $buffer = '';
         if ($Drip !== false) {
            fwrite($Drip, $Frame(0x2, true, str_repeat('D', 64), announce: 1_048_576));
            $started = hrtime(true);
            while ($closed === null && hrtime(true) - $started < 6_000_000_000) {
               usleep(300_000);
               @fwrite($Drip, 'd');
               $chunk = @fread($Drip, 65_536);
               if (is_string($chunk)) {
                  $buffer .= $chunk;
               }
               if (feof($Drip)) {
                  $closed = (int) ((hrtime(true) - $started) / 1_000_000);
               }
            }
         }
         $at = strpos($buffer, "\x88");
         $Observed['idle drip closed 1008'] = $closed !== null && $at !== false && strlen($buffer) >= $at + 4
            && (int) unpack('n', substr($buffer, $at + 2, 2))[1] === 1008;
      }
      finally {
         if ($Drip !== false) {
            @fclose($Drip);
         }
         if ($master > 0) {
            posix_kill(-$master, SIGKILL);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $cleanup = hrtime(true) + 1_000_000_000;
         while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }
         $Observed['group clean'] = $master > 0 && posix_kill(-$master, 0) === false;
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "MessageDeadlineProbe.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      }

      yield new Assertion(description: 'without a heartbeat the supervisor tick still closes the dripped frame 1008 near the wall time')
         ->expect($Observed, Op::Identical, [
            'idle drip closed 1008' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
