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
use Bootgly\ACI\Process;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Events;


// ! Embedded fixture: reload() replays this exact file through pcntl_exec,
//   so every image re-reads the listener mode and the worker count under
//   test from its state files before start() — a reload may change either.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--shared-listener-probe'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $port = (int) ($_SERVER['argv'][3] ?? 0);
   $state = (string) ($_SERVER['argv'][4] ?? '');
   $mode = trim((string) @file_get_contents("{$state}.mode"));
   $workers = (int) trim((string) @file_get_contents("{$state}.workers"));
   $host = trim((string) @file_get_contents("{$state}.host"));
   if (
      realpath("{$root}/autoboot.php") === false
      || $port < 1024 || $port > 65535
      || in_array($mode, ['shared', 'pooled'], true) === false
      || $workers < 1 || $workers > 4
      // ! TEST-NET-1: an address no host of this spec holds (EADDRNOTAVAIL)
      || in_array($host, ['127.0.0.1', '192.0.2.1'], true) === false
   ) {
      exit(2);
   }
   // ? First launch only — the replayed image already leads its session
   @posix_setsid();
   // ! exec keeps the PID: this is the master's in every image
   $master = (int) getmypid();

   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";

   // ! The listener mode under test — the Darwin path when shared
   TCP_Server_CLI::$shared = $mode === 'shared';

   // ! A spec-only class: its state inodes are this spec's alone to remove
   final class SharedListenerProbe extends TCP_Server_CLI
   {
      public static function boot (mixed $Environment): void
      {
      }
   }

   // ! The reload relay — the master's one child that is no worker — held
   //   past its exit while the spec asks: the fresh master must kill it
   register_shutdown_function(static function () use ($master, $state): void {
      if (getmypid() !== $master && Process::$index === 0 && is_file("{$state}.stall")) {
         file_put_contents("{$state}.relay", (string) getmypid());
         usleep(3_000_000);
      }
   });

   $Server = new SharedListenerProbe(Modes::Foreground);
   $Server->configure(new Configs(host: $host, port: $port, workers: $workers));
   $Server->on(Events::DataReceive, static function ($input): string {
      // @ Busy this worker: its event loop serves nothing else meanwhile
      if (trim((string) $input) === 'hold') {
         usleep(800_000);
      }

      $PID = getmypid();
      $parent = posix_getppid();

      return "{$PID} {$parent}";
   });
   $Server->start();

   // @ A stop under CI returns here (Foreground): whether the master let its
   //   listener go — an exclusive bind of the port proves it
   $Released = @stream_socket_server("tcp://127.0.0.1:{$port}", $code, $message);
   file_put_contents("{$state}.stopped", $Released === false ? "held: {$message}" : 'free');
   exit(0);
}


/**
 * Darwin's SO_REUSEPORT does not balance — XNU routes every connection to the
 * last socket bound — so there every worker accepts on ONE listener the master
 * binds exclusively (`TCP_Server_CLI::$shared`, forced here on Linux). That
 * listener refuses any joiner; more than one worker serves; a worker that loses
 * an accept race to a sibling keeps serving its own connections at once; a
 * reload hands the one listener to the fresh workers of the same master (whose
 * fresh image kills a relay that outstays its exit), a refork inherits it, and
 * a stop lets it go. A reload from a pooled image — its SO_REUSEPORT listeners
 * another account could join on Darwin — fails closed. The same accept keeps a
 * pooled reload that raises the worker count (its workers then share the
 * listeners it carried) from stalling either.
 *
 * What proves what: the nonblocking listener and the accept timeout 0 are each
 * pinned in the source. The live race legs go red only when both are reverted
 * — a loser's poll() normally runs before the winner's accept(), so either
 * half alone seldom stalls. The accept-miss legs prove a race a sibling won is
 * no connection error, while an accept that fails (EMFILE) is one.
 */
