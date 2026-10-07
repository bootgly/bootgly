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
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;


// ! Embedded fixture: the spec relaunches this exact file as a server in its
//   own session — `pin` reports the worker socket options (and O_CLOEXEC)
//   of instance(), `serve` starts a Foreground server that answers
//   "<input> <worker PID>".
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--udp-exclusivity-probe'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $mode = (string) ($_SERVER['argv'][3] ?? '');
   $class = (string) ($_SERVER['argv'][4] ?? '');
   $host = (string) ($_SERVER['argv'][5] ?? '');
   $port = (int) ($_SERVER['argv'][6] ?? 0);
   $workers = (int) ($_SERVER['argv'][7] ?? 0);
   if (
      realpath("{$root}/autoboot.php") === false
      || in_array($mode, ['pin', 'serve'], true) === false
      || in_array($class, ['ExclusivityProbe', 'ExclusivityRival'], true) === false
      || (
         in_array($host, ['127.0.0.1', '0.0.0.0', '[::]', 'localhost'], true) === false
         // ? Or one scoped IPv6 link-local host (L8)
         && preg_match('/^\[fe80:[0-9a-f:]+%[A-Za-z0-9_.-]+\]$/', $host) !== 1
      )
      // ? Below 1024 only for the privileged-port refusal (L9)
      || $port < 1 || $port > 65535
      || $workers < 1 || $workers > 2
   ) {
      exit(2);
   }
   $session = posix_setsid();
   if ($session === false || $session === -1) {
      exit(125);
   }

   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";

   // ! Spec-only classes: two State identities whose inodes are this spec's
   //   alone to remove
   final class ExclusivityProbe extends UDP_Server_CLI
   {
      public static function boot (mixed $Environment): void
      {
      }
   }
   final class ExclusivityRival extends UDP_Server_CLI
   {
      public static function boot (mixed $Environment): void
      {
      }
   }

   $Server = new $class(Modes::Foreground);
   $Server->configure(new Configs(host: $host, port: $port, workers: $workers));

   // ? pin: the options of the socket one worker binds
   if ($mode === 'pin') {
      $Stream = $Server->instance();
      $Socket = is_resource($Stream) ? socket_import_stream($Stream) : false;
      if ($Socket === false) {
         exit(3);
      }
      $options = [
         'SO_REUSEADDR' => socket_get_option($Socket, SOL_SOCKET, SO_REUSEADDR),
         'SO_REUSEPORT' => socket_get_option($Socket, SOL_SOCKET, SO_REUSEPORT),
      ];
      if ($host === '[::]') {
         $options['IPV6_V6ONLY'] = socket_get_option($Socket, IPPROTO_IPV6, IPV6_V6ONLY);
      }
      // ! O_CLOEXEC: every descriptor of this socket — found by its inode
      //   among /proc/self/fd — and none may be missing
      $inode = (int) (fstat($Stream)['ino'] ?? 0);
      $descriptors = [];
      foreach ((array) @scandir('/proc/self/fd') as $descriptor) {
         if ($inode > 0 && @readlink("/proc/self/fd/{$descriptor}") === "socket:[{$inode}]") {
            $information = (string) @file_get_contents("/proc/self/fdinfo/{$descriptor}");
            $descriptors[] = preg_match('/^flags:\s+([0-7]+)$/m', $information, $match) === 1
               && (octdec($match[1]) & 0o2000000) !== 0;
         }
      }
      $options['O_CLOEXEC'] = $descriptors !== [] && in_array(false, $descriptors, true) === false;
      $JSON = json_encode($options);
      echo "PIN {$JSON}\n";
      exit(0);
   }

   $Server->on(Events::DatagramReceive, static function (string $input): string {
      $PID = getmypid();

      return "{$input} {$PID}";
   });
   $Server->start();
   exit(0);
}


/**
 * PHP binds every UDP stream with SO_REUSEADDR, and Linux lets two such
 * sockets share an address with no uid check. The server binds its workers
 * without it (close-on-exec, zone kept) and probes the port exclusively at
 * start, so no other socket can share the port in either order — and only
 * that refusal names another socket.
 */
