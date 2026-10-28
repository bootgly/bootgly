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
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;


// ! Embedded fixture: reload() replays this exact file through pcntl_exec,
//   so every image forces the shared socket before start().
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--udp-shared-probe'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $port = (int) ($_SERVER['argv'][3] ?? 0);
   $leg = (string) ($_SERVER['argv'][4] ?? '');
   $state = (string) ($_SERVER['argv'][5] ?? '');
   if (
      realpath("{$root}/autoboot.php") === false
      || $port < 1024 || $port > 65535
      || in_array($leg, ['serve', 'gap'], true) === false
      || $state === ''
   ) {
      exit(2);
   }
   // ? The first launch leads a new session; the replayed image already
   //   leads it, so a refused setsid() is how the replay knows itself.
   $session = @posix_setsid();
   $replayed = $session === false || $session === -1;

   // ? The reload gap, made deterministic: before start(), the replay has a
   //   plain PHP UDP bind take the port the stopped workers and the master
   //   left, and holds it for 2.5 s.
   if ($replayed && $leg === 'gap') {
      $squatter = pcntl_fork();
      if ($squatter === 0) {
         // ! Leave the master: the squatter is never one of its children
         if (pcntl_fork() !== 0) {
            exit(0);
         }
         $Socket = @stream_socket_server("udp://127.0.0.1:{$port}", $code, $message, STREAM_SERVER_BIND);
         if ($Socket === false) {
            file_put_contents("{$state}.refused", (string) $message);
            exit(1);
         }
         file_put_contents("{$state}.ready", (string) hrtime(true));
         usleep(2_500_000);
         fclose($Socket);
         file_put_contents("{$state}.released", (string) hrtime(true));
         exit(0);
      }
      if ($squatter > 0) {
         pcntl_waitpid($squatter, $status);
      }
      // @@ Never call start() before the squatter holds the port
      $deadline = hrtime(true) + 3_000_000_000;
      clearstatcache();
      while (is_file("{$state}.ready") === false && hrtime(true) < $deadline) {
         usleep(5_000);
         clearstatcache();
      }
   }

   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";

   // ! The Darwin path, forced
   UDP_Server_CLI::$shared = true;

   // ! A spec-only class: its state inodes are this spec's alone to remove
   final class SharedSocketProbe extends UDP_Server_CLI
   {
      public static function boot (mixed $Environment): void
      {
      }
   }

   $Server = new SharedSocketProbe(Modes::Foreground);
   $Server->configure(new Configs(host: '127.0.0.1', port: $port, workers: 3));
   $Server->on(Events::DatagramReceive, static function (string $input): string {
      // @ Busy this worker: its event loop reads nothing else meanwhile
      if ($input === 'hold') {
         usleep(800_000);
      }

      $PID = getmypid();
      $parent = posix_getppid();

      return "{$PID} {$parent}";
   });
   $Server->start();

   // @ A stop under CI returns here (Foreground): whether the master let its
   //   copy of the shared socket go — an exclusive bind of the port proves it
   $Released = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
   $free = $Released !== false && @socket_bind($Released, '127.0.0.1', $port);
   if ($Released !== false) {
      socket_close($Released);
   }
   file_put_contents("{$state}.stopped", $free ? 'free' : 'held');
   exit(0);
}


/**
 * Darwin's SO_REUSEPORT does not balance — XNU delivers every datagram to the
 * last socket bound — so there the master binds ONE socket exclusively and
 * every worker reads it (`UDP_Server_CLI::$shared`, forced here on Linux).
 * No socket joins or shares the port; the master keeps it from its start, so
 * no worker ever finds it missing and the port never empties, not even with
 * every worker dead; a datagram reaches an idle worker while the others are
 * busy, and no peer keeps a worker (the documented contract: per-peer state
 * lives in each worker a peer reaches); a reload rebinds it for fresh workers
 * of the same master, a refork inherits it, a stop lets it go, and a reload
 * that finds the port taken backs its workers off until the master binds it
 * again — exclusively. A Test-mode master keeps no copy, an ephemeral port is
 * one port for every worker, and a start cancelled after its probe leaves the
 * port free.
 */