return new Test(
   description: 'Darwin shared listener (forced): exclusive, spread over the workers, never stalled by a lost accept, kept across reload and refork, released by a stop, never taken over from a pooled image',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('posix_setrlimit') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      // # Pins — the switch is derived from the OS family alone, the accept
      //   path every mode shares never waits, the relay reap needs no procfs,
      //   and the launcher never keeps a listener it does not serve
      /** The source code of the file declaring $class. */
      $Source = static function (string $class): string {
         $file = (string) (new ReflectionClass($class))->getFileName();

         return (string) file_get_contents($file);
      };
      /**
       * The significant tokens of the default expression of the static
       * property $name, as one string ('' when it is not declared so).
       */
      $Default = static function (string $code, string $name): string {
         $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($token): bool => is_array($token) === false
               || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
         ));
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
       * call to $function — inside method $method only, when given.
       *
       * @return array<int,string>
       */
      $Calls = static function (string $code, string $function, null|string $method = null): array {
         $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($token): bool => is_array($token) === false
               || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
         ));
         $from = 0;
         $to = count($tokens);
         if ($method !== null) {
            $from = $to;
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

      $server = $Source(TCP_Server_CLI::class);
      $connections = $Source(Connections::class);
      $pins = [
         'default is the OS family' => (new ReflectionProperty(TCP_Server_CLI::class, 'shared'))->getDefaultValue()
            === (PHP_OS_FAMILY === 'Darwin'),
         'default expression' => $Default($server, 'shared'),
         'accept timeout' => $Calls($connections, 'stream_socket_accept', 'connect'),
         'nonblocking listener' => in_array('$Listener,false', $Calls($server, 'stream_set_blocking', 'prepare'), true),
         // ? The relay reap proves its child by waitpid alone — no procfs
         //   read — and kills the one that outstays its exit
         'relay reap reads no procfs' => $Calls($server, 'file_get_contents', 'receive'),
         'relay reap kills' => $Calls($server, 'posix_kill', 'receive'),
         // ? The Daemon launcher drops the listener it never serves
         'launcher drops its listeners' => in_array('$Listener', $Calls($server, 'fclose', 'detach'), true),
      ];
      yield new Assertion(description: 'the switch is PHP_OS_FAMILY === \'Darwin\' (never a procfs probe), every listener is accepted nonblocking with timeout 0, the relay reap needs no procfs, and the launcher keeps no listener: ' . json_encode($pins, JSON_UNESCAPED_SLASHES))
         ->expect($pins, Op::Identical, [
            'default is the OS family' => true,
            'default expression' => "PHP_OS_FAMILY==='Darwin'",
            'accept timeout' => ['$this->Server->Socket,0'],
            'nonblocking listener' => true,
            'relay reap reads no procfs' => [],
            'relay reap kills' => ['$PID,SIGKILL'],
            'launcher drops its listeners' => true,
         ])
         ->assert();

      // # Accept misses — a race a sibling won is no connection error, in
      //   every mode; an accept that fails (EMFILE) is one
      $accept = [];
      $segments = Display::$segments;
      $errors = Connections::$errors;
      $limits = posix_getrlimit();
      $soft = ($limits['soft openfiles'] ?? 'unlimited') === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $limits['soft openfiles'];
      $hard = ($limits['hard openfiles'] ?? 'unlimited') === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $limits['hard openfiles'];
      $Listener = false;
      $Client = false;
      $Fillers = [];
      try {
         Display::show(Display::NONE);
         $Probe = new TCP_Server_CLI(Modes::Test);
         $Listener = @stream_socket_server('tcp://127.0.0.1:0', $code, $message);
         if ($Listener !== false) {
            stream_set_blocking($Listener, false);
            (new ReflectionProperty(TCP_Server_CLI::class, 'Socket'))->setValue($Probe, $Listener);
         }
         Connections::$errors = ['connection' => 0, 'read' => 0, 'write' => 0];
         // @ Nothing pending: what a worker that lost the race finds
         $accept['a miss accepts nothing'] = $Listener !== false && $Probe->Connections->connect() === false;
         $accept['a miss is no connection error'] = Connections::$errors['connection'] === 0;
         // @ One connection pending, and no descriptor left for it: EMFILE
         $Client = $Listener !== false
            ? @stream_socket_client('tcp://' . stream_socket_get_name($Listener, false), $code, $message, 1.0)
            : false;
         usleep(20_000);
         $cap = $soft === POSIX_RLIMIT_INFINITY || $soft > 2048 ? 2048 : $soft;
         $exhausted = false;
         if (posix_setrlimit(POSIX_RLIMIT_NOFILE, $cap, $hard)) {
            while (count($Fillers) < 4096) {
               $Filler = @fopen('/dev/null', 'r');
               if ($Filler === false) {
                  $exhausted = true;
                  break;
               }
               $Fillers[] = $Filler;
            }
         }
         // ? Only once no descriptor is left: a real accept must never run here
         $accept['EMFILE accepts nothing'] = $exhausted && $Client !== false && $Probe->Connections->connect() === false;
         $accept['EMFILE is a connection error'] = Connections::$errors['connection'] === 1;
      }
      finally {
         foreach ($Fillers as $Filler) {
            fclose($Filler);
         }
         posix_setrlimit(POSIX_RLIMIT_NOFILE, $soft, $hard);
         if (is_resource($Client)) {
            fclose($Client);
         }
         if (is_resource($Listener)) {
            fclose($Listener);
         }
         Connections::$errors = $errors;
         Display::show($segments);
      }
      yield new Assertion(description: 'an accept a sibling won is no connection error; one that fails (EMFILE) is: ' . json_encode($accept))
         ->expect($accept, Op::Identical, [
            'a miss accepts nothing' => true,
            'a miss is no connection error' => true,
            'EMFILE accepts nothing' => true,
            'EMFILE is a connection error' => true,
         ])
         ->assert();

      // # Live legs
      // ! This run's own fixture state: mode, worker count, log, relay marks
      $directory = Temporaries::reserve('shared-listener');
      /** @var array<int,int> $ports every port this run used, for the state cleanup */
      $ports = [];
      /** @var array<int,array{0:mixed,1:int}> $sessions every launched [process, master] not yet torn down, by master */
      $sessions = [];

      /** A free loopback TCP port. */
      $Reserve = static function () use (&$ports): int {
         $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         $ports[] = $port;

         return $port;
      };
      /** Launch the embedded fixture: [process, master PID, log file, state prefix]. */
      $Launch = static function (
         string $mode, int $port, int $workers, array $INI = [], string $host = '127.0.0.1'
      ) use ($directory, &$sessions): array {
         $nonce = bin2hex(random_bytes(3));
         $state = "{$directory}/{$mode}.{$port}.{$nonce}";
         file_put_contents("{$state}.mode", $mode);
         file_put_contents("{$state}.workers", (string) $workers);
         file_put_contents("{$state}.host", $host);
         $log = "{$state}.log";
         // ! A stop returns into the fixture (Foreground under CI), so it can
         //   report whether the master let its listener go
         $environment = (array) getenv();
         $environment['GITHUB_ACTIONS'] = 'true';
         $Process = proc_open(
            [
               PHP_BINARY, '-d', 'opcache.jit=0', ...$INI,
               __FILE__, '--shared-listener-probe', BOOTGLY_ROOT_BASE, (string) $port, $state,
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
      /** A log, without its ANSI sequences: its tail, for the evidence. */
      $Tail = static function (string $log): string {
         $text = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', (string) @file_get_contents($log));

         return substr(trim($text), -400);
      };

      /** A client connected to $port, or false. */
      $Connect = static function (int $port): mixed {
         return @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 1.0);
      };
      /** Send one request on $Client. */
      $Send = static function (mixed $Client, string $request): bool {
         return is_resource($Client) && @fwrite($Client, $request) === strlen($request);
      };
      /**
       * The next "PID PPID" answer on $Client within $seconds, and the
       * milliseconds it took: [PID, PPID, milliseconds] — PID 0 on none.
       *
       * @return array{0:int,1:int,2:int}
       */
      $Receive = static function (mixed $Client, float $seconds): array {
         $start = hrtime(true);
         $deadline = $start + (int) ($seconds * 1e9);
         $answer = '';
         while (is_resource($Client) && preg_match('/^\d+ \d+$/', $answer) !== 1) {
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
               break;
            }
            $read = [$Client];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1_000));
            if ($selected !== 1) {
               continue;
            }
            $chunk = @fread($Client, 64);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $answer .= $chunk;
         }
         $milliseconds = intdiv(hrtime(true) - $start, 1_000_000);
         if (preg_match('/^(\d+) (\d+)$/', $answer, $match) === 1) {
            return [(int) $match[1], (int) $match[2], $milliseconds];
         }

         return [0, 0, $milliseconds];
      };
      /** Close every client of $Clients. */
      $Close = static function (array $Clients): void {
         foreach ($Clients as $Client) {
            if (is_resource($Client)) {
               fclose($Client);
            }
         }
      };
      /**
       * The first worker outside $old that answers on $port, child of $master,
       * within $seconds — or 0.
       *
       * @param array<int,int> $old
       */
      $Await = static function (int $port, int $master, array $old, float $seconds) use ($Connect, $Send, $Receive): int {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $Client = $Connect($port);
            if ($Client !== false) {
               $Send($Client, 'pid');
               [$PID, $parent] = $Receive($Client, 0.5);
               fclose($Client);
               if ($PID > 0 && $parent === $master && in_array($PID, $old, true) === false) {
                  return $PID;
               }
            }
            usleep(50_000);
         } while (hrtime(true) < $deadline);

         return 0;
      };
      /**
       * Busy two workers with a held request each, then ask a third client:
       * [its three connections (kept open), the PID each answered, the
       * parent each named, the milliseconds the third took].
       *
       * @return array{0:array<int,mixed>,1:array<int,int>,2:array<int,int>,3:int}
       */
      $Spread = static function (int $port) use ($Connect, $Send, $Receive): array {
         $Clients = [];
         $Clients[0] = $Connect($port);
         $Send($Clients[0], 'hold');
         usleep(100_000);
         $Clients[1] = $Connect($port);
         $Send($Clients[1], 'hold');
         usleep(100_000);
         $Clients[2] = $Connect($port);
         $Send($Clients[2], 'pid');
         [$third, $thirdParent, $milliseconds] = $Receive($Clients[2], 2.0);
         [$first, $firstParent] = $Receive($Clients[0], 2.0);
         [$second, $secondParent] = $Receive($Clients[1], 2.0);

         return [$Clients, [$first, $second, $third], [$firstParent, $secondParent, $thirdParent], $milliseconds];
      };
      /**
       * Whether the spread over $port shows three distinct workers — none of
       * $old, all children of $master — and the third answered within
       * $limit ms. Retried a few times (a worker may still be booting):
       * [held connections, PIDs, whether it held, evidence].
       *
       * @param array<int,int> $old
       *
       * @return array{0:array<int,mixed>,1:array<int,int>,2:bool,3:array<string,mixed>}
       */
      $Distinct = static function (int $port, int $master, array $old, int $limit) use ($Spread, $Close): array {
         $evidence = [];
         for ($attempt = 1; $attempt <= 3; $attempt++) {
            [$Clients, $PIDs, $parents, $milliseconds] = $Spread($port);
            $evidence = ['attempt' => $attempt, 'PIDs' => $PIDs, 'parents' => $parents, 'third ms' => $milliseconds];
            $held = count(array_unique($PIDs)) === 3
               && in_array(0, $PIDs, true) === false
               && array_intersect($PIDs, $old) === []
               && array_unique($parents) === [$master]
               && $milliseconds < $limit;
            if ($held) {
               return [$Clients, $PIDs, true, $evidence];
            }
            $Close($Clients);
            usleep(200_000);
         }

         return [[], [], false, $evidence];
      };
      /**
       * Race every worker for one new connection, then ask each held
       * connection again: [the PID each answered, the slowest milliseconds,
       * the PID that answered the newcomer].
       *
       * @param array<int,mixed> $Clients
       *
       * @return array{0:array<int,int>,1:int,2:int}
       */
      $Race = static function (int $port, array $Clients) use ($Connect, $Send, $Receive): array {
         usleep(100_000);
         $Newcomer = $Connect($port);
         usleep(100_000);
         $answers = [];
         $slowest = 0;
         foreach ($Clients as $index => $Client) {
            $Send($Client, 'pid');
            [$PID, , $milliseconds] = $Receive($Client, 1.0);
            $answers[$index] = $PID;
            $slowest = max($slowest, $milliseconds);
         }
         $Send($Newcomer, 'pid');
         [$newcomer] = $Receive($Newcomer, 2.0);
         if (is_resource($Newcomer)) {
            fclose($Newcomer);
         }

         return [$answers, $slowest, $newcomer];
      };
      /**
       * Whether a listener can bind $port beside the server: [a SO_REUSEPORT
       * joiner refused, a plain listener refused].
       *
       * @return array<string,bool>
       */
      $Exclusive = static function (int $port): array {
         $refused = [];
         foreach (['SO_REUSEPORT joiner' => ['so_reuseport' => true], 'plain listener' => []] as $kind => $options) {
            $Intruder = @stream_socket_server(
               "tcp://127.0.0.1:{$port}",
               $code,
               $message,
               STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
               stream_context_create(['socket' => $options])
            );
            $refused["{$kind} refused"] = $Intruder === false;
            if ($Intruder !== false) {
               fclose($Intruder);
            }
         }

         return $refused;
      };
      /** Whether a plain listener binds $port within $seconds: nothing holds it any more. */
      $Bindable = static function (int $port, float $seconds): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $Listener = @stream_socket_server("tcp://127.0.0.1:{$port}", $code, $message);
            if ($Listener !== false) {
               fclose($Listener);

               return true;
            }
            usleep(20_000);
         } while (hrtime(true) < $deadline);

         return false;
      };

      $shared = [];
      $guard = [];
      $pooled = [];
      $upgrade = [];
      $evidence = [];
      // ! Whether every fixture session this run started is gone
      $groups = true;
      try {
         // # Shared — the Darwin path, forced
         $port = $Reserve();
         [$Process, $master, $log, $state] = $Launch('shared', $port, 3);
         $Clients = [];
         try {
            $first = $Await($port, $master, [], 8.0);
            $shared['serves'] = $first > 0;

            // @ (a) Exclusive: no joiner binds beside the shared listener
            $shared['exclusive'] = $Exclusive($port);
            // @ ... and the same instance relaunched on it keeps the lock's
            //   own message, though its exclusive probe is refused first
            [$Relaunch, $relaunched, $relaunch] = $Launch('shared', $port, 3);
            $exit = $Settle($Relaunch, 6.0);
            $groups = $Teardown($Relaunch, $relaunched) && $groups;
            $shared['a relaunch is refused as the running instance'] = [
               'exit' => $exit,
               'message' => str_contains($Read($relaunch), "Another instance is already running on port {$port}."),
            ];
            $evidence['relaunch log'] = $Tail($relaunch);
            // @ ... while the same instance refused for another reason — an
            //   address this host lacks (EADDRNOTAVAIL) — keeps its own words
            $Unassigned = @stream_socket_server('tcp://192.0.2.1:0', $code, $message);
            $assignable = $Unassigned !== false;
            if ($Unassigned !== false) {
               fclose($Unassigned);
            }
            [$Elsewhere, $elsewhereMaster, $elsewhere] = $Launch('shared', $port, 3, host: '192.0.2.1');
            $exit = $Settle($Elsewhere, 6.0);
            $groups = $Teardown($Elsewhere, $elsewhereMaster) && $groups;
            $output = $Read($elsewhere);
            $shared['a refusal for another reason keeps its own words'] = [
               'host lacks 192.0.2.1' => $assignable === false,
               'exit' => $exit,
               'the bind error' => str_contains($output, "Could not bind to 192.0.2.1:{$port}:"),
               'never "already running"' => str_contains($output, 'Another instance is already running') === false,
            ];
            $evidence['elsewhere log'] = $Tail($elsewhere);

            // @ (b) More than one worker serves: two held, a third answers
            [$Clients, $PIDs, $held, $evidence['spread']] = $Distinct($port, $master, [], 400);
            $shared['three workers serve'] = $held;

            // @ (c) The workers that lose an accept race keep serving at once
            [$answers, $slowest, $newcomer] = $Race($port, $Clients);
            $shared['race losers answer their own connections'] = $held && $answers === $PIDs;
            $shared['within 300 ms'] = $held && $slowest < 300;
            $shared['the newcomer is served'] = in_array($newcomer, $PIDs, true);
            $evidence['race'] = ['answers' => $answers, 'slowest ms' => $slowest, 'newcomer' => $newcomer];
            $Close($Clients);
            $Clients = [];

            // @ (d) Reload: the same master hands the one listener to fresh
            //   workers — its relay held past its exit, so the fresh image
            //   kills and reaps it once its 2 s grace runs out
            touch("{$state}.stall");
            $reloading = hrtime(true);
            if ($master > 0 && $held) {
               posix_kill($master, SIGUSR2);
            }
            $reloaded = $held ? $Await($port, $master, $PIDs, 10.0) : 0;
            $shared['reloaded workers serve'] = $reloaded > 0;
            $relay = (int) @file_get_contents("{$state}.relay");
            $shared['a relay that outstays its exit is killed and reaped'] = [
               'held past its exit' => $relay > 0,
               'gone once the fresh workers serve' => $relay > 0 && $reloaded > 0 && @posix_kill($relay, 0) === false,
               'after the 2 s grace' => $reloaded > 0 && hrtime(true) - $reloading >= 2_000_000_000,
            ];
            @unlink("{$state}.stall");
            usleep(200_000);
            [$Clients, $reloadedPIDs, $held, $evidence['reloaded spread']] = $Distinct($port, $master, $PIDs, 400);
            $shared['three reloaded workers serve'] = $held;
            $shared['exclusive after the reload'] = $Exclusive($port);
            $Close($Clients);
            $Clients = [];

            // @ (e) Refork: a killed worker's replacement inherits the listener
            $victim = $reloadedPIDs[0] ?? 0;
            if ($victim > 0) {
               posix_kill($victim, SIGKILL);
            }
            $replacement = $victim > 0 ? $Await($port, $master, $reloadedPIDs, 6.0) : 0;
            $shared['a killed worker is replaced'] = $replacement > 0;
            [$Clients, $reforkedPIDs, $held, $evidence['reforked spread']] = $Distinct($port, $master, [$victim], 400);
            $shared['three workers serve after the refork'] = $held && in_array($replacement, $reforkedPIDs, true);
            $Close($Clients);
            $Clients = [];
            $shared['master alive'] = is_resource($Process) && proc_get_status($Process)['running'];

            // @ (f) Stop: the master lets the one listener go with its workers
            if ($master > 0) {
               posix_kill($master, SIGTERM);
            }
            $shared['a stop releases the listener'] = [
               'exit' => $Settle($Process, 8.0),
               'free in the stopped master' => trim((string) @file_get_contents("{$state}.stopped")),
               'bindable after' => $Bindable($port, 2.0),
            ];
         }
         finally {
            $Close($Clients);
            $groups = $Teardown($Process, $master) && $groups;
            $evidence['shared log'] = $Tail($log);
         }

         // # Guard — a hand-off socket path sun_path cannot hold whole
         //   (Darwin: 103 bytes): the reload aborts before draining anything,
         //   and the same workers serve on
         $temporary = "{$directory}/" . str_repeat('t', 64);
         mkdir($temporary, 0700);
         $port = $Reserve();
         [$Process, $master, $log] = $Launch('shared', $port, 3, ['-d', "sys_temp_dir={$temporary}"]);
         $Clients = [];
         try {
            $first = $Await($port, $master, [], 8.0);
            $guard['serves'] = $first > 0;
            [$Clients, $PIDs, $held, $evidence['guard spread']] = $Distinct($port, $master, [], 400);
            $Close($Clients);
            $Clients = [];
            if ($master > 0 && $held) {
               posix_kill($master, SIGUSR2);
            }
            $guard['reload aborted on the path length'] = $held && $Find(
               $log,
               'Reload aborted: the hand-off socket path exceeds the 103-byte AF_UNIX limit',
               4.0
            );
            [$Clients, $keptPIDs, $held, $evidence['guard kept spread']] = $Distinct($port, $master, [], 400);
            sort($PIDs);
            sort($keptPIDs);
            $guard['the same workers serve on'] = $held && $keptPIDs === $PIDs;
            $guard['nothing created in the temporary directory'] = array_values(array_diff((array) @scandir($temporary), ['.', '..'])) === [];
            $guard['master alive'] = is_resource($Process) && proc_get_status($Process)['running'];
         }
         finally {
            $Close($Clients);
            $groups = $Teardown($Process, $master) && $groups;
            $evidence['guard log'] = $Tail($log);
            @rmdir($temporary);
         }

         // # Pooled — a reload raising the worker count from 1 to 3: its
         //   workers share the one listener it carried (D2)
         $port = $Reserve();
         [$Process, $master, $log, $state] = $Launch('pooled', $port, 1);
         $Clients = [];
         try {
            $single = $Await($port, $master, [], 8.0);
            $pooled['serves'] = $single > 0;
            file_put_contents("{$state}.workers", '3');
            if ($master > 0 && $single > 0) {
               posix_kill($master, SIGUSR2);
            }
            $raised = $single > 0 ? $Await($port, $master, [$single], 10.0) : 0;
            $pooled['reloaded workers serve'] = $raised > 0;
            usleep(200_000);
            [$Clients, $PIDs, $held, $evidence['pooled spread']] = $Distinct($port, $master, [$single], 400);
            $pooled['three workers share the carried listener'] = $held;
            [$answers, $slowest, $newcomer] = $Race($port, $Clients);
            $pooled['race losers answer their own connections'] = $held && $answers === $PIDs;
            $pooled['within 300 ms'] = $held && $slowest < 300;
            $pooled['the newcomer is served'] = in_array($newcomer, $PIDs, true);
            $evidence['pooled race'] = ['answers' => $answers, 'slowest ms' => $slowest, 'newcomer' => $newcomer];
         }
         finally {
            $Close($Clients);
            $groups = $Teardown($Process, $master) && $groups;
            $evidence['pooled log'] = $Tail($log);
         }

         // # Upgrade — a pooled image reloaded into a shared one hands over
         //   SO_REUSEPORT listeners, which on Darwin another account could
         //   bind beside and take every connection from: the fresh image
         //   refuses to keep them — the hand-off fails closed
         $port = $Reserve();
         [$Process, $master, $log, $state] = $Launch('pooled', $port, 3);
         try {
            $first = $Await($port, $master, [], 8.0);
            $upgrade['serves'] = $first > 0;
            file_put_contents("{$state}.mode", 'shared');
            if ($master > 0 && $first > 0) {
               posix_kill($master, SIGUSR2);
            }
            $upgrade['the reloaded master exits'] = $first > 0 ? $Settle($Process, 10.0) : null;
            $upgrade['the refusal names SO_REUSEPORT'] = str_contains(
               $Read($log),
               'Reload aborted: the listener handed over carries SO_REUSEPORT (a pooled image), which a shared listener never keeps'
            );
            $upgrade['nothing serves the port after'] = $Bindable($port, 3.0);
         }
         finally {
            $groups = $Teardown($Process, $master) && $groups;
            $evidence['upgrade log'] = $Tail($log);
         }
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
               if (str_starts_with((string) $file, "SharedListenerProbe.{$used}.")) {
                  @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
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

      yield new Assertion(description: 'shared: an exclusive listener spread over three workers, never stalled by a lost accept, kept across reload and refork, released by a stop: ' . json_encode($evidence, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES))
         ->expect($shared, Op::Identical, [
            'serves' => true,
            'exclusive' => ['SO_REUSEPORT joiner refused' => true, 'plain listener refused' => true],
            'a relaunch is refused as the running instance' => ['exit' => 1, 'message' => true],
            'a refusal for another reason keeps its own words' => [
               'host lacks 192.0.2.1' => true,
               'exit' => 1,
               'the bind error' => true,
               'never "already running"' => true,
            ],
            'three workers serve' => true,
            'race losers answer their own connections' => true,
            'within 300 ms' => true,
            'the newcomer is served' => true,
            'reloaded workers serve' => true,
            'a relay that outstays its exit is killed and reaped' => [
               'held past its exit' => true,
               'gone once the fresh workers serve' => true,
               'after the 2 s grace' => true,
            ],
            'three reloaded workers serve' => true,
            'exclusive after the reload' => ['SO_REUSEPORT joiner refused' => true, 'plain listener refused' => true],
            'a killed worker is replaced' => true,
            'three workers serve after the refork' => true,
            'master alive' => true,
            'a stop releases the listener' => [
               'exit' => 0,
               'free in the stopped master' => 'free',
               'bindable after' => true,
            ],
         ])
         ->assert();

      yield new Assertion(description: 'guard: a reload whose hand-off socket path exceeds sun_path aborts with the same workers serving: ' . json_encode($evidence, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES))
         ->expect($guard, Op::Identical, [
            'serves' => true,
            'reload aborted on the path length' => true,
            'the same workers serve on' => true,
            'nothing created in the temporary directory' => true,
            'master alive' => true,
         ])
         ->assert();

      yield new Assertion(description: 'pooled: a reload raising the workers from 1 to 3 never stalls the ones sharing the carried listener: ' . json_encode($evidence, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES))
         ->expect($pooled, Op::Identical, [
            'serves' => true,
            'reloaded workers serve' => true,
            'three workers share the carried listener' => true,
            'race losers answer their own connections' => true,
            'within 300 ms' => true,
            'the newcomer is served' => true,
         ])
         ->assert();

      yield new Assertion(description: 'upgrade: a pooled image reloaded into a shared one never keeps its SO_REUSEPORT listeners — the hand-off fails closed: ' . json_encode($evidence, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES))
         ->expect($upgrade, Op::Identical, [
            'serves' => true,
            'the reloaded master exits' => 1,
            'the refusal names SO_REUSEPORT' => true,
            'nothing serves the port after' => true,
         ])
         ->assert();

      yield new Assertion(description: 'every fixture session is gone')
         ->expect($groups, Op::Identical, true)
         ->assert();
   }),
);