return new Test(
   description: 'UDP-22: a UDP server binds its port exclusively — no foreign bind beside it, no start on top of one',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      // ! This run's own logs
      $temporary = sys_get_temp_dir();
      $PID = getmypid();
      $nonce = bin2hex(random_bytes(4));
      $directory = "{$temporary}/bootgly-udp-exclusivity-{$PID}-{$nonce}";
      mkdir($directory, 0700);
      $pids = BOOTGLY_STORAGE_DIR . 'pids';
      /** @var array<int,int> $ports every port this run used, for the state cleanup */
      $ports = [];
      /** @var array<int,array{0:mixed,1:int}> $sessions every launched [process, session leader] not yet torn down, by leader */
      $sessions = [];

      /** A port free on both loopback families. */
      $Reserve = static function () use (&$ports): int {
         $Context = stream_context_create(['socket' => ['ipv6_v6only' => false]]);
         $Reservation = @stream_socket_server('udp://[::]:0', $code, $message, STREAM_SERVER_BIND, $Context);
         if ($Reservation === false) {
            $Reservation = stream_socket_server('udp://0.0.0.0:0', $code, $message, STREAM_SERVER_BIND);
         }
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         $ports[] = $port;

         return $port;
      };
      /** Launch the embedded fixture: [process, session leader PID, log file]. */
      $Launch = static function (
         string $mode, string $class, string $host, int $port, int $workers, array $INI = []
      ) use ($directory, &$sessions): array {
         $nonce = bin2hex(random_bytes(3));
         $log = "{$directory}/{$mode}.{$class}.{$port}.{$nonce}.log";
         $Process = proc_open(
            [
               PHP_BINARY, '-d', 'opcache.jit=0', ...$INI,
               __FILE__, '--udp-exclusivity-probe', BOOTGLY_ROOT_BASE,
               $mode, $class, $host, (string) $port, (string) $workers,
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
         );

         $leader = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         // ! Tracked until torn down: the outer finally reaps whatever a throw left
         if ($leader > 0) {
            $sessions[$leader] = [$Process, $leader];
         }

         return [$Process, $leader, $log];
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
      /** Tear down the session this spec started: true when nothing is left. */
      $Teardown = static function (mixed $Process, int $leader) use (&$sessions): bool {
         unset($sessions[$leader]);
         // ? Never PID 0/-1 (this runner's group)
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

         return $leader > 0 && @posix_kill(-$leader, 0) === false;
      };
      /** A log, without its ANSI sequences. */
      $Text = static function (string $log): string {
         return (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', (string) @file_get_contents($log));
      };
      /** The PIDs of the workers answering on $port, polled until $count answer or $seconds pass. */
      $Serve = static function (int $port, float $seconds, int $count = 1): array {
         $answering = [];
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            // ! A fresh source port per ping: SO_REUSEPORT spreads them over the workers
            $Client = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
            stream_set_blocking($Client, false);
            stream_socket_sendto($Client, 'ping', 0, "127.0.0.1:{$port}");
            $Ready = [$Client];
            $Write = null;
            $Except = null;
            if (@stream_select($Ready, $Write, $Except, 0, 100_000) === 1) {
               while (($reply = @stream_socket_recvfrom($Client, 65_535)) !== false && $reply !== '') {
                  if (preg_match('/^ping (\d+)$/', $reply, $match) === 1) {
                     $answering[(int) $match[1]] = true;
                  }
               }
            }
            fclose($Client);
         } while (count($answering) < $count && hrtime(true) < $deadline);

         return array_keys($answering);
      };
      /** The files under storage/pids. */
      $List = static function () use ($pids): array {
         clearstatcache();
         $files = array_values(array_diff((array) @scandir($pids), ['.', '..']));
         sort($files);

         return $files;
      };

      $observed = [];
      $expected = [];
      $evidence = [];
      // ! Whether every fixture session this run started is gone
      $groups = true;
      try {
         // @ L1 — a worker socket carries SO_REUSEPORT and never SO_REUSEADDR,
         //   and closes on exec: a process a handler starts never inherits it
         foreach (['127.0.0.1', '[::]'] as $host) {
            [$Process, $leader, $log] = $Launch('pin', 'ExclusivityProbe', $host, $Reserve(), 1);
            $exit = $Settle($Process, 5.0);
            $groups = $Teardown($Process, $leader) && $groups;
            $output = $Text($log);
            $options = preg_match('/^PIN (\{.*\})$/m', $output, $match) === 1
               ? json_decode($match[1], true)
               : ['exit' => $exit, 'output' => substr($output, -300)];
            $observed["L1 worker options on {$host}"] = $options;
            $expected["L1 worker options on {$host}"] = $host === '[::]'
               ? ['SO_REUSEADDR' => 0, 'SO_REUSEPORT' => 1, 'IPV6_V6ONLY' => 0, 'O_CLOEXEC' => true]
               : ['SO_REUSEADDR' => 0, 'SO_REUSEPORT' => 1, 'O_CLOEXEC' => true];
         }

         // @ L2 — a running server: plain same-uid binds on its port are refused
         $port = $Reserve();
         [$Server, $master, $log] = $Launch('serve', 'ExclusivityProbe', '0.0.0.0', $port, 2);
         try {
            $serving = $Serve($port, 6.0, 2);
            $observed['L2 both workers serve'] = count($serving) === 2;
            $expected['L2 both workers serve'] = true;

            // ! The refusal is decided by errno, never by the locale's strerror
            //   text: stream_socket_server() reports a refused bind with code 0
            //   and that text only, so a twin bind with the stream layer's own
            //   option (SO_REUSEADDR) through ext-sockets yields the errno
            $inuse = extension_loaded('sockets') ? SOCKET_EADDRINUSE : 98;
            $Intruders = [];
            $Twins = [];
            foreach (['127.0.0.1', '0.0.0.0'] as $IP) {
               $code = 0;
               $message = '';
               $Intruder = @stream_socket_server("udp://{$IP}:{$port}", $code, $message, STREAM_SERVER_BIND);
               if ($Intruder !== false) {
                  stream_set_blocking($Intruder, false);
                  $Intruders[] = $Intruder;
               }

               $errno = -1;
               $Twin = function_exists('socket_create') ? @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP) : false;
               if ($Twin !== false) {
                  @socket_set_option($Twin, SOL_SOCKET, SO_REUSEADDR, 1);
                  if (@socket_bind($Twin, $IP, $port)) {
                     $errno = 0;
                     socket_set_nonblock($Twin);
                     $Twins[] = $Twin;
                  }
                  else {
                     $errno = socket_last_error($Twin);
                     socket_close($Twin);
                  }
               }

               $observed["L2 plain bind on {$IP} refused"] = ['stream' => $Intruder === false, 'errno' => $errno];
               $expected["L2 plain bind on {$IP} refused"] = ['stream' => true, 'errno' => $inuse];
               $evidence["L2 bind {$IP}"] = $Intruder === false ? "code {$code}: {$message}" : 'bound';
            }

            // @@ 20 datagrams while the binds were tried (and held, if taken)
            $Client = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
            stream_set_blocking($Client, false);
            for ($index = 0; $index < 20; $index++) {
               stream_socket_sendto($Client, "datagram-{$index}", 0, "127.0.0.1:{$port}");
            }
            $answered = [];
            $deadline = hrtime(true) + 2_000_000_000;
            while (count($answered) < 20 && hrtime(true) < $deadline) {
               $Ready = [$Client];
               $Write = null;
               $Except = null;
               if (@stream_select($Ready, $Write, $Except, 0, 50_000) === 1) {
                  while (($reply = @stream_socket_recvfrom($Client, 65_535)) !== false && $reply !== '') {
                     if (preg_match('/^(datagram-\d+) \d+$/', $reply, $match) === 1) {
                        $answered[$match[1]] = true;
                     }
                  }
               }
            }
            fclose($Client);
            $captured = 0;
            foreach ($Intruders as $Intruder) {
               while (($datagram = @stream_socket_recvfrom($Intruder, 65_535)) !== false && $datagram !== '') {
                  $captured++;
               }
               fclose($Intruder);
            }
            foreach ($Twins as $Twin) {
               while (@socket_recvfrom($Twin, $datagram, 65_535, 0, $from, $origin) > 0) {
                  $captured++;
               }
               socket_close($Twin);
            }
            $observed['L2 answered by the server'] = count($answered);
            $expected['L2 answered by the server'] = 20;
            $observed['L2 captured by the binds'] = $captured;
            $expected['L2 captured by the binds'] = 0;

            // @ L4 — the same project relaunched on its port keeps its message
            [$Process, $leader, $relaunched] = $Launch('serve', 'ExclusivityProbe', '0.0.0.0', $port, 1);
            $exit = $Settle($Process, 4.0);
            $groups = $Teardown($Process, $leader) && $groups;
            $output = $Text($relaunched);
            $observed['L4 relaunch'] = [
               'exit' => $exit,
               'message' => str_contains($output, "Another instance is already running on port {$port}."),
            ];
            $expected['L4 relaunch'] = ['exit' => 1, 'message' => true];
            $evidence['L4 output'] = substr(trim($output), 0, 240);

            // @ L5 — another identity on the same port is refused, never joins
            [$Process, $leader, $rival] = $Launch('serve', 'ExclusivityRival', '0.0.0.0', $port, 1);
            $exit = $Settle($Process, 4.0);
            $groups = $Teardown($Process, $leader) && $groups;
            $output = $Text($rival);
            $observed['L5 rival identity'] = [
               'exit' => $exit,
               'message' => str_contains($output, "Could not bind to 0.0.0.0:{$port} exclusively")
                  && str_contains($output, 'holds this UDP port'),
            ];
            $expected['L5 rival identity'] = ['exit' => 1, 'message' => true];
            $evidence['L5 output'] = substr(trim($output), 0, 240);

            // ? The server outlived every refused attempt
            $observed['L2 server still serves'] = $Serve($port, 3.0) !== [];
            $expected['L2 server still serves'] = true;
         }
         finally {
            $observed['L2 group clean'] = $Teardown($Server, $master);
            $expected['L2 group clean'] = true;
         }

         // @ L3 — a squatter bound first makes the launch refuse, creating nothing
         $port = $Reserve();
         $Squatter = stream_socket_server("udp://127.0.0.1:{$port}", $code, $message, STREAM_SERVER_BIND);
         try {
            $before = $List();
            [$Process, $leader, $log] = $Launch('serve', 'ExclusivityProbe', '127.0.0.1', $port, 1);
            $exit = $Settle($Process, 4.0);
            $after = $List();
            $groups = $Teardown($Process, $leader) && $groups;
            $output = $Text($log);
            $observed['L3 squatter first'] = [
               'squatter bound' => is_resource($Squatter),
               'exit' => $exit,
               'message' => str_contains($output, "Could not bind to 127.0.0.1:{$port} exclusively")
                  && str_contains($output, 'holds this UDP port'),
               'new state files' => array_values(array_diff($after, $before)),
            ];
            $expected['L3 squatter first'] = [
               'squatter bound' => true,
               'exit' => 1,
               'message' => true,
               'new state files' => [],
            ];
            $evidence['L3 output'] = substr(trim($output), 0, 240);

            // @ L10 — an EADDRINUSE refusal names the other socket: the start
            //   probe (L3 above) and a worker bind on the same squatted port
            [$Process, $leader, $log] = $Launch('pin', 'ExclusivityProbe', '127.0.0.1', $port, 1);
            $exit = $Settle($Process, 4.0);
            $groups = $Teardown($Process, $leader) && $groups;
            $worker = $Text($log);
            $observed['L10 EADDRINUSE wording'] = [
               'start' => str_contains($output, "Another socket — possibly another account's — holds this UDP port."),
               'worker exit' => $exit,
               'worker' => str_contains($worker, "Could not bind the UDP port {$port}: ")
                  && str_contains($worker, ' — another socket holds it.'),
            ];
            $expected['L10 EADDRINUSE wording'] = ['start' => true, 'worker exit' => 1, 'worker' => true];
            $evidence['L10 worker output'] = substr(trim($worker), 0, 240);
         }
         finally {
            if (is_resource($Squatter)) {
               fclose($Squatter);
            }
         }

         // @ L6 — 'localhost' resolves once: a squatter on its first family
         //   refuses the start instead of landing on the other family
         // ! The only allowed skip: 'localhost' reaches a single loopback
         //   family — getaddrinfo() lists one, or one cannot be bound on
         //   this host (IPv6 off) — computed here and asserted, never assumed
         $resolved = [];
         $Lookup = function_exists('socket_addrinfo_lookup')
            ? @socket_addrinfo_lookup('localhost', null, ['ai_socktype' => SOCK_DGRAM])
            : false;
         foreach (is_array($Lookup) ? $Lookup : [] as $Info) {
            $explained = socket_addrinfo_explain($Info);
            $resolved[] = (string) ($explained['ai_addr']['sin_addr'] ?? $explained['ai_addr']['sin6_addr'] ?? '');
         }
         $resolved = array_values(array_unique($resolved));
         sort($resolved);
         $bindable = [];
         foreach (['127.0.0.1' => 'udp://127.0.0.1:0', '::1' => 'udp://[::1]:0'] as $loopback => $URI) {
            $Loopback = @stream_socket_server($URI, $code, $message, STREAM_SERVER_BIND);
            if (is_resource($Loopback)) {
               $bindable[] = $loopback;
               fclose($Loopback);
            }
         }
         $dual = array_values(array_intersect(['127.0.0.1', '::1'], $resolved, $bindable)) === ['127.0.0.1', '::1'];
         $evidence['L6 families'] = [
            'resolves' => $resolved,
            'bindable' => $bindable,
         ];
         $ran = false;
         $port = $Reserve();
         $Resolution = @stream_socket_server('udp://localhost:0', $code, $message, STREAM_SERVER_BIND);
         $name = is_resource($Resolution) ? (string) stream_socket_get_name($Resolution, false) : '';
         if (is_resource($Resolution)) {
            fclose($Resolution);
         }
         $first = substr($name, 0, (int) strrpos($name, ':'));
         $family = str_starts_with($first, '[') ? AF_INET6 : AF_INET;
         $IP = trim($first, '[]');
         $Bare = function_exists('socket_create') && $first !== ''
            ? @socket_create($family, SOCK_DGRAM, SOL_UDP)
            : false;
         try {
            $bare = $Bare !== false && @socket_bind($Bare, $IP, $port);
            // ? Precondition: a host:port bind does land on the other family
            $Other = $bare ? @stream_socket_server("udp://localhost:{$port}", $code, $message, STREAM_SERVER_BIND) : false;
            $other = is_resource($Other) ? (string) stream_socket_get_name($Other, false) : '';
            if (is_resource($Other)) {
               fclose($Other);
            }
            $landing = substr($other, 0, (int) strrpos($other, ':'));
            if ($bare === false || $landing === '' || $landing === $first) {
               $evidence['L6'] = "not run: localhost does not land on both loopback families (first: '{$first}', other: '{$landing}')";
            }
            else {
               $ran = true;
               [$Process, $leader, $log] = $Launch('serve', 'ExclusivityProbe', 'localhost', $port, 1);
               $exit = $Settle($Process, 4.0);
               $groups = $Teardown($Process, $leader) && $groups;
               $output = $Text($log);
               $observed['L6 localhost resolves once'] = [
                  'exit' => $exit,
                  'message' => str_contains($output, "Could not bind to {$first}:{$port} exclusively"),
               ];
               $expected['L6 localhost resolves once'] = ['exit' => 1, 'message' => true];
               $evidence['L6'] = "first {$first}, a host:port bind lands on {$landing}";
               $evidence['L6 output'] = substr(trim($output), 0, 240);
            }
         }
         finally {
            if ($Bare !== false) {
               socket_close($Bare);
            }
         }
         $observed['L6 ran'] = [
            'localhost resolves' => $resolved,
            'loopbacks bindable' => $bindable,
            'ran' => $ran,
         ];
         $expected['L6 ran'] = [
            'localhost resolves' => $resolved,
            'loopbacks bindable' => $bindable,
            // ? Skipped only when 'localhost' cannot reach both families
            'ran' => $dual,
         ];

         // @ L7 — without ext-sockets the launch refuses with the requirement
         $disabled = [
            '-d', 'disable_functions=socket_create,socket_bind,socket_set_option,socket_export_stream,socket_import_stream',
         ];
         $port = $Reserve();
         [$Process, $leader, $log] = $Launch('serve', 'ExclusivityProbe', '127.0.0.1', $port, 1, $disabled);
         $exit = $Settle($Process, 4.0);
         $groups = $Teardown($Process, $leader) && $groups;
         $output = $Text($log);
         $observed['L7 without ext-sockets'] = [
            'exit' => $exit,
            'message' => str_contains($output, 'UDP_Server_CLI needs ext-sockets to bind its port exclusively'),
         ];
         $expected['L7 without ext-sockets'] = ['exit' => 1, 'message' => true];
         $evidence['L7 output'] = substr(trim($output), 0, 240);

         // @ L7b — instance() called directly (no start() before it) refuses
         //   the same way: its own guard, not start()'s
         $port = $Reserve();
         [$Process, $leader, $log] = $Launch('pin', 'ExclusivityProbe', '127.0.0.1', $port, 1, $disabled);
         $exit = $Settle($Process, 4.0);
         $groups = $Teardown($Process, $leader) && $groups;
         $output = $Text($log);
         $observed['L7b instance() without ext-sockets'] = [
            'exit' => $exit,
            'message' => str_contains($output, 'UDP_Server_CLI needs ext-sockets to bind its port exclusively'),
         ];
         $expected['L7b instance() without ext-sockets'] = ['exit' => 1, 'message' => true];
         $evidence['L7b output'] = substr(trim($output), 0, 240);

         // @ L8 — a scoped IPv6 link-local host keeps its zone: the server
         //   starts on "[fe80::…%<interface>]" and serves a datagram sent there
         // ! Precondition: an interface holds a usable link-local address —
         //   read from /proc/net/if_inet6 and asserted, never assumed
         $links = [];
         $table = @file('/proc/net/if_inet6', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
         foreach (is_array($table) ? $table : [] as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (is_array($fields) === false || count($fields) !== 6) {
               continue;
            }
            [$hexadecimal, $index, , $scope, $flags, $interface] = $fields;
            // ? Link scope (0x20), neither tentative (0x40) nor DAD-failed (0x08)
            if (hexdec($scope) !== 0x20 || (hexdec($flags) & 0x48) !== 0) {
               continue;
            }
            $address = @inet_ntop((string) hex2bin($hexadecimal));
            if (is_string($address) && preg_match('/^[A-Za-z0-9_.-]+$/', $interface) === 1) {
               $links[] = [(int) hexdec($index), $address, $interface];
            }
         }
         // ! The lowest interface index: a physical link outlives a container's veth
         sort($links);
         $ran = false;
         if ($links !== []) {
            $ran = true;
            [, $address, $interface] = $links[0];
            $scoped = "{$address}%{$interface}";
            $port = $Reserve();
            [$Server, $master, $log] = $Launch('serve', 'ExclusivityProbe', "[{$scoped}]", $port, 1);
            $answered = false;
            $Client = function_exists('socket_create') ? @socket_create(AF_INET6, SOCK_DGRAM, SOL_UDP) : false;
            try {
               // @@ Ping the scoped address until a worker answers, the server exits or 6 s pass
               $deadline = hrtime(true) + 6_000_000_000;
               $bound = $Client !== false && @socket_bind($Client, $scoped, 0);
               while ($bound && $answered === false && hrtime(true) < $deadline && $Settle($Server, 0.0) === null) {
                  @socket_sendto($Client, 'ping', 4, 0, $scoped, $port);
                  $Ready = [$Client];
                  $Write = null;
                  $Except = null;
                  if (@socket_select($Ready, $Write, $Except, 0, 100_000) === 1) {
                     while (@socket_recvfrom($Client, $reply, 65_535, MSG_DONTWAIT, $from, $origin) > 0) {
                        $answered = $answered || preg_match('/^ping \d+$/', (string) $reply) === 1;
                     }
                  }
               }
               $exit = $Settle($Server, 0.0);
               $observed['L8 zone host serves'] = [
                  'client bound' => $bound,
                  'running' => $exit === null,
                  'answered' => $answered,
               ];
               $expected['L8 zone host serves'] = ['client bound' => true, 'running' => true, 'answered' => true];
            }
            finally {
               if ($Client !== false) {
                  socket_close($Client);
               }
               $groups = $Teardown($Server, $master) && $groups;
            }
            $status = $exit === null ? 'running' : "exit {$exit}";
            $evidence['L8'] = "host [{$scoped}], {$status}";
            $evidence['L8 output'] = substr(trim($Text($log)), 0, 240);
         }
         else {
            $evidence['L8'] = 'not run: /proc/net/if_inet6 lists no usable link-local (scope 0x20) address';
         }
         $observed['L8 ran'] = [
            'link-local addresses' => count($links),
            'ran' => $ran,
         ];
         $expected['L8 ran'] = [
            'link-local addresses' => count($links),
            // ? Skipped only without a link-local address on this host
            'ran' => $links !== [],
         ];

         // @ L9 — a non-root launch below the unprivileged port floor is
         //   refused by errno 13 with the privileges hint, never with the
         //   "another socket" wording (EADDRINUSE only)
         // ! Precondition: this account cannot bind a privileged port — not
         //   root, a floor above 1 (the kernel's 1024 without the sysctl), and
         //   no CAP_NET_BIND_SERVICE (bit 10) — computed and asserted
         $sysctl = @file_get_contents('/proc/sys/net/ipv4/ip_unprivileged_port_start');
         $floor = $sysctl === false ? 1024 : (int) trim($sysctl);
         $superuser = posix_geteuid() === 0;
         $capable = preg_match('/^CapEff:\s+([0-9a-f]+)$/m', (string) @file_get_contents('/proc/self/status'), $match) === 1
            && (hexdec(substr($match[1], -4)) & (1 << 10)) !== 0;
         $privileged = $superuser === false && $floor > 1 && $capable === false;
         $ran = false;
         if ($privileged) {
            $ran = true;
            $port = $floor - 1;
            $ports[] = $port;
            [$Process, $leader, $log] = $Launch('serve', 'ExclusivityProbe', '127.0.0.1', $port, 1);
            $start = $Settle($Process, 4.0);
            $groups = $Teardown($Process, $leader) && $groups;
            $output = $Text($log);
            [$Process, $leader, $log] = $Launch('pin', 'ExclusivityProbe', '127.0.0.1', $port, 1);
            $exit = $Settle($Process, 4.0);
            $groups = $Teardown($Process, $leader) && $groups;
            $worker = $Text($log);
            $observed['L9 errno 13 refusal'] = [
               'start exit' => $start,
               'start hint' => str_contains($output, 'Ports below 1024 require elevated privileges'),
               // @ L10 — the errno 13 refusal never names another socket
               'start names another socket' => str_contains(strtolower($output), 'another socket'),
               'worker exit' => $exit,
               'worker message' => str_contains($worker, "Could not bind the UDP port {$port}: "),
               'worker names another socket' => str_contains(strtolower($worker), 'another socket'),
            ];
            $expected['L9 errno 13 refusal'] = [
               'start exit' => 1,
               'start hint' => true,
               'start names another socket' => false,
               'worker exit' => 1,
               'worker message' => true,
               'worker names another socket' => false,
            ];
            $evidence['L9'] = "port {$port} below the floor {$floor}";
            $evidence['L9 output'] = substr(trim($output), 0, 240);
            $evidence['L9 worker output'] = substr(trim($worker), 0, 240);
         }
         else {
            $reason = match (true) {
               $superuser => 'root',
               $capable => 'CAP_NET_BIND_SERVICE',
               default => "port floor {$floor}",
            };
            $evidence['L9'] = "not run: this account may bind every port ({$reason})";
         }
         $observed['L9 ran'] = [
            'root' => $superuser,
            'port floor' => $floor,
            'CAP_NET_BIND_SERVICE' => $capable,
            'ran' => $ran,
         ];
         $expected['L9 ran'] = [
            'root' => $superuser,
            'port floor' => $floor,
            'CAP_NET_BIND_SERVICE' => $capable,
            // ? Skipped only when this account may bind every port
            'ran' => $privileged,
         ];
      }
      finally {
         // @ Any session a throw left between its launch and its teardown
         foreach ($sessions as [$Process, $leader]) {
            $Teardown($Process, $leader);
            $groups = false;
         }
         // @ This run's state inodes, by their literal prefix
         foreach ($List() as $file) {
            foreach ($ports as $used) {
               if (
                  str_starts_with((string) $file, "ExclusivityProbe.{$used}.")
                  || str_starts_with((string) $file, "ExclusivityRival.{$used}.")
               ) {
                  @unlink("{$pids}/{$file}");
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

      $observed['every fixture session gone'] = $groups;
      $expected['every fixture session gone'] = true;

      yield new Assertion(
         description: 'no socket shares the server port, and start() never lands on one: ' . json_encode($evidence)
      )
         ->expect($observed, Op::Identical, $expected)
         ->assert();
   }),
);
