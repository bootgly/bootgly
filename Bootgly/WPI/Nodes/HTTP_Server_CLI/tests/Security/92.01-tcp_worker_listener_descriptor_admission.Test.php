<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Process;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC L3 (2026-08-01 worker listener admission) — a worker must not
 * enter its event loop after Select rejects its inherited listening socket.
 *
 * The isolated probe opens a real low-descriptor TCP listener, retains file
 * handles until a second real TCP listener is proven unrepresentable by this
 * process' stream_select backend, and then forks one real server worker for
 * each listener. The instrumented selector delegates to the production
 * Select::add() and Select::loop() methods and reports only their boundaries.
 */
$probe = [
   'fixture_error' => '',
   'child' => [],
   'source' => [],
   'discovery' => [],
   'control' => [],
   'legs' => [],
];

if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--l3-listener-probe'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $base = rtrim((string) ($_SERVER['argv'][3] ?? ''), '/');
   if (
      $root === ''
      || is_dir($root) === false
      || $base === ''
      || is_dir($base) === false
   ) {
      fwrite(STDERR, "L3 probe received an invalid root or fixture path.\n");
      exit(2);
   }

   define('BOOTGLY_STORAGE_BASE', $base . '/storage');
   define('BOOTGLY_STORAGE_DIR', $base . '/storage/');
   putenv('BOOTGLY_ENVIRONMENT=production');
   $_SERVER['SCRIPT_FILENAME'] = '';
   require $root . '/autoboot.php';

   final class L3Select extends Select
   {
      /** @var resource */
      private mixed $writer;


      /** @param resource $writer */
      public function __construct (Connections &$Connections, mixed $writer)
      {
         parent::__construct($Connections);

         $this->writer = $writer;
      }

      /** Emit one bounded evidence record to the parent process. */
      public function mark (string $stage, array $evidence = []): void
      {
         $record = json_encode(
            ['stage' => $stage, 'pid' => posix_getpid()] + $evidence,
            JSON_UNESCAPED_SLASHES,
         );
         if (is_string($record)) {
            @fwrite($this->writer, $record . "\n");
            @fflush($this->writer);
         }
      }

      public function add ($Socket, int $flag, mixed $payload): bool
      {
         $result = parent::add($Socket, $flag, $payload);
         $resourceID = (int) $Socket;
         $this->mark('add', [
            'resource_id' => $resourceID,
            'flag' => $flag,
            'result' => $result,
            'retained' => isset($this->reads[$resourceID]),
         ]);

         return $result;
      }

      public function loop (): void
      {
         $this->mark('loop_enter');
         try {
            parent::loop();
         }
         catch (Throwable $Throwable) {
            $this->mark('loop_error', [
               'error' => $Throwable::class . ': ' . $Throwable->getMessage(),
            ]);
            throw $Throwable;
         }
         finally {
            $this->mark('loop_leave');
         }
      }
   }

   final class L3Server extends HTTP_Server_CLI
   {
      /**
       * Fork one genuine work() child with an already-bound listener.
       *
       * @param resource $Listener
       */
      public function spawn ($Listener, L3Select $Selector): int
      {
         $this->Listeners = [$Listener];
         self::$Event = $Selector;
         if (isset(self::$context) === false) {
            // open() normally initializes this before the production fork;
            // the fixture supplies an externally-bound equivalent listener.
            self::$context = [];
         }
         $this->Process->Signals->install([SIGALRM]);
         pcntl_signal(SIGPIPE, SIG_IGN, false);

         $this->Process->fork(
            1,
            function (Process $Process, int $index) use ($Selector): void {
               try {
                  $this->work($Process, $index);
               }
               catch (Throwable $Throwable) {
                  $Selector->mark('worker_error', [
                     'error' => $Throwable::class . ': '
                        . $Throwable->getMessage(),
                  ]);
                  exit(70);
               }
            },
         );

         $PIDs = $this->Process->Children->PIDs;
         $PID = end($PIDs);
         if (is_int($PID) === false || $PID <= 0) {
            throw new RuntimeException('L3 could not identify its worker PID.');
         }

         return $PID;
      }

      /** Run the complete master startup path on one exact free port. */
      public function launch (int $port, L3Select $Selector): void
      {
         self::$Event = $Selector;
         $this->configure(
            host: '127.0.0.1',
            port: $port,
            workers: 1,
         );

         $started = $this->start();
         $Selector->mark('start_returned', [
            'result' => $started,
            'children' => count($this->Process->Children->PIDs),
         ]);

         // Give a vulnerable worker's bounded selector timer time to expose
         // its loop entry before the fixture tears the master down.
         usleep(100000);
         $this->stop();
      }

      /** Forget an exactly reaped fixture child. */
      public function forget (int $PID): void
      {
         $this->Process->Children->remove($PID);
      }

      /** Release parent-side listener references before fixture cleanup. */
      public function release (): void
      {
         $this->Listeners = [];
      }

      /** The isolated startup probe needs no application bootstrap. */
      protected function loading (): void
      {
         // ...
      }

      protected function wire (int $index): void
      {
         parent::wire($index);

         if (self::$Event instanceof L3Select) {
            self::$Event->mark('wire', [
               'index' => $index,
               'resource_id' => (int) $this->Socket,
            ]);
         }
      }
   }

   /** @var array<int,resource> $fillers */
   $fillers = [];
   $lowListener = null;
   $highListener = null;
   $Server = null;

   /**
    * Create and exercise one real TCP listener without leaving a pending peer.
    *
    * @return array{listener:resource,address:string,connected:bool}
    */
   $Listen = static function (): array {
      $code = 0;
      $message = '';
      $Listener = stream_socket_server(
         'tcp://127.0.0.1:0',
         $code,
         $message,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
      );
      if ($Listener === false) {
         throw new RuntimeException(
            "L3 could not bind a TCP listener ({$code}): {$message}",
         );
      }

      $address = stream_socket_get_name($Listener, false);
      if (is_string($address) === false || $address === '') {
         fclose($Listener);
         throw new RuntimeException('L3 could not resolve its bound listener.');
      }

      $clientCode = 0;
      $clientMessage = '';
      $Client = @stream_socket_client(
         'tcp://' . $address,
         $clientCode,
         $clientMessage,
         0.5,
      );
      $Accepted = is_resource($Client)
         ? @stream_socket_accept($Listener, 0.5)
         : false;
      $connected = is_resource($Client) && is_resource($Accepted);
      if (is_resource($Accepted)) {
         fclose($Accepted);
      }
      if (is_resource($Client)) {
         fclose($Client);
      }
      if ($connected === false) {
         fclose($Listener);
         throw new RuntimeException(
            "L3 listener did not accept its local control ({$clientCode}): {$clientMessage}",
         );
      }

      return [
         'listener' => $Listener,
         'address' => $address,
         'connected' => true,
      ];
   };

   /**
    * Probe the exact listener in the real selector backend.
    *
    * @param resource $Listener
    * @return array{result:int|false,warnings:array<int,array{severity:int,message:string,file:string}>}
    */
   $Select = static function (mixed $Listener): array {
      $warnings = [];
      set_error_handler(
         static function (
            int $severity,
            string $message,
            string $file,
         ) use (&$warnings): bool {
            $captured = str_contains($message, 'stream_select')
               && str_contains($message, 'FD_SETSIZE');
            if ($captured) {
               $warnings[] = [
                  'severity' => $severity,
                  'message' => $message,
                  'file' => $file,
               ];
            }

            return $captured;
         },
      );
      try {
         $reads = [$Listener];
         $writes = [];
         $excepts = [];
         $result = stream_select($reads, $writes, $excepts, 0, 0);
      }
      finally {
         restore_error_handler();
      }

      return ['result' => $result, 'warnings' => $warnings];
   };

   /**
    * Fork, bound, and exactly reap one real server work() invocation.
    *
    * @param resource $Listener
    * @return array<string,mixed>
    */
   $Run = static function (
      string $kind,
      mixed $Listener,
      L3Server $Server,
   ): array {
      $pair = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($pair === false) {
         throw new RuntimeException("L3 {$kind} leg could not create its evidence pipe.");
      }

      $Connections = $Server->Connections;
      $Selector = new L3Select($Connections, $pair[1]);
      $Selector->defer(
         hrtime(true) + 50_000_000,
         static function () use ($Selector): void {
            $Selector->loop = false;
         },
      );

      $PID = 0;
      $status = 0;
      $waited = 0;
      $timedOut = false;
      $raw = '';
      try {
         $PID = $Server->spawn($Listener, $Selector);
         fclose($pair[1]);

         $deadline = microtime(true) + 2.0;
         do {
            $waited = pcntl_waitpid($PID, $status, WNOHANG);
            if ($waited === $PID || $waited === -1) {
               break;
            }
            usleep(5000);
         }
         while (microtime(true) < $deadline);

         if ($waited !== $PID) {
            $timedOut = true;
            @posix_kill($PID, SIGTERM);
            $killDeadline = microtime(true) + 0.25;
            do {
               $waited = pcntl_waitpid($PID, $status, WNOHANG);
               if ($waited === $PID || $waited === -1) {
                  break;
               }
               usleep(5000);
            }
            while (microtime(true) < $killDeadline);

            if ($waited !== $PID) {
               @posix_kill($PID, SIGKILL);
               $waited = pcntl_waitpid($PID, $status);
            }
         }

         stream_set_blocking($pair[0], false);
         $contents = stream_get_contents($pair[0]);
         if (is_string($contents)) {
            $raw = $contents;
         }
      }
      finally {
         foreach ($pair as $socket) {
            if (is_resource($socket)) {
               fclose($socket);
            }
         }
         if ($PID > 0 && $waited === $PID) {
            $Server->forget($PID);
         }
      }

      $events = [];
      foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
         if ($line === '') {
            continue;
         }
         $event = json_decode($line, true);
         if (is_array($event) === false) {
            throw new RuntimeException(
               "L3 {$kind} leg returned malformed worker evidence.",
            );
         }
         $events[] = $event;
      }

      return [
         'listener_resource_id' => (int) $Listener,
         'pid' => $PID,
         'waited' => $waited,
         'timed_out' => $timedOut,
         'exited' => $waited === $PID && pcntl_wifexited($status),
         'exit_code' => $waited === $PID && pcntl_wifexited($status)
            ? pcntl_wexitstatus($status)
            : -1,
         'signaled' => $waited === $PID && pcntl_wifsignaled($status),
         'signal' => $waited === $PID && pcntl_wifsignaled($status)
            ? pcntl_wtermsig($status)
            : 0,
         'events' => $events,
      ];
   };

   /**
    * Run the complete master bind/admission/start path in its own process.
    *
    * @return array<string,mixed>
    */
   $Launch = static function (int $port): array {
      $pair = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($pair === false) {
         throw new RuntimeException('L3 master leg could not create its evidence pipe.');
      }

      $PID = pcntl_fork();
      if ($PID < 0) {
         fclose($pair[0]);
         fclose($pair[1]);
         throw new RuntimeException('L3 master leg could not fork.');
      }
      if ($PID === 0) {
         fclose($pair[0]);
         if (posix_setpgid(0, 0) === false) {
            @fwrite($pair[1], json_encode([
               'stage' => 'startup_error',
               'pid' => posix_getpid(),
               'error' => 'Could not isolate the startup process group.',
            ]) . "\n");
            exit(70);
         }

         $Selector = null;
         try {
            $Startup = new L3Server(Modes::Test);
            $Connections = $Startup->Connections;
            $Selector = new L3Select($Connections, $pair[1]);
            $Selector->defer(
               hrtime(true) + 50_000_000,
               static function () use ($Selector): void {
                  $Selector->loop = false;
               },
            );
            $Startup->launch($port, $Selector);
            exit(0);
         }
         catch (Throwable $Throwable) {
            if ($Selector instanceof L3Select) {
               $Selector->mark('startup_error', [
                  'error' => $Throwable::class . ': '
                     . $Throwable->getMessage(),
               ]);
            }
            exit(70);
         }
      }

      fclose($pair[1]);
      $status = 0;
      $waited = 0;
      $timedOut = false;
      $raw = '';
      $groupAlive = false;
      try {
         $deadline = microtime(true) + 3.0;
         do {
            $waited = pcntl_waitpid($PID, $status, WNOHANG);
            if ($waited === $PID || $waited === -1) {
               break;
            }
            usleep(5000);
         }
         while (microtime(true) < $deadline);

         if ($waited !== $PID) {
            $timedOut = true;
            @posix_kill(-$PID, SIGTERM);
            $killDeadline = microtime(true) + 0.25;
            do {
               $waited = pcntl_waitpid($PID, $status, WNOHANG);
               if ($waited === $PID || $waited === -1) {
                  break;
               }
               usleep(5000);
            }
            while (microtime(true) < $killDeadline);

            if ($waited !== $PID) {
               @posix_kill(-$PID, SIGKILL);
               $waited = pcntl_waitpid($PID, $status);
            }
         }

         $groupAlive = @posix_kill(-$PID, 0);
         if ($groupAlive) {
            @posix_kill(-$PID, SIGKILL);
         }

         stream_set_blocking($pair[0], false);
         $contents = stream_get_contents($pair[0]);
         if (is_string($contents)) {
            $raw = $contents;
         }
      }
      finally {
         if (is_resource($pair[0])) {
            fclose($pair[0]);
         }
      }

      $events = [];
      foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
         if ($line === '') {
            continue;
         }
         $event = json_decode($line, true);
         if (is_array($event) === false) {
            throw new RuntimeException(
               'L3 master leg returned malformed startup evidence.',
            );
         }
         $events[] = $event;
      }

      return [
         'pid' => $PID,
         'waited' => $waited,
         'timed_out' => $timedOut,
         'exited' => $waited === $PID && pcntl_wifexited($status),
         'exit_code' => $waited === $PID && pcntl_wifexited($status)
            ? pcntl_wexitstatus($status)
            : -1,
         'signaled' => $waited === $PID && pcntl_wifsignaled($status),
         'signal' => $waited === $PID && pcntl_wifsignaled($status)
            ? pcntl_wtermsig($status)
            : 0,
         'group_alive' => $groupAlive,
         'events' => $events,
      ];
   };

   try {
      if (
         function_exists('pcntl_fork') === false
         || function_exists('pcntl_waitpid') === false
         || function_exists('stream_socket_pair') === false
      ) {
         throw new RuntimeException('L3 requires PCNTL and stream socket-pair support.');
      }

      $TCPReflection = new ReflectionClass(TCP_Server_CLI::class);
      $SelectReflection = new ReflectionClass(Select::class);
      $tcpFile = $TCPReflection->getFileName();
      $selectFile = $SelectReflection->getFileName();
      $probe['source'] = [
         'php_version' => PHP_VERSION,
         'tcp_file' => is_string($tcpFile) ? $tcpFile : '',
         'tcp_sha256' => is_string($tcpFile)
            ? hash_file('sha256', $tcpFile)
            : false,
         'select_file' => is_string($selectFile) ? $selectFile : '',
         'select_sha256' => is_string($selectFile)
            ? hash_file('sha256', $selectFile)
            : false,
      ];

      $low = $Listen();
      $lowListener = $low['listener'];

      // @ Discover the boundary through the real backend, not resource IDs.
      for ($attempt = 0; $attempt < 128; $attempt++) {
         for ($batch = 0; $batch < 16; $batch++) {
            $filler = fopen('/dev/null', 'rb');
            if ($filler === false) {
               break 2;
            }
            $fillers[] = $filler;
         }

         $candidate = $Listen();
         $candidateProbe = $Select($candidate['listener']);
         if ($candidateProbe['result'] === false) {
            $highListener = $candidate['listener'];
            $probe['discovery'] = [
               'attempts' => $attempt + 1,
               'fillers' => count($fillers),
               'address' => $candidate['address'],
               'connected' => $candidate['connected'],
               'result' => $candidateProbe['result'],
               'warnings' => $candidateProbe['warnings'],
            ];
            break;
         }
         fclose($candidate['listener']);
      }

      if (is_resource($highListener) === false) {
         throw new RuntimeException(
            'The local stream_select backend exposed no unrepresentable TCP listener.',
         );
      }

      $lowProbe = $Select($lowListener);
      $probe['control'] = [
         'address' => $low['address'],
         'connected' => $low['connected'],
         'result_under_pressure' => $lowProbe['result'],
         'warnings_under_pressure' => $lowProbe['warnings'],
      ];

      $Server = new L3Server(Modes::Test);
      $probe['legs']['low'] = $Run('low', $lowListener, $Server);
      $probe['legs']['high'] = $Run('high', $highListener, $Server);
      $Server->release();

      $highAddress = (string) ($probe['discovery']['address'] ?? '');
      $separator = strrpos($highAddress, ':');
      $port = $separator === false
         ? 0
         : (int) substr($highAddress, $separator + 1);
      if ($port < 1 || $port > 65535) {
         throw new RuntimeException('L3 could not recover the high listener port.');
      }

      // Free exactly that port while retaining descriptor pressure. The
      // complete startup leg must bind its own high listener and reject it in
      // the master before any serving worker is forked.
      fclose($highListener);
      $highListener = null;
      $probe['legs']['master'] = $Launch($port);
   }
   catch (Throwable $Throwable) {
      $probe['fixture_error'] = $Throwable::class . ': '
         . $Throwable->getMessage();
   }
   finally {
      if ($Server instanceof L3Server) {
         $Server->release();
      }
      foreach ([$lowListener, $highListener] as $Listener) {
         if (is_resource($Listener)) {
            fclose($Listener);
         }
      }
      foreach ($fillers as $filler) {
         if (is_resource($filler)) {
            fclose($filler);
         }
      }
   }

   $encoded = json_encode($probe, JSON_UNESCAPED_SLASHES);
   if (is_string($encoded) === false) {
      fwrite(STDERR, "L3 probe could not encode its evidence.\n");
      exit(3);
   }
   fwrite(STDOUT, "\nL3_EVIDENCE:" . $encoded);
   exit(0);
}