return new Test(
   description: 'Darwin shared UDP socket (forced): exclusive, kept by the master from its start, read by every worker with no peer affinity, rebound across reload, inherited by a refork, released by a stop, regained exclusively after the reload gap',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('pcntl_fork') === false
      || function_exists('proc_open') === false
      || function_exists('socket_create') === false,
   test: new Assertions(Case: function (): Generator {
      // # Pins — the switch is derived from the OS family alone, the shared
      //   socket never parks a worker, and the master never carries its copy
      //   across exec nor past a stop
      $code = (string) file_get_contents((string) (new ReflectionClass(UDP_Server_CLI::class))->getFileName());
      $tokens = array_values(array_filter(
         token_get_all($code),
         static fn ($token): bool => is_array($token) === false
            || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
      ));
      /** The significant tokens of the default expression of `static bool $name`. */
      $Default = static function (string $name) use ($tokens): string {
         foreach ($tokens as $index => $token) {
            // ? The declaration `static bool $name =` — never a `static::$name` write
            if (
               is_array($token)
               && $token[0] === T_VARIABLE
               && $token[1] === "\${$name}"
               && is_array($tokens[$index - 1] ?? null)
               && $tokens[$index - 1][1] === 'bool'
               && is_array($tokens[$index - 2] ?? null)
               && $tokens[$index - 2][0] === T_STATIC
               && ($tokens[$index + 1] ?? null) === '='
            ) {
               $expression = '';
               for ($next = $index + 2; isSet($tokens[$next]) && $tokens[$next] !== ';'; $next++) {
                  $expression .= is_array($tokens[$next]) ? $tokens[$next][1] : $tokens[$next];
               }

               return $expression;
            }
         }

         return '';
      };
      /**
       * The argument lists (significant tokens, one string each) of every
       * call to $function inside method $method.
       *
       * @return array<int,string>
       */
      $Calls = static function (string $function, string $method) use ($tokens): array {
         $from = count($tokens);
         foreach ($tokens as $index => $token) {
            if (
               is_array($token) && $token[0] === T_FUNCTION
               && is_array($tokens[$index + 1] ?? null) && $tokens[$index + 1][1] === $method
            ) {
               $from = $index;
               break;
            }
         }
         // @ The method body: from its first brace to the matching one
         $to = $from;
         $depth = 0;
         for ($index = $from; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
               $depth++;
            }
            else if ($token === '}') {
               $depth--;
               if ($depth === 0) {
                  $to = $index;
                  break;
               }
            }
         }
         $calls = [];
         for ($index = $from; $index < $to; $index++) {
            $token = $tokens[$index];
            if (
               is_array($token) && $token[0] === T_STRING && $token[1] === $function
               && ($tokens[$index + 1] ?? null) === '('
            ) {
               $arguments = '';
               $depth = 0;
               for ($next = $index + 1; isSet($tokens[$next]); $next++) {
                  $part = $tokens[$next];
                  if ($part === '(') {
                     $depth++;
                     if ($depth === 1) {
                        continue;
                     }
                  }
                  else if ($part === ')') {
                     $depth--;
                     if ($depth === 0) {
                        break;
                     }
                  }
                  $arguments .= is_array($part) ? $part[1] : $part;
               }
               $calls[] = $arguments;
            }
         }

         return $calls;
      };
      $pins = [
         'default is the OS family' => (new ReflectionProperty(UDP_Server_CLI::class, 'shared'))->getDefaultValue()
            === (PHP_OS_FAMILY === 'Darwin'),
         'default expression' => $Default('shared'),
         // ? Every worker drains the one queue: a datagram a sibling took
         //   first ends the drain at once, never parks the worker
         'the shared socket is nonblocking' => in_array('$Socket,false', $Calls('stream_set_blocking', 'share'), true),
         'reload closes the master copy' => in_array('$this->Socket', $Calls('fclose', 'reload'), true),
         'stop closes the master copy' => in_array('$this->Socket', $Calls('fclose', 'stop'), true),
         // ? The Daemon launcher drops the copy it never serves
         'the launcher drops its copy' => in_array('$this->Socket', $Calls('fclose', 'detach'), true),
      ];
      yield new Assertion(description: 'the switch is PHP_OS_FAMILY === \'Darwin\' (never a procfs probe), the shared socket is nonblocking, and the master closes its copy on reload and stop, the launcher at once: ' . json_encode($pins, JSON_UNESCAPED_SLASHES))
         ->expect($pins, Op::Identical, [
            'default is the OS family' => true,
            'default expression' => "PHP_OS_FAMILY==='Darwin'",
            'the shared socket is nonblocking' => true,
            'reload closes the master copy' => true,
            'stop closes the master copy' => true,
            'the launcher drops its copy' => true,
         ])
         ->assert();

      // # Live legs
      // ! This run's own fixture logs and gap markers
      $directory = Temporaries::reserve('shared-socket');
      /** @var array<int,int> $ports every port this run used, for the state cleanup */
      $ports = [];
      /** @var array<int,array{0:mixed,1:int}> $sessions every launched [process, master] not yet torn down, by master */
      $sessions = [];

      /** A free loopback UDP port. */
      $Reserve = static function () use (&$ports): int {
         $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         $ports[] = $port;

         return $port;
      };
      /** Launch the embedded fixture: [process, master PID, log file, state prefix]. */
      $Launch = static function (string $leg, int $port) use ($directory, &$sessions): array {
         $state = "{$directory}/{$leg}.{$port}";
         $log = "{$state}.log";
         // ! A stop returns into the fixture (Foreground under CI), so it can
         //   report whether the master let its copy go
         $environment = (array) getenv();
         $environment['GITHUB_ACTIONS'] = 'true';
         $Process = proc_open(
            [
               PHP_BINARY, '-d', 'opcache.jit=0',
               __FILE__, '--udp-shared-probe', BOOTGLY_ROOT_BASE, (string) $port, $leg, $state,
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $environment,
         );
         $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         // ! Tracked until torn down: the outer finally reaps whatever a throw left
         if ($master > 0) {
            $sessions[$master] = [$Process, $master];
         }

         return [$Process, $master, $log, $state];
      };
      /** Tear one fixture session down: true when nothing of it is left. */
      $Teardown = static function (mixed $Process, int $master) use (&$sessions): bool {
         unset($sessions[$master]);
         // ! Its own session group; never PID 0/-1 (this runner's group)
         if ($master > 0) {
            @posix_kill(-$master, SIGCONT);
            @posix_kill(-$master, SIGKILL);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $cleanup = hrtime(true) + 1_000_000_000;
         while ($master > 0 && @posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }

         return $master > 0 && @posix_kill(-$master, 0) === false;
      };
      /** The fixture's exit code within $seconds, or null while it still runs. */
      $Settle = static function (mixed $Process, float $seconds): null|int {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $status = is_resource($Process) ? proc_get_status($Process) : ['running' => false, 'exitcode' => -1];
            if ($status['running'] === false) {
               return (int) $status['exitcode'];
            }
            usleep(20_000);
         } while (hrtime(true) < $deadline);

         return null;
      };
      /** A log, without its ANSI sequences. */
      $Read = static function (string $log): string {
         return (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', (string) @file_get_contents($log));
      };
      /** Whether $needle shows up in $log within $seconds. */
      $Find = static function (string $log, string $needle, float $seconds) use ($Read): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            if (str_contains($Read($log), $needle)) {
               return true;
            }
            usleep(20_000);
         } while (hrtime(true) < $deadline);

         return false;
      };
      /** Whether file $file exists within $seconds. */
      $Exist = static function (string $file, float $seconds): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            clearstatcache();
            if (is_file($file)) {
               return true;
            }
            usleep(10_000);
         } while (hrtime(true) < $deadline);

         return false;
      };
      /** Whether $PID exited within $seconds (a zombie its frozen master has not reaped yet counts). */
      $Dead = static function (int $PID, float $seconds): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $stat = (string) @file_get_contents("/proc/{$PID}/stat");
            $state = substr($stat, (int) strrpos($stat, ')') + 2, 1);
            if ($PID > 0 && ($stat === '' || $state === 'Z')) {
               return true;
            }
            usleep(10_000);
         } while (hrtime(true) < $deadline);

         return false;
      };

      /** A fresh client socket: a new source port, so a new peer to the server. */
      $Client = static function (): mixed {
         $Socket = @stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         if ($Socket !== false) {
            stream_set_blocking($Socket, false);
         }

         return $Socket;
      };
      /** Send one request datagram from $Socket to $port. */
      $Send = static function (mixed $Socket, int $port, string $request): bool {
         return is_resource($Socket)
            && @stream_socket_sendto($Socket, $request, 0, "127.0.0.1:{$port}") === strlen($request);
      };
      /**
       * The next "PID PPID" reply on $Socket within $seconds, and the
       * milliseconds it took: [PID, PPID, milliseconds] — PID 0 on none.
       *
       * @return array{0:int,1:int,2:int}
       */
      $Receive = static function (mixed $Socket, float $seconds): array {
         $start = hrtime(true);
         $deadline = $start + (int) ($seconds * 1e9);
         while (is_resource($Socket)) {
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
               break;
            }
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1_000)) !== 1) {
               continue;
            }
            $reply = @stream_socket_recvfrom($Socket, 65_535);
            if (is_string($reply) && preg_match('/^(\d+) (\d+)$/', $reply, $match) === 1) {
               return [(int) $match[1], (int) $match[2], intdiv(hrtime(true) - $start, 1_000_000)];
            }
         }

         return [0, 0, intdiv(hrtime(true) - $start, 1_000_000)];
      };
      /**
       * The first worker outside $old that answers on $port, child of $master,
       * within $seconds — or 0.
       *
       * @param array<int,int> $old
       */
      $Await = static function (int $port, int $master, array $old, float $seconds) use ($Client, $Send, $Receive): int {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $Socket = $Client();
            $Send($Socket, $port, 'pid');
            [$PID, $parent] = $Receive($Socket, 0.3);
            if (is_resource($Socket)) {
               fclose($Socket);
            }
            if ($PID > 0 && $parent === $master && in_array($PID, $old, true) === false) {
               return $PID;
            }
         } while (hrtime(true) < $deadline);

         return 0;
      };
      /**
       * Busy two workers with a held datagram each, then send a third one:
       * whether three distinct workers — none of $old, all children of
       * $master — answered, the third within $limit ms. Retried a few times
       * (a worker may still be booting): [PIDs, whether it held, evidence].
       *
       * @param array<int,int> $old
       *
       * @return array{0:array<int,int>,1:bool,2:array<string,mixed>}
       */
      $Spread = static function (int $port, int $master, array $old, int $limit) use ($Client, $Send, $Receive): array {
         $evidence = [];
         for ($attempt = 1; $attempt <= 3; $attempt++) {
            $Sockets = [$Client(), $Client(), $Client()];
            $Send($Sockets[0], $port, 'hold');
            usleep(100_000);
            $Send($Sockets[1], $port, 'hold');
            usleep(100_000);
            $Send($Sockets[2], $port, 'pid');
            [$third, $thirdParent, $milliseconds] = $Receive($Sockets[2], 2.0);
            [$first, $firstParent] = $Receive($Sockets[0], 2.0);
            [$second, $secondParent] = $Receive($Sockets[1], 2.0);
            foreach ($Sockets as $Socket) {
               if (is_resource($Socket)) {
                  fclose($Socket);
               }
            }
            $PIDs = [$first, $second, $third];
            $parents = [$firstParent, $secondParent, $thirdParent];
            $evidence = ['attempt' => $attempt, 'PIDs' => $PIDs, 'parents' => $parents, 'third ms' => $milliseconds];
            if (
               count(array_unique($PIDs)) === 3
               && in_array(0, $PIDs, true) === false
               && array_intersect($PIDs, $old) === []
               && array_unique($parents) === [$master]
               && $milliseconds < $limit
            ) {
               return [$PIDs, true, $evidence];
            }
            usleep(200_000);
         }

         return [[], false, $evidence];
      };
      /**
       * Whether another socket can bind $port beside the server: [a
       * SO_REUSEPORT joiner, an exclusive bind, a plain PHP bind — each
       * refused].
       *
       * @return array<string,bool>
       */
      $Exclusive = static function (int $port): array {
         $refused = [];
         foreach (['SO_REUSEPORT joiner' => true, 'exclusive bind' => false] as $kind => $reuse) {
            $Socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            $bound = false;
            if ($Socket !== false) {
               if ($reuse) {
                  @socket_set_option($Socket, SOL_SOCKET, SO_REUSEPORT, 1);
               }
               $bound = @socket_bind($Socket, '127.0.0.1', $port);
               socket_close($Socket);
            }
            $refused["{$kind} refused"] = $Socket !== false && $bound === false;
         }
         $Intruder = @stream_socket_server("udp://127.0.0.1:{$port}", $code, $message, STREAM_SERVER_BIND);
         $refused['plain bind refused'] = $Intruder === false;
         if ($Intruder !== false) {
            fclose($Intruder);
         }

         return $refused;
      };
      /** Whether an exclusive bind of $port succeeds within $seconds: nothing holds it any more. */
      $Bindable = static function (int $port, float $seconds): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $Socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            $bound = $Socket !== false && @socket_bind($Socket, '127.0.0.1', $port);
            if ($Socket !== false) {
               socket_close($Socket);
            }
            if ($bound) {
               return true;
            }
            usleep(20_000);
         } while (hrtime(true) < $deadline);

         return false;
      };
      /** Whether $log shows that the master ever lacked its socket, or ever reforked a worker. */
      $Lacked = static function (string $log) use ($Read): array {
         $output = $Read($log);

         return [
            'no worker found the master without its socket' => str_contains($output, 'the master holds no socket') === false,
            'the master never bound it again' => str_contains($output, 'bound again by the master') === false,
            'no worker reforked' => str_contains($output, 'recovered (new PID') === false,
         ];
      };

      // # Probe — Test mode, an ephemeral port, a start cancelled after its
      //   probe: each in its own session, in a script of its own
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   echo json_encode(['session' => false]) . PHP_EOL;
   exit(125);
}

