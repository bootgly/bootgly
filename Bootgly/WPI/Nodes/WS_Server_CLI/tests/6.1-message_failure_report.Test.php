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


/**
 * A message handler that throws does not end the session: the server catches
 * the failure and goes on. What it survives must still reach the operator —
 * the failure is reported through `Throwables::notify()`, with the transport
 * peer, and lands on the `exceptions` channel the server registers.
 *
 * The server runs in its own session, in Interactive mode with one worker; a
 * peer sends one message that makes the handler throw, then one it answers.
 */
return new Test(
   description: 'A WebSocket server should report a message handler failure it catches, and keep the session',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('REPORTED_AUTOBOOT');

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Process;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\WS_Server_CLI;

final class ReportedMessage extends WS_Server_CLI
{
   protected function work (Process $Process, int $index): void
   {
      file_put_contents((string) getenv('REPORTED_NOTES'), json_encode([
         'boot' => posix_getpid(),
      ]) . "\n", FILE_APPEND | LOCK_EX);

      parent::work($Process, $index);
   }
}

// ! What the report carries, beside the record the server logs
Throwables::$reporters[] = static function (Throwable $Throwable, array $context): void {
   file_put_contents((string) getenv('REPORTED_NOTES'), json_encode([
      'context' => $context,
   ]) . "\n", FILE_APPEND | LOCK_EX);
};

$Server = new ReportedMessage(Modes::Interactive);
$Server->configure(new WS_Server_CLI\Configs(
   host: '127.0.0.1',
   port: (int) getenv('REPORTED_PORT'),
   workers: 1,
));
$Server->on(WS_Server_CLI\Events::MessageReceived, static function ($Session, $Message): string {
   if ($Message->payload === 'boom') {
      throw new RuntimeException('reported-failure-ws-message');
   }

   return 'pong';
});
$Server->start();
PHP;

      // @ Reserve a free loopback port.
      $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }
      $notes = (string) tempnam(sys_get_temp_dir(), 'bootgly-reported-message-');

      /** A masked client text frame (zero mask key: the payload as it is). */
      $Frame = static fn (string $payload): string => "\x81" . chr(0x80 | strlen($payload)) . "\x00\x00\x00\x00{$payload}";
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
      /**
       * The notes the server left so far.
       *
       * @return array<int,array<string,mixed>>
       */
      $Notes = static function () use ($notes): array {
         $Records = [];
         foreach ((array) @file($notes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $Record = json_decode((string) $line, true);
            if (is_array($Record)) {
               $Records[] = $Record;
            }
         }

         return $Records;
      };

      $Environment = (array) getenv();
      $Environment['REPORTED_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
      $Environment['REPORTED_PORT'] = (string) $port;
      $Environment['REPORTED_NOTES'] = $notes;

      $Observed = [];
      $Contexts = [];
      $master = 0;
      $Pipes = [];
      $Process = null;
      $Peer = false;
      $clean = false;
      try {
         $Process = proc_open(
            [PHP_BINARY, '-d', 'opcache.jit=0', '-r', $Script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment,
         );
         $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         foreach ($Pipes as $Pipe) {
            stream_set_blocking($Pipe, false);
         }
         $output = '';
         // ! What the server wrote so far, without the terminal's colours
         $Read = static function () use (&$Pipes, &$output): string {
            $output .= (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);

            return (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
         };
         $record = 'exceptions.ERROR: reported-failure-ws-message';

         // @ Wait for the worker, then for its listener
         $worker = 0;
         $deadline = hrtime(true) + 10_000_000_000;
         while ($worker === 0 && hrtime(true) < $deadline) {
            $Read();
            usleep(20_000);
            foreach ($Notes() as $Note) {
               $worker = (int) ($Note['boot'] ?? $worker);
            }
         }
         $Observed['the worker booted'] = $worker > 0;
         while ($worker > 0 && $Peer === false && hrtime(true) < $deadline) {
            usleep(50_000);
            $Peer = $Connect($port);
         }
         $Observed['a peer connected'] = $Peer !== false;

         // @ One message the handler throws on
         if ($Peer !== false) {
            fwrite($Peer, $Frame('boom'));
         }
         $deadline = hrtime(true) + 5_000_000_000;
         while ($Peer !== false && str_contains($Read(), $record) === false && hrtime(true) < $deadline) {
            usleep(20_000);
         }
         $Observed['the failure is logged on the exceptions channel'] = str_contains($Read(), $record);

         // @ The same session, one message later: the handler answers
         $reply = '';
         if ($Peer !== false) {
            fwrite($Peer, $Frame('ping'));
         }
         $deadline = hrtime(true) + 5_000_000_000;
         while ($Peer !== false && str_contains($reply, "\x81\x04pong") === false && hrtime(true) < $deadline) {
            usleep(20_000);
            $reply .= (string) @fread($Peer, 1024);
         }
         $Observed['the session went on'] = $reply === "\x81\x04pong";
         $Observed['the worker went on'] = $worker > 0 && posix_kill($worker, 0);

         // @ A fast stop from the operator ends the master and its worker
         if ($Peer !== false) {
            fclose($Peer);
            $Peer = false;
         }
         if ($master > 0) {
            posix_kill($master, SIGTERM);
         }
         $deadline = hrtime(true) + 8_000_000_000;
         while (is_resource($Process) && proc_get_status($Process)['running'] && hrtime(true) < $deadline) {
            usleep(20_000);
         }
         $written = $Read();
         $Observed['one record for the one throw'] = substr_count($written, $record) === 1;
         $Observed['no crash logged'] = str_contains($written, 'crashed') === false;

         foreach ($Notes() as $Note) {
            if (isset($Note['context'])) {
               $Contexts[] = $Note['context'];
            }
         }
      }
      finally {
         if ($Peer !== false) {
            @fclose($Peer);
         }
         // ! The whole session group; never PID 0/-1 (this runner's group).
         if ($master > 0) {
            posix_kill(-$master, SIGKILL);
         }
         foreach ($Pipes as $Pipe) {
            @fclose($Pipe);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $cleanup = hrtime(true) + 1_000_000_000;
         while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }
         $clean = $master > 0 && posix_kill(-$master, 0) === false;
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, 'ReportedMessage') && str_contains((string) $file, ".{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
         @unlink($notes);
      }
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'the handler failure is logged once, and the session and the worker go on')
         ->expect($Observed, Op::Identical, [
            'the worker booted' => true,
            'a peer connected' => true,
            'the failure is logged on the exceptions channel' => true,
            'the session went on' => true,
            'the worker went on' => true,
            'one record for the one throw' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      yield new Assertion(description: 'the report carries the interface, the phase and the transport peer — once')
         ->expect($Contexts, Op::Identical, [
            ['interface' => 'WPI', 'phase' => 'Message', 'peer' => '127.0.0.1'],
         ])
         ->assert();
   })
);
