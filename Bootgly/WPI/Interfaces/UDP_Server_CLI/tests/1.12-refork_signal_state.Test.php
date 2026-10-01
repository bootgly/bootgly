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


return new Test(
   description: 'A reforked UDP worker fields signals, ticks timers and frees a filled ceiling',
   skip: function_exists('pcntl_sigprocmask') === false
      || function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false
      || function_exists('stream_socket_server') === false,
   test: new Assertions(Case: function (): Generator {
      // ! One session-isolated Foreground server: 1 worker, ceiling 2, idle 1 s.
      //   The launcher blocks SIGUSR2 first, so every worker must serve under
      //   exactly [SIGUSR2] — the launcher's mask, never a dispatcher's.
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('UDP_REFORK_AUTOBOOT');

use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;

final class ReforkProbe extends UDP_Server_CLI
{
   public static int $ticks = 0;

   public static function boot (mixed $Environment): void
   {
   }
}

Display::show(Display::NONE);
pcntl_sigprocmask(SIG_SETMASK, [SIGUSR2]);

$Server = new ReforkProbe(Modes::Foreground);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('UDP_REFORK_PORT'),
   workers: 1,
   maxConnections: 2,
   maxConnectionsPerIP: 2,
   connectionIdleTimeout: 1,
));
$Server->on(Events::DatagramReceive, static function (string $input): string {
   if ($input === 'crash') {
      exit(3);
   }
   if ($input === 'arm') {
      Timer::add(interval: 1, handler: static function (): void {
         ReforkProbe::$ticks++;
      }, persistent: true);
   }
   // @ Read the mask without changing it
   $Mask = [];
   pcntl_sigprocmask(SIG_BLOCK, [SIGUSR2], $Mask);
   pcntl_sigprocmask(SIG_SETMASK, $Mask);
   sort($Mask);

   return json_encode(['pid' => getmypid(), 'mask' => $Mask, 'ticks' => ReforkProbe::$ticks]);
});
$Server->start();
PHP;

      // @ Reserve a free loopback port.
      $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }

      /** @var array<int,resource> $Clients */
      $Clients = [];
      /** Send one datagram from a named, stable source and read one reply. */
      $Ask = static function (string $source, string $payload, int $milliseconds = 200) use (&$Clients, $port): null|array {
         if (isSet($Clients[$source]) === false) {
            $Client = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
            if (is_resource($Client) === false) {
               return null;
            }
            stream_set_blocking($Client, false);
            $Clients[$source] = $Client;
         }
         $Client = $Clients[$source];
         while (@stream_socket_recvfrom($Client, 65_535) !== false) {
            // drain late replies
         }
         stream_socket_sendto($Client, $payload, 0, "127.0.0.1:{$port}");
         $read = [$Client];
         $write = null;
         $except = null;
         if (@stream_select($read, $write, $except, 0, $milliseconds * 1_000) !== 1) {
            return null;
         }
         $reply = @stream_socket_recvfrom($Client, 65_535);
         $Data = is_string($reply) ? json_decode($reply, true) : null;

         return is_array($Data) ? $Data : null;
      };
      /** Poll until a reply satisfies $Accept, within a hard deadline. */
      $Poll = static function (string $source, string $payload, Closure $Accept, float $seconds) use ($Ask): null|array {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $Data = $Ask($source, $payload);
            if ($Data !== null && $Accept($Data)) {
               return $Data;
            }
            usleep(50_000);
         } while (hrtime(true) < $deadline);

         return null;
      };
      /** Seconds until $PID is gone, or null past the bound. */
      $Gone = static function (int $PID, float $seconds): null|float {
         $started = hrtime(true);
         do {
            if ($PID <= 0) {
               return null;
            }
            if (posix_kill($PID, 0) === false) {
               return (hrtime(true) - $started) / 1e9;
            }
            usleep(10_000);
         } while (hrtime(true) - $started < (int) ($seconds * 1e9));

         return null;
      };

      $Environment = (array) getenv();
      $Environment['UDP_REFORK_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
      $Environment['UDP_REFORK_PORT'] = (string) $port;
      $Process = proc_open(
         [PHP_BINARY, '-r', $Script],
         [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
         $Pipes,
         BOOTGLY_ROOT_BASE,
         $Environment,
      );
      $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;

      $Observed = [];
      try {
         // @ The original worker, then a crash and its replacement.
         $Original = $Poll('a', 'state', static fn (array $Data): bool => true, 5.0);
         $Ask('a', 'crash');
         $Revived = $Poll('a', 'state', static fn (array $Data): bool => $Data['pid'] !== ($Original['pid'] ?? 0), 4.0);
         $Observed['original mask'] = $Original['mask'] ?? null;
         $Observed['revived mask'] = $Revived['mask'] ?? null;

         // @ Timers tick in the replacement — the Timer runs on whole-second
         //   SIGALRMs, so two ticks land within 1-3 s: poll, bounded.
         $Ask('a', 'arm');
         $Observed['revived ticks >= 2'] = $Poll(
            'a',
            'state',
            static fn (array $Data): bool => ($Data['ticks'] ?? 0) >= 2,
            6.0
         ) !== null;

         // @ Fill the ceiling (a + b), then a third peer waits for the idle sweep.
         $Ask('b', 'state');
         $Observed['third peer refused at fill'] = $Ask('c', 'state') === null;
         $Observed['third peer served after sweep'] = $Poll('c', 'state', static fn (array $Data): bool => true, 5.0) !== null;

         // @ The replacement answers SIGTERM; the master revives it again.
         $revived = (int) ($Revived['pid'] ?? 0);
         // ! Never signal PID 0/-1: that is this runner's own group.
         if ($revived > 0) {
            posix_kill($revived, SIGTERM);
         }
         $Observed['revived exits on SIGTERM'] = $Gone($revived, 1.5) !== null;
         $Second = $Poll('d', 'state', static fn (array $Data): bool => $Data['pid'] !== $revived, 4.0);
         $Observed['second revive mask'] = $Second['mask'] ?? null;

         // @ A hard-killed master leaves no orphan: the worker watchdog fires.
         if ($master > 0) {
            posix_kill($master, SIGKILL);
         }
         //   (a deaf replacement that never revived is the one watched)
         $Observed['worker exits after master SIGKILL'] = $Gone((int) ($Second['pid'] ?? $revived), 3.0) !== null;
      }
      finally {
         // ! The whole session group, including a signal-deaf or orphaned worker.
         if ($master > 0) {
            posix_kill(-$master, SIGKILL);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         foreach ($Clients as $Client) {
            fclose($Client);
         }
         $deadline = hrtime(true) + 500_000_000;
         while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $deadline) {
            usleep(10_000);
         }
         $Observed['group clean'] = $master > 0 && posix_kill(-$master, 0) === false;
      }

      yield new Assertion(description: 'reforked worker serves under the launcher mask, ticks, sweeps, stops and never orphans')
         ->expect(
            $Observed,
            Op::Identical,
            [
               'original mask' => [SIGUSR2],
               'revived mask' => [SIGUSR2],
               'revived ticks >= 2' => true,
               'third peer refused at fill' => true,
               'third peer served after sweep' => true,
               'revived exits on SIGTERM' => true,
               'second revive mask' => [SIGUSR2],
               'worker exits after master SIGKILL' => true,
               'group clean' => true,
            ],
         )
         ->assert();
   }),
);