require getenv('UDP_SHARED_AUTOBOOT');

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;

final class SharedModeProbe extends UDP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }

   // ! Each worker reports the address of the socket it reads
   public function instance ()
   {
      $Socket = parent::instance();
      $name = is_resource($Socket) ? (string) stream_socket_get_name($Socket, false) : '';
      file_put_contents((string) getenv('UDP_SHARED_NAMES'), "{$name}\n", FILE_APPEND | LOCK_EX);

      return $Socket;
   }

   // ! The start is cancelled once its probe kept the shared socket
   protected function detach (): void
   {
      throw new RuntimeException('cancelled after the probe');
   }
}

Display::show(Display::NONE);
// ! No Daemon fallback sink: nothing of this probe lands in storage/logs
Logger::$Sinks = new Handlers;
UDP_Server_CLI::$shared = true;

$leg = (string) getenv('UDP_SHARED_LEG');
$port = (int) getenv('UDP_SHARED_PORT');
$names = (string) getenv('UDP_SHARED_NAMES');
$Evidence = ['leg' => $leg, 'error' => ''];

/** The PID of the worker answering on $port within $seconds, or 0. */
$Ask = static function (int $port, float $seconds): int {
   $Client = @stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
   if ($Client === false) {
      return 0;
   }
   stream_set_blocking($Client, false);
   $PID = 0;
   $deadline = hrtime(true) + (int) ($seconds * 1e9);
   do {
      @stream_socket_sendto($Client, 'pid', 0, "127.0.0.1:{$port}");
      $read = [$Client];
      $write = null;
      $except = null;
      if (@stream_select($read, $write, $except, 0, 100_000) === 1) {
         $PID = (int) @stream_socket_recvfrom($Client, 65_535);
      }
   } while ($PID <= 0 && hrtime(true) < $deadline);
   fclose($Client);

   return $PID;
};
/** Whether an exclusive bind of $port succeeds: nothing holds it. */
$Free = static function (int $port): bool {
   $Socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
   $bound = $Socket !== false && @socket_bind($Socket, '127.0.0.1', $port);
   if ($Socket !== false) {
      socket_close($Socket);
   }

   return $bound;
};

