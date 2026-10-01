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
   description: 'A UDP socket the event backend refuses is refused loudly, at startup and in the worker',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('posix_getrlimit') === false
      || function_exists('posix_setrlimit') === false
      || function_exists('proc_open') === false
      || is_executable('/bin/bash') === false
      // ! The server raises its own soft limit: only the hard one must allow it
      || ((posix_getrlimit()['hard openfiles'] ?? 0) !== 'unlimited'
         && (int) (posix_getrlimit()['hard openfiles'] ?? 0) < 2048),
   test: new Assertions(Case: function (): Generator {
      // ! PAD: descriptors the launcher holds before start() (master probe).
      //   PADW: descriptors the worker opens before it binds (worker refusal).
      //   1100 either way pushes the bound socket past FD_SETSIZE (1024).
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}
// ! Room for the padding whatever soft limit the runner inherited
if (posix_setrlimit(POSIX_RLIMIT_NOFILE, 2048, 2048) === false) {
   exit(124);
}

require getenv('UDP_REFUSAL_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;

final class RefusalProbe extends UDP_Server_CLI
{
   /** @var array<int,resource> */
   public static array $Pad = [];

   public static function boot (mixed $Environment): void
   {
   }

   public function instance ()
   {
      for ($i = 0, $n = (int) getenv('UDP_REFUSAL_PADW'); $i < $n; $i++) {
         self::$Pad[] = fopen('/dev/null', 'r');
      }

      $Socket = parent::instance();

      // ! Report the descriptor the worker socket took (the highest socket
      //   descriptor open right after the bind) for the boundary legs
      $highest = -1;
      foreach ((array) scandir('/proc/self/fd') as $entry) {
         if (ctype_digit((string) $entry) && str_starts_with((string) @readlink("/proc/self/fd/{$entry}"), 'socket:')) {
            $highest = max($highest, (int) $entry);
         }
      }
      fwrite(STDOUT, "WORKER-FD={$highest}\n");

      return $Socket;
   }
}

for ($i = 0, $n = (int) getenv('UDP_REFUSAL_PAD'); $i < $n; $i++) {
   RefusalProbe::$Pad[] = fopen('/dev/null', 'r');
}
$Server = new RefusalProbe(Modes::Foreground);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('UDP_REFUSAL_PORT'),
   workers: 1,
));
$Server->on(Events::DatagramReceive, static fn (string $input): string => "served:{$input}");
$Server->start();
PHP;

      /**
       * Run one session-isolated server for at most $seconds, then tear its
       * whole group down. Returns its exit status (null while it still ran),
       * whether a datagram was answered, and its console output.
       *
       * @return array{exit:null|int,served:bool,output:string,clean:bool}
       */
      $Run = static function (int $pad, int $padw, float $seconds) use ($Script): array {
         $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }

         $Environment = (array) getenv();
         $Environment['UDP_REFUSAL_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['UDP_REFUSAL_PORT'] = (string) $port;
         $Environment['UDP_REFUSAL_PAD'] = (string) $pad;
         $Environment['UDP_REFUSAL_PADW'] = (string) $padw;
         // ! The boundary legs place the worker socket at an exact descriptor:
         //   the server must not inherit the runner's open descriptors (PHP
         //   opens them without close-on-exec), which would sit anywhere in
         //   the table — bash closes every one above stderr, then execs PHP.
         $Process = proc_open(
            [
               '/bin/bash', '-c', 'for d in /proc/$$/fd/*; do d=${d##*/}; [ "$d" -gt 2 ] && eval "exec $d<&-"; done; exec "$@"', 'bash',
               PHP_BINARY, '-d', 'display_errors=stderr', '-r', $Script,
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment,
         );
         $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         stream_set_blocking($Pipes[1], false);
         stream_set_blocking($Pipes[2], false);

         $Client = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         stream_set_blocking($Client, false);
         $output = '';
         $exit = null;
         $served = false;
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $output .= (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);
            $Status = proc_get_status($Process);
            if ($Status['running'] === false) {
               $exit = (int) $Status['exitcode'];
               break;
            }
            stream_socket_sendto($Client, 'probe', 0, "127.0.0.1:{$port}");
            $read = [$Client];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 100_000) === 1) {
               $served = $served || str_starts_with((string) @stream_socket_recvfrom($Client, 65_535), 'served:');
            }
         } while (hrtime(true) < $deadline);

         // ! The whole session group — a refork loop or a silent worker included.
         if ($master > 0) {
            posix_kill(-$master, SIGKILL);
         }
         $output .= (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);
         proc_close($Process);
         fclose($Client);
         $cleanup = hrtime(true) + 500_000_000;
         while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }

         return [
            'exit' => $exit,
            'served' => $served,
            'output' => $output,
            'clean' => $master > 0 && posix_kill(-$master, 0) === false,
         ];
      };

      // @ Control: no padding — serves, and reports its worker descriptor.
      $Control = $Run(0, 0, 3.0);
      $descriptor = preg_match('/WORKER-FD=(\d+)/', $Control['output'], $Match) === 1 ? (int) $Match[1] : -1;
      // @ The launcher holds 1100 descriptors: refused before any fork.
      $Startup = $Run(1100, 0, 3.0);
      // @ The worker alone crosses FD_SETSIZE: the worker refuses, loudly.
      $Worker = $Run(0, 1100, 2.0);
      // @ The boundary, exact: the worker socket at FD_SETSIZE - 1 serves; at
      //   FD_SETSIZE the start is refused before any worker is forked — never
      //   a worker that refuses and is reforked again and again. (glibc's
      //   FD_SETSIZE, which bounds PHP's select(); the serving leg proves the
      //   boundary sits where it is assumed.)
      $Below = $descriptor >= 0 ? $Run(1023 - $descriptor, 0, 2.5) : null;
      $Edge = $descriptor >= 0 ? $Run(1024 - $descriptor, 0, 2.5) : null;

      yield new Assertion(description: 'refused registrations never leave a silent worker')
         ->expect(
            [
               'control served' => $Control['served'],
               'startup exit' => $Startup['exit'],
               'startup refusal logged' => str_contains($Startup['output'], 'Listener rejected by the event backend during startup.'),
               'worker refusal logged' => str_contains($Worker['output'], 'Worker socket rejected by the event backend'),
               'worker never served' => $Worker['served'] === false,
               'worker descriptor reported' => $descriptor >= 0,
               'FD_SETSIZE - 1 serves' => $Below !== null && $Below['served'],
               'FD_SETSIZE refused at startup' => $Edge !== null && $Edge['exit'] === 1
                  && str_contains($Edge['output'], 'Listener rejected by the event backend during startup.'),
               'FD_SETSIZE never reforked' => $Edge !== null && str_contains($Edge['output'], 'recovered') === false,
               'groups clean' => $Control['clean'] && $Startup['clean'] && $Worker['clean']
                  && ($Below['clean'] ?? false) && ($Edge['clean'] ?? false),
            ],
            Op::Identical,
            [
               'control served' => true,
               'startup exit' => 1,
               'startup refusal logged' => true,
               'worker refusal logged' => true,
               'worker never served' => true,
               'worker descriptor reported' => true,
               'FD_SETSIZE - 1 serves' => true,
               'FD_SETSIZE refused at startup' => true,
               'FD_SETSIZE never reforked' => true,
               'groups clean' => true,
            ],
         )
         ->assert();
   }),
);