return new Test(
   description: 'Worker startup must reject an unrepresentable inherited listener',
   Separator: new Separator(line: true),

   request: static function () use (&$probe): string {
      $process = null;
      $pipes = [];
      $base = sys_get_temp_dir() . '/bootgly-l3-listener-'
         . bin2hex(random_bytes(8));

      /** @return array<string,mixed> */
      $Terminate = static function (mixed $process): array {
         $status = proc_get_status($process);
         if (is_array($status) === false) {
            return ['running' => false, 'exitcode' => -1];
         }
         if (($status['running'] ?? false) === false) {
            return $status;
         }

         proc_terminate($process);
         for ($attempt = 0; $attempt < 25; $attempt++) {
            usleep(10000);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
               return $status;
            }
         }

         proc_terminate($process, 9);
         for ($attempt = 0; $attempt < 100; $attempt++) {
            usleep(10000);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
               return $status;
            }
         }

         return $status;
      };

      $Clean = static function (string $path) use (&$Clean): void {
         if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
         }
         if (is_dir($path) === false) {
            return;
         }
         foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            $Clean($path . '/' . $entry);
         }
         @rmdir($path);
      };

      try {
         if (
            function_exists('proc_open') === false
            || mkdir($base, 0700, true) === false
         ) {
            throw new RuntimeException('L3 could not create its isolated attack process.');
         }

         $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];
         $process = proc_open(
            [
               PHP_BINARY,
               '-d',
               'opcache.enable_cli=0',
               __FILE__,
               '--l3-listener-probe',
               BOOTGLY_ROOT_BASE,
               $base,
            ],
            $descriptors,
            $pipes,
            BOOTGLY_ROOT_BASE,
         );
         if (is_resource($process) === false) {
            throw new RuntimeException('L3 could not start its listener attack process.');
         }

         stream_set_blocking($pipes[1], false);
         stream_set_blocking($pipes[2], false);
         $output = '';
         $error = '';
         $timedOut = false;
         $status = [];
         $deadline = microtime(true) + 12.0;
         do {
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) {
               $output .= $chunk;
            }
            $chunk = stream_get_contents($pipes[2]);
            if ($chunk !== false) {
               $error .= $chunk;
            }

            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
               break;
            }
            usleep(10000);
         }
         while (microtime(true) < $deadline);

         if (($status['running'] ?? false) === true) {
            $timedOut = true;
            $status = $Terminate($process);
         }

         foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false) {
               if ($index === 1) {
                  $output .= $chunk;
               }
               else {
                  $error .= $chunk;
               }
            }
            fclose($pipes[$index]);
            unset($pipes[$index]);
         }

         $statusCode = (int) ($status['exitcode'] ?? -1);
         $closedCode = ($status['running'] ?? false) === false
            ? proc_close($process)
            : -1;
         if (($status['running'] ?? false) === false) {
            $process = null;
         }
         $exitCode = $statusCode >= 0 ? $statusCode : $closedCode;

         if ($timedOut) {
            throw new RuntimeException('L3 listener attack process exceeded 12 seconds.');
         }
         $marker = 'L3_EVIDENCE:';
         $position = strrpos($output, $marker);
         if ($position === false) {
            throw new RuntimeException(
               'L3 listener attack process returned no evidence marker: '
               . trim($error !== '' ? $error : $output),
            );
         }
         $decoded = json_decode(
            trim(substr($output, $position + strlen($marker))),
            true,
         );
         if (is_array($decoded) === false) {
            throw new RuntimeException(
               'L3 listener attack process returned unreadable evidence.',
            );
         }
         $probe = $decoded;
         $probe['child'] = [
            'exit_code' => $exitCode,
            'stderr' => trim($error),
            'stdout_prefix' => trim(substr($output, 0, $position)),
            'timed_out' => false,
         ];
         if ($exitCode !== 0 || $probe['child']['stderr'] !== '') {
            $probe['fixture_error'] = 'Listener attack process exit/stderr control failed.';
         }
      }
      catch (Throwable $Throwable) {
         $probe['fixture_error'] = $Throwable::class . ': '
            . $Throwable->getMessage();
      }
      finally {
         foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
               fclose($pipe);
            }
         }
         if (is_resource($process)) {
            $status = $Terminate($process);
            if (($status['running'] ?? false) === false) {
               proc_close($process);
            }
         }
         $Clean($base);
      }

      return "GET /l3/worker-listener-admission HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) {
      yield $Router->route(
         '/l3/worker-listener-admission',
         static fn (Request $Request, Response $Response): Response =>
            $Response(code: 200, body: 'L3-CONTROL'),
         GET,
      );
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'L3-CONTROL') === false
      ) {
         Vars::$labels = ['L3 native HTTP harness control'];
         dump(json_encode($response));

         return 'L3 fixture failed: the independent HTTP control did not complete.';
      }
      if (($probe['fixture_error'] ?? '') !== '') {
         Vars::$labels = ['L3 fixture error', 'L3 evidence'];
         dump($probe['fixture_error'], json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'L3 fixture failed before worker listener admission: '
            . $probe['fixture_error'];
      }

      $expectedTCP = realpath(
         BOOTGLY_ROOT_BASE . '/Bootgly/WPI/Interfaces/TCP_Server_CLI.php',
      );
      $expectedSelect = realpath(
         BOOTGLY_ROOT_BASE . '/Bootgly/WPI/Events/Select.php',
      );
      $source = $probe['source'] ?? [];
      if (
         is_string($expectedTCP) === false
         || is_string($expectedSelect) === false
         || realpath((string) ($source['tcp_file'] ?? '')) !== $expectedTCP
         || realpath((string) ($source['select_file'] ?? '')) !== $expectedSelect
         || ($source['tcp_sha256'] ?? false) !== hash_file('sha256', $expectedTCP)
         || ($source['select_sha256'] ?? false) !== hash_file('sha256', $expectedSelect)
      ) {
         Vars::$labels = ['L3 exact-worktree source control', 'L3 evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'L3 fixture failed: the attack process did not load the exact '
            . 'TCP_Server_CLI.php and Select.php bytes from this worktree.';
      }

      $Warned = static function (array $warnings): bool {
         foreach ($warnings as $warning) {
            if (
               str_contains((string) ($warning['message'] ?? ''), 'stream_select')
               && str_contains((string) ($warning['message'] ?? ''), 'FD_SETSIZE')
            ) {
               return true;
            }
         }

         return false;
      };
      $discovery = $probe['discovery'] ?? [];
      if (
         ($discovery['connected'] ?? false) !== true
         || ($discovery['result'] ?? null) !== false
         || $Warned($discovery['warnings'] ?? []) === false
      ) {
         Vars::$labels = ['L3 high-listener precondition', 'L3 evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'L3 fixture failed: the exact high TCP listener was not proven '
            . 'listening and FD_SETSIZE-unrepresentable.';
      }
      $control = $probe['control'] ?? [];
      if (
         ($control['connected'] ?? false) !== true
         || ($control['result_under_pressure'] ?? false) === false
         || ($control['warnings_under_pressure'] ?? null) !== []
      ) {
         Vars::$labels = ['L3 low-listener control', 'L3 evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'L3 control failed: the exact low TCP listener was not usable '
            . 'by stream_select under the same descriptor pressure.';
      }

      /** @return null|array<string,mixed> */
      $Event = static function (array $leg, string $stage): null|array {
         foreach ($leg['events'] ?? [] as $event) {
            if (($event['stage'] ?? null) === $stage) {
               return $event;
            }
         }

         return null;
      };
      /** @return array<int,string> */
      $Stages = static function (array $leg): array {
         $stages = [];
         foreach ($leg['events'] ?? [] as $event) {
            $stages[] = (string) ($event['stage'] ?? '');
         }

         return $stages;
      };
      $low = $probe['legs']['low'] ?? [];
      $lowWire = $Event($low, 'wire');
      $lowAdd = $Event($low, 'add');
      if (
         ($low['timed_out'] ?? true) !== false
         || ($low['exited'] ?? false) !== true
         || ($low['exit_code'] ?? -1) !== 0
         || ($low['signaled'] ?? true) !== false
         || ($lowWire['resource_id'] ?? -1) !== ($low['listener_resource_id'] ?? -2)
         || ($lowAdd['resource_id'] ?? -1) !== ($low['listener_resource_id'] ?? -2)
         || ($lowAdd['flag'] ?? null) !== Select::EVENT_CONNECT
         || ($lowAdd['result'] ?? false) !== true
         || ($lowAdd['retained'] ?? false) !== true
         || ($lowAdd['pid'] ?? -1) !== ($low['pid'] ?? -2)
         || $Stages($low) !== ['add', 'wire', 'loop_enter', 'loop_leave']
      ) {
         Vars::$labels = ['L3 real low-worker control', 'L3 evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'L3 control failed: the real low-listener worker did not pass '
            . 'instance(), guarded add(), and a bounded production Select loop.';
      }

      $high = $probe['legs']['high'] ?? [];
      $highWire = $Event($high, 'wire');
      $highAdd = $Event($high, 'add');
      $highRejected = ($high['timed_out'] ?? true) === false
         && ($high['signaled'] ?? true) === false
         && ($highAdd['resource_id'] ?? -1) === ($high['listener_resource_id'] ?? -2)
         && ($highAdd['flag'] ?? null) === Select::EVENT_CONNECT
         && ($highAdd['result'] ?? true) === false
         && ($highAdd['retained'] ?? true) === false
         && ($highAdd['pid'] ?? -1) === ($high['pid'] ?? -2);
      if (
         $highRejected
         && ($high['exited'] ?? false) === true
         && ($high['exit_code'] ?? -1) === 0
         && ($highWire['resource_id'] ?? -1) === ($high['listener_resource_id'] ?? -2)
         && $Stages($high) === ['wire', 'add', 'loop_enter', 'loop_leave']
      ) {
         Vars::$labels = ['L3 confirmed worker-listener admission evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'CONFIRMED L3: TCP_Server_CLI::work() ignored Select::add() '
            . 'rejecting the exact FD_SETSIZE-unrepresentable inherited '
            . 'listener and entered Select::loop(); the low-listener control '
            . 'passed the same real worker startup path.';
      }

      $workerSafe = $highRejected
         && ($high['exited'] ?? false) === true
         && ($high['exit_code'] ?? -1) === 1
         && $highWire === null
         && $Stages($high) === ['add'];

      $master = $probe['legs']['master'] ?? [];
      $masterAdd = $Event($master, 'add');
      $masterSafe = ($master['timed_out'] ?? true) === false
         && ($master['exited'] ?? false) === true
         && ($master['exit_code'] ?? -1) === 1
         && ($master['signaled'] ?? true) === false
         && ($master['group_alive'] ?? true) === false
         && ($masterAdd['pid'] ?? -1) === ($master['pid'] ?? -2)
         && ($masterAdd['flag'] ?? null) === Select::EVENT_CONNECT
         && ($masterAdd['result'] ?? true) === false
         && ($masterAdd['retained'] ?? true) === false
         && $Stages($master) === ['add'];

      if ($workerSafe && $masterSafe) {
         return true;
      }

      Vars::$labels = ['L3 unexpected worker-listener state', 'L3 evidence'];
      dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

      return 'L3 probe reached an unexpected partial state; intentional '
         . 'fail-closed listener admission before Select::loop() was not established.';
   },
);