try {
   $Server = new SharedModeProbe($leg === 'cancel' ? Modes::Daemon : Modes::Test);
   $Server->configure(new Configs(host: '127.0.0.1', port: $leg === 'zero' ? 0 : $port, workers: 2));
   $Server->on(Events::DatagramReceive, static fn (string $input): string => (string) getmypid());

   if ($leg === 'cancel') {
      try {
         $Server->start();
         $Evidence['cancelled after the probe'] = false;
      }
      catch (RuntimeException $Exception) {
         $Evidence['cancelled after the probe'] = $Exception->getMessage() === 'cancelled after the probe';
      }
      $Evidence['its socket closed'] = is_resource($Server->Socket) === false;
      $Evidence['the port free'] = $Free($port);
   }
   else {
      $Evidence['started'] = $Server->start();
      // ? Test mode never reforks: the workers alone keep the shared socket
      $Evidence['the master keeps no copy'] = is_resource($Server->Socket) === false;
      if ($leg === 'zero') {
         // @ Each worker reports the ephemeral port it reads: one for all
         $reported = [];
         $deadline = hrtime(true) + 3_000_000_000;
         do {
            usleep(10_000);
            $reported = array_values(array_filter(explode("\n", (string) @file_get_contents($names))));
         } while (count($reported) < 2 && hrtime(true) < $deadline);
         $bound = array_values(array_unique(array_map(
            static fn (string $name): int => (int) substr($name, (int) strrpos($name, ':') + 1),
            $reported
         )));
         $Evidence['both workers report'] = count($reported) === 2;
         $Evidence['one ephemeral port'] = count($bound) === 1 && $bound[0] > 0;
         $port = $bound[0] ?? 0;
      }
      $Evidence['a worker serves'] = $port > 0 && in_array($Ask($port, 3.0), $Server->Process->Children->PIDs, true);
      $Server->stop();
      $Evidence['the port free after the stop'] = $port > 0 && $Free($port);
   }
}
catch (Throwable $Throwable) {
   $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
}
echo json_encode($Evidence) . PHP_EOL;
PHP;
      /**
       * Run one probe leg of $Script on $port in its own session: its
       * evidence, and whether its group is gone.
       *
       * @return array{0:array<string,mixed>,1:bool}
       */
      $Probe = static function (string $leg, int $port) use ($Script, $directory): array {
         $environment = (array) getenv();
         $environment['UDP_SHARED_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $environment['UDP_SHARED_LEG'] = $leg;
         $environment['UDP_SHARED_PORT'] = (string) $port;
         $environment['UDP_SHARED_NAMES'] = "{$directory}/{$leg}.{$port}.names";
         $Process = proc_open(
            [PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'display_errors=stderr', '-r', $Script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "{$directory}/{$leg}.{$port}.err", 'a']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $environment,
         );
         $leader = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         $output = '';
         if (is_resource($Process)) {
            stream_set_blocking($Pipes[1], false);
            $deadline = hrtime(true) + 12_000_000_000;
            do {
               $output .= (string) stream_get_contents($Pipes[1]);
               if (proc_get_status($Process)['running'] === false) {
                  break;
               }
               usleep(20_000);
            } while (hrtime(true) < $deadline);
            $output .= (string) stream_get_contents($Pipes[1]);
            fclose($Pipes[1]);
         }
         // ! Its own session group: a worker left behind included
         if ($leader > 0) {
            @posix_kill(-$leader, SIGKILL);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $cleanup = hrtime(true) + 1_000_000_000;
         while ($leader > 0 && @posix_kill(-$leader, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }
         // ! The evidence line — a stop's own notice may precede it, colors included
         $evidence = ['output' => substr(trim($output), -400)];
         if (preg_match_all('/\{"leg":.*\}/', $output, $matches) > 0) {
            $decoded = json_decode((string) end($matches[0]), true);
            if (is_array($decoded)) {
               $evidence = $decoded;
            }
         }

         return [$evidence, $leader > 0 && @posix_kill(-$leader, 0) === false];
      };

      $shared = [];
      $gap = [];
      $probes = [];
      $evidence = [];
      // ! Whether every fixture session this run started is gone
      $groups = true;
      try {
         // # Serve — exclusive, kept from the start, spread with no peer
         //   affinity, reload, refork, every worker dead, stop
         $port = $Reserve();
         [$Process, $master, $log, $state] = $Launch('serve', $port);
         try {
            $first = $Await($port, $master, [], 8.0);
            $shared['serves'] = $first > 0;

            // @ (a) No socket joins or shares the port
            $shared['exclusive'] = $Exclusive($port);

            // @ (b) Two workers busy: an idle third reads the next datagram
            [$PIDs, $held, $evidence['spread']] = $Spread($port, $master, [], 400);
            $shared['three workers serve'] = $held;

            // @ (c) No peer affinity: while one worker serves a peer's held
            //   datagram, a sibling answers that same peer's next one
            $Peer = $Client();
            $Send($Peer, $port, 'hold');
            usleep(100_000);
            $Send($Peer, $port, 'pid');
            [$next, , $milliseconds] = $Receive($Peer, 2.0);
            [$holder] = $Receive($Peer, 2.0);
            if (is_resource($Peer)) {
               fclose($Peer);
            }
            $shared['one peer reaches two workers'] = $next > 0 && $holder > 0 && $next !== $holder && $milliseconds < 400;
            $evidence['affinity'] = ['next' => $next, 'holder' => $holder, 'next ms' => $milliseconds];

            // @ (d) Reload: the same master binds it again for fresh workers
            if ($master > 0 && $held) {
               posix_kill($master, SIGUSR2);
            }
            $reloaded = $held ? $Await($port, $master, $PIDs, 10.0) : 0;
            $shared['reloaded workers serve'] = $reloaded > 0;
            usleep(200_000);
            [$reloadedPIDs, $held, $evidence['reloaded spread']] = $Spread($port, $master, $PIDs, 400);
            $shared['three reloaded workers serve'] = $held;
            $shared['exclusive after the reload'] = $Exclusive($port);
            $shared['no reload gap'] = str_contains($Read($log), 'is held by another socket') === false;
            // ? Up to the first deliberate kill, the master held its socket
            //   from each start on: no worker ever found it missing, and no
            //   worker had to be reforked
            $shared['the master kept its socket'] = $Lacked($log);

            // @ (e) Refork: a killed worker's replacement inherits the socket
            $victim = $reloadedPIDs[0] ?? 0;
            if ($victim > 0) {
               posix_kill($victim, SIGKILL);
            }
            $replacement = $victim > 0 ? $Await($port, $master, $reloadedPIDs, 6.0) : 0;
            $shared['a killed worker is replaced'] = $replacement > 0;
            [$reforkedPIDs, $held, $evidence['reforked spread']] = $Spread($port, $master, [$victim], 400);
            $shared['three workers serve after the refork'] = $held && in_array($replacement, $reforkedPIDs, true);

            // @ (f) Every worker dead, the master frozen: the port never
            //   empties — the master's copy keeps it bound — and the reforks
            //   serve again on that same socket
            if ($master > 0 && $held) {
               posix_kill($master, SIGSTOP);
               foreach ($reforkedPIDs as $PID) {
                  posix_kill($PID, SIGKILL);
               }
            }
            $dead = $held;
            foreach ($reforkedPIDs as $PID) {
               $dead = $dead && $Dead($PID, 2.0);
            }
            $shared['every worker dead'] = $dead;
            $shared['exclusive with every worker dead'] = $Exclusive($port);
            if ($master > 0) {
               posix_kill($master, SIGCONT);
            }
            $revived = $Await($port, $master, $reforkedPIDs, 6.0);
            $shared['served again'] = $revived > 0;
            $output = $Read($log);
            $shared['never found without its socket'] = str_contains($output, 'the master holds no socket') === false
               && str_contains($output, 'bound again by the master') === false;
            $shared['master alive'] = is_resource($Process) && proc_get_status($Process)['running'];

            // @ (g) Stop: the master lets its copy go with its workers
            if ($master > 0) {
               posix_kill($master, SIGTERM);
            }
            $shared['a stop releases the socket'] = [
               'exit' => $Settle($Process, 8.0),
               'free in the stopped master' => trim((string) @file_get_contents("{$state}.stopped")),
               'bindable after' => $Bindable($port, 2.0),
            ];
         }
         finally {
            $groups = $Teardown($Process, $master) && $groups;
            $evidence['serve log'] = substr(trim($Read($log)), -600);
         }

         // # Gap — the replay finds the port taken: its workers back off
         //   until the master binds it again, exclusively
         $port = $Reserve();
         [$Process, $master, $log, $state] = $Launch('gap', $port);
         try {
            $original = $Await($port, $master, [], 8.0);
            $gap['serves'] = $original > 0;
            if ($master > 0 && $original > 0) {
               posix_kill($master, SIGUSR2);
            }
            $gap['replay squatter holds the port'] = $original > 0 && $Exist("{$state}.ready", 6.0);
            $gap['reload warning logged'] = $Find(
               $log,
               "Reload: UDP port {$port} is held by another socket; the workers retry until they can bind it.",
               3.0
            );
            $gap['worker refusal logged'] = $Find(
               $log,
               "Could not serve the UDP port {$port}: the master holds no socket.",
               3.0
            );
            $gap['master alive while the squatter holds'] = is_resource($Process) && proc_get_status($Process)['running'];
            $gap['squatter released'] = $Exist("{$state}.released", 5.0);
            $released = hrtime(true);
            $recovered = $Await($port, $master, [$original], 8.0);
            $gap['serves again within 6 s of the release'] = $recovered > 0
               && hrtime(true) - $released <= 6_000_000_000;
            $gap['the master bound the port again'] = $Find($log, "UDP port {$port} bound again by the master", 1.0);
            // ? Bound again exclusively: no account can join it after the gap
            $gap['exclusive after the regain'] = $Exclusive($port);
            $gap['master alive'] = is_resource($Process) && proc_get_status($Process)['running'];
         }
         finally {
            $groups = $Teardown($Process, $master) && $groups;
            $evidence['gap log'] = substr(trim($Read($log)), -600);
         }

         // # Probes — the branches a Linux default never takes
         // @ A Test-mode master keeps no copy, and the port is free once it stops
         $port = $Reserve();
         [$probes['test'], $clean] = $Probe('test', $port);
         $groups = $clean && $groups;
         // @ An ephemeral port is one port for every worker
         $ports[] = 0;
         [$probes['zero'], $clean] = $Probe('zero', 0);
         $groups = $clean && $groups;
         // @ A start cancelled after its probe kept the socket leaves the port free
         $port = $Reserve();
         [$probes['cancel'], $clean] = $Probe('cancel', $port);
         $groups = $clean && $groups;
      }
      finally {
         // @ Any session a throw left between its launch and its teardown
         foreach ($sessions as [$Process, $master]) {
            $Teardown($Process, $master);
            $groups = false;
         }
         // @ This run's state inodes, by their literal prefix
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            foreach ($ports as $used) {
               foreach (['SharedSocketProbe', 'SharedModeProbe'] as $class) {
                  if (str_starts_with((string) $file, "{$class}.{$used}.")) {
                     @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
                  }
               }
            }
         }
         foreach ((array) @scandir($directory) as $file) {
            if ($file !== '.' && $file !== '..') {
               @unlink("{$directory}/{$file}");
            }
         }
         @rmdir($directory);
      }

      $refused = [
         'SO_REUSEPORT joiner refused' => true,
         'exclusive bind refused' => true,
         'plain bind refused' => true,
      ];
      yield new Assertion(description: 'shared: one exclusive socket the master keeps from its start, read by every worker with no peer affinity, rebound across reload, inherited by a refork, bound with every worker dead, released by a stop: ' . json_encode($evidence, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES))
         ->expect($shared, Op::Identical, [
            'serves' => true,
            'exclusive' => $refused,
            'three workers serve' => true,
            'one peer reaches two workers' => true,
            'reloaded workers serve' => true,
            'three reloaded workers serve' => true,
            'exclusive after the reload' => $refused,
            'no reload gap' => true,
            'the master kept its socket' => [
               'no worker found the master without its socket' => true,
               'the master never bound it again' => true,
               'no worker reforked' => true,
            ],
            'a killed worker is replaced' => true,
            'three workers serve after the refork' => true,
            'every worker dead' => true,
            'exclusive with every worker dead' => $refused,
            'served again' => true,
            'never found without its socket' => true,
            'master alive' => true,
            'a stop releases the socket' => [
               'exit' => 0,
               'free in the stopped master' => 'free',
               'bindable after' => true,
            ],
         ])
         ->assert();

      yield new Assertion(description: 'gap: a reload that finds the port taken backs its workers off and serves once the master binds it again, exclusively: ' . json_encode($evidence, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES))
         ->expect($gap, Op::Identical, [
            'serves' => true,
            'replay squatter holds the port' => true,
            'reload warning logged' => true,
            'worker refusal logged' => true,
            'master alive while the squatter holds' => true,
            'squatter released' => true,
            'serves again within 6 s of the release' => true,
            'the master bound the port again' => true,
            'exclusive after the regain' => $refused,
            'master alive' => true,
         ])
         ->assert();

      yield new Assertion(description: 'probes: a Test-mode master keeps no copy, an ephemeral port is one port for every worker, a start cancelled after its probe leaves the port free: ' . json_encode($probes, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES))
         ->expect($probes, Op::Identical, [
            'test' => [
               'leg' => 'test',
               'error' => '',
               'started' => true,
               'the master keeps no copy' => true,
               'a worker serves' => true,
               'the port free after the stop' => true,
            ],
            'zero' => [
               'leg' => 'zero',
               'error' => '',
               'started' => true,
               'the master keeps no copy' => true,
               'both workers report' => true,
               'one ephemeral port' => true,
               'a worker serves' => true,
               'the port free after the stop' => true,
            ],
            'cancel' => [
               'leg' => 'cancel',
               'error' => '',
               'cancelled after the probe' => true,
               'its socket closed' => true,
               'the port free' => true,
            ],
         ])
         ->assert();

      yield new Assertion(description: 'every fixture session is gone')
         ->expect($groups, Op::Identical, true)
         ->assert();
   }),
);
