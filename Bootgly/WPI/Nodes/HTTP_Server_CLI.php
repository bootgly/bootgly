<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes;


use const BOOTGLY_ROOT_DIR;
use const SIG_IGN;
use const SIGALRM;
use const SIGCHLD;
use const SIGCONT;
use const SIGHUP;
use const SIGINT;
use const SIGIO;
use const SIGIOT;
use const SIGPIPE;
use const SIGQUIT;
use const SIGTERM;
use const SIGTSTP;
use const SIGUSR1;
use const SIGUSR2;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use function clearstatcache;
use function count;
use function fclose;
use function feof;
use function fread;
use function function_exists;
use function opcache_invalidate;
use function pcntl_signal;
use function preg_match;
use function restore_error_handler;
use function str_contains;
use function str_replace;
use function stream_context_create;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_client;
use function stream_socket_server;
use function strlen;
use function strpos;
use function substr;
use function time;
use function usleep;
use Closure;
use Exception;
use Generator;
use Throwable;

use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Throwables\Exceptions;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Process;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_Testing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


class HTTP_Server_CLI extends TCP_Server_CLI implements HTTP, Server
{
   // * Config
   // ...inherited from TCP_Server_CLI

   // * Data
   // ...inherited from TCP_Server_CLI
   // # Hooks
   protected static null|Closure $onServerStarted = null;
   protected static null|Closure $onServerStopped = null;

   // * Metadata
   // ...inherited from TCP_Server_CLI

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


   public function __construct (Modes $Mode = Modes::Daemon)
   {
      // * Config
      // ...inherited from TCP_Server_CLI

      // * Data
      // ...inherited from TCP_Server_CLI

      // * Metadata
      // ...inherited from TCP_Server_CLI


      // \
      parent::__construct($Mode);
      // * Config
      $this->socket = $this->secure !== null
         ? 'https'
         : 'http';
      // @ Configure Logger
      $this->Logger = new Logger(channel: 'HTTP.Server.CLI');

      // . Request, Response, Router
      self::$Request = new Request;
      self::$Response = new Response;
      self::$Router = new Router;

      // . Decoders, Encoders
      self::$Decoder = new Decoder_;

      switch ($Mode) {
         case Modes::Test:
            self::$Encoder = new Encoder_Testing;
            break;
         default:
            self::$Encoder = new Encoder_;
      }

      $WPI = WPI;
      // # HTTP
      $WPI->Server = $this;
      $WPI->Response = &self::$Response;
      $WPI->Request = &self::$Request;
      $WPI->Router = &self::$Router;
   }

   /**
    * Configure the HTTP Server.
    *
    * @return self The HTTP Server instance, for chaining 
    */
   public function configure (
      string $host, int $port, int $workers,
      null|array $secure = null,
      null|string $user = null, null|string $group = null,
      null|int $requestMaxFileSize = null, null|int $requestMaxBodySize = null,
      null|int $requestMaxMultipartFieldSize = null,
      null|int $requestMaxMultipartHeaderSize = null,
      null|int $requestMaxMultipartFields = null,
      null|int $requestMaxMultipartFiles = null,
      null|int $downloadsMaxBytesOnDisk = null
   ): self
   {
      parent::configure($host, $port, $workers, $secure, $user, $group);

      if ($host === '0.0.0.0') {
         $this->domain ??= 'localhost';
      }

      // * Config
      $this->socket = $this->secure !== null
         ? 'https://'
         : 'http://';

      // @ Request limits
      if ($requestMaxFileSize !== null) {
         Request::$maxFileSize = $requestMaxFileSize;
      }
      if ($requestMaxBodySize !== null) {
         Request::$maxBodySize = $requestMaxBodySize;
      }
      if ($requestMaxMultipartFieldSize !== null) {
         Request::$maxMultipartFieldSize = $requestMaxMultipartFieldSize;
      }
      if ($requestMaxMultipartHeaderSize !== null) {
         Request::$maxMultipartHeaderSize = $requestMaxMultipartHeaderSize;
      }
      if ($requestMaxMultipartFields !== null) {
         Request::$maxMultipartFields = $requestMaxMultipartFields;
      }
      if ($requestMaxMultipartFiles !== null) {
         Request::$maxMultipartFiles = $requestMaxMultipartFiles;
      }
      if ($downloadsMaxBytesOnDisk !== null) {
         Downloads::$maxBytesOnDisk = $downloadsMaxBytesOnDisk;
      }

      return $this;
   }

   /**
    * Register hooks for the HTTP Server.
    *
    * @param Closure(Request, Response, Router): mixed $requestReceived The request handler.
    * @param null|Closure(static): void $serverStarted Called after workers are spawned (server is ready).
    * @param null|Closure(static): void $serverStopped Called when the server is stopping.
    *
    * @return self The HTTP Server instance, for chaining.
    */
   public function on (
      // on HTTP
      Closure $requestReceived,
      // on Server
      null|Closure $serverStarted = null,
      null|Closure $serverStopped = null
   ): self
   {
      // @ Request handler
      if (isset(SAPI::$Middlewares) === false) {
         SAPI::$Middlewares = new Middlewares;
      }
      SAPI::$Handler = $requestReceived;

      // @ Lifecycle hooks
      self::$onServerStarted = $serverStarted;
      self::$onServerStopped = $serverStopped;

      // :
      return $this;
   }

   public function start (): bool
   {
      $this->Status = Status::Starting;

      Logger::$display = Logger::$display === 0 ? 0 : Logger::DISPLAY_MESSAGE;
      $this->log('@\;Starting Server...@.;', self::LOG_NOTICE_LEVEL);

      // @ Boot Server API
      // ! Honor server Mode: under Modes::Test the constructor installs
      //   Encoder_Testing; booting Production here would flip it back to
      //   Encoder_ right before fork, leaving workers racing SIGUSR1
      //   (`@test init`) against the first incoming request and serving
      //   503 from Encoder_ when the handler hasn't been installed yet.
      if (self::$Application) {
         self::$Application::boot(
            $this->Mode === Modes::Test
               ? Environments::Test
               : Environments::Production
         );
      }
      else if (isSet(SAPI::$Handler) === false) {
         $this->log('@\;No request handler defined. Call on(request:) before start().@\;', self::LOG_ERROR_LEVEL);
         exit(1);
      }

      // # Process
      // ? Pre-flight: verify socket can be bound before forking workers
      $probeCode = 0;
      $probeMessage = '';
      $probeContext = stream_context_create(['socket' => ['so_reuseport' => true, 'ipv6_v6only' => false]]);
      try {
         $probeSocket = @stream_socket_server(
            'tcp://' . ($this->host ?? '0.0.0.0') . ':' . ($this->port ?? 0),
            $probeCode,
            $probeMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $probeContext
         );
      }
      catch (Throwable) {
         $probeSocket = false;
      }
      if ($probeSocket === false) {
         $message = '@\;Could not bind to ' . ($this->host ?? '0.0.0.0') . ':' . ($this->port ?? 0) . ': ' . $probeMessage;
         if ($probeCode === 13 || str_contains($probeMessage ?? '', 'Permission denied')) {
            $message .= '@\;Ports below 1024 require elevated privileges. Try running with `sudo`.@.;';
         }
         $this->log($message, self::LOG_ERROR_LEVEL);
         exit(1);
      }
      fclose($probeSocket);

      $this->log("Forking {$this->workers} workers... @..;", self::LOG_NOTICE_LEVEL);

      // @ Initialize cross-worker upload byte counter (master-side, before
      //   fork) so workers inherit the SHM segment + lockfile descriptor.
      Downloads::init();

      // @ Install signal handlers for graceful shutdown
      $this->Process->Signals->install([
         SIGALRM,  // Timer
         SIGUSR1,  // Custom command
         SIGHUP,   // stop
         SIGINT,   // stop (CTRL + C)
         SIGQUIT,  // stop
         SIGTERM,  // stop
         SIGTSTP,  // pause (CTRL + Z)
         SIGCONT,  // resume
         SIGUSR2,  // reload
         SIGCHLD,  // recover
         SIGIOT,   // connection info
         SIGIO,    // connection stats
      ]);
      pcntl_signal(SIGPIPE, SIG_IGN, false);

      // @ Fork process workers...
      $this->Process->fork($this->workers, instance: function (Process $Process, int $index): void {
         $Process->title = 'Bootgly_HTTP_Server: child process (Worker #' . Process::$index . ')';

         Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

         // @ Hot-path: restore default error handler in worker.
         // The global Errors::collect handler is a userland callback invoked on every
         // suppressed warning (@fwrite/@fread produce EAGAIN under backpressure).
         // Userland dispatch dominates up to ~23% of CPU on high-throughput workloads.
         // CLI default handler is a no-op for suppressed errors (zero cost).
         restore_error_handler();

         // @ Create stream socket server
         $this->instance();

         // Event Loop
         self::$Event->add(
            $this->Socket,
            self::$Event::EVENT_CONNECT,
            true
         );
         self::$Event->loop();

         // @ Close stream socket server
         $this->stop();
      });

      // @ Set master process title
      $this->Process->title = 'Bootgly_HTTP_Server: master process';

      // @ Save full process state (master + workers + host + port).
      //   Done while still privileged so the file exists before demote() can
      //   hand it off to the target user — the PID dir may not be writable
      //   by the demoted user, but chown on the existing file lets subsequent
      //   re-saves (e.g. in daemonize()) succeed as that user.
      $this->Process->State->save([
         'master'  => Process::$master,
         'workers' => $this->Process->Children->PIDs,
         'host'    => $this->host ?? '0.0.0.0',
         'port'    => $this->port ?? 0,
         'started' => time(),
         'type'    => 'WPI'
      ]);

      // @ Drop privileges on master post-fork + post-save. Workers kept their
      //   own bind as root (port <1024) and demote themselves in `instance()`.
      //   `demote()` also chowns state files so `project stop` from the
      //   demoted user can unlink them.
      $this->demote();

      // # Hook
      // @ Invoke started hook (server is ready for connections)
      if (self::$onServerStarted !== null) {
         (self::$onServerStarted)($this);
      }

      // @
      // ... Continue to master process:
      switch ($this->Mode) {
         case Modes::Daemon:
            $this->daemonize();
            break;
         case Modes::Interactive:
            $this->interacting();
            break;
         case Modes::Monitor:
            $this->monitoring();
            break;
      }

      return true;
   }

   public function stop (): void
   {
      // # Hook
      // @ Invoke stopped hook before shutdown
      if (self::$onServerStopped !== null && $this->Process->level === 'master') {
         (self::$onServerStopped)($this);
      }

      // @ Tear down cross-worker upload counter (master only).
      if (isset($this->Process) && $this->Process->level === 'master') {
         Downloads::destroy();
      }

      parent::stop();
   }

   public static function boot (Environments $Environment, string $testsDir = 'E2E'): void
   {
      switch ($Environment) {
         case Environments::Test:
            try {
               // @ Propagate Test environment to SAPI so that Request::decode()
               //   parses & strips the `X-Bootgly-Test: N` harness header on
               //   the very first request. Without this, a worker booted via
               //   start() (before SIGUSR1 `@test init` has been dispatched)
               //   would leave Environment=Production, the header would be
               //   exposed to user code, and SAPI::$testIndexHeader would
               //   stay null — Encoder_Testing would then call an
               //   uninitialized SAPI::$Handler and return 500 / 503.
               SAPI::$Environment = Environments::Test;

               self::$Encoder = new Encoder_Testing;

               if (
                  isset(SAPI::$Suite)
                  && isset(SAPI::$Tests[self::class])
               ) {
                  break;
               }

               // @ Convert namespace to path (backslash -> forward slash)
               $classPath = str_replace('\\', '/', __CLASS__);

               // * Data
               // !
               $bootstrap = BOOTGLY_ROOT_DIR . $classPath . '/tests/' . $testsDir . '/@.php';
               $Bootstrap = new File($bootstrap);
               // ?
               if ($Bootstrap->exists === false) {
                  throw new Exception("Test Suite file not found: \n {$bootstrap}");
               }
               // @ Reset Cache of Test case file
               if (function_exists('opcache_invalidate')) {
                  opcache_invalidate($bootstrap, true);
               }
               clearstatcache(false, $bootstrap);

               $Suite = include $Bootstrap->file;
               // ?
               if ($Suite instanceof Suite === false) {
                  throw new Exception("Test Suite instance not found: \n {$bootstrap}");
               }
               self::pretest($Suite, $testsDir);
            }
            catch (Throwable $Throwable) {
               Exceptions::report($Throwable);
            }

            break;
         default:
            self::$Encoder = new Encoder_;
      }
   }

   public static function pretest (null|Suite $Suite, string $testsDir = 'E2E'): void
   {
      if ($Suite === null) {
         return;
      }

      $originalTests = $Suite->tests;
      $target = $Suite->target ?? 0;

      $selected = [];
      if ($target > 0) {
         $index = $target - 1;
         if (isset($originalTests[$index])) {
            $selected[$index] = $originalTests[$index];
         }
      }
      else {
         foreach ($originalTests as $index => $case) {
            $selected[$index] = $case;
         }
      }

      SAPI::$Suite = $Suite;
      SAPI::$Tests[self::class] = [];
      SAPI::$tests[self::class] = [];

      // @ Convert namespace to path (backslash -> forward slash)
      $classPath = str_replace('\\', '/', __CLASS__);

      foreach ($selected as $index => $case) {
         $Test_Case_File = new File(
            BOOTGLY_ROOT_DIR . $classPath . '/tests/' . $testsDir . '/' . $case . '.test.php'
         );
         if ($Test_Case_File->exists === false) {
            continue;
         }

         try {
            /** @var Specification|null $test */
            $test = require $Test_Case_File;
         }
         catch (Throwable) {
            $test = null;
         }

         if ($test instanceof Specification) {
            $test->index(case: $index + 1);
         }
         SAPI::$Tests[self::class][] = $test;
         SAPI::$tests[self::class][] = $case;

         // @ Expand handler queue for multi-request tests
         if (
            $test instanceof \Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification
            && $test->requests !== []
         ) {
            $extra = count($test->requests) - 1;
            for ($i = 0; $i < $extra; $i++) {
               SAPI::$Tests[self::class][] = $test;
            }
         }
      }

      $Suite->tests = SAPI::$tests[self::class];
   }
   protected static function test (TCP_Server_CLI $TCP_Server_CLI): bool
   {
      Logger::$display = Logger::DISPLAY_NONE;

      self::boot(Environments::Test);

      // @ MODE_TEST skips per-project State lock + shutdown SIGINT, so multiple
      //   test suites can run sequentially in the same master PHP process
      //   without blocking on flock() or tearing down the master on each
      //   client destruction.
      $TCP_Client_CLI = new TCP_Client_CLI(mode: TCP_Client_CLI::MODE_TEST);
      $TCP_Client_CLI->configure(
         host: ($TCP_Server_CLI->host === '0.0.0.0')
            ? '127.0.0.1'
            : ($TCP_Server_CLI->host ?? 'localhost'),
         port: $TCP_Server_CLI->port ?? 80,
      );
      $TCP_Client_CLI->on(
         // on Connection connect
         clientConnect: static function ($Socket, $Connection)
         use ($TCP_Client_CLI) 
         {
            Logger::$display = Logger::DISPLAY_MESSAGE;

            // ! Suite
            $Suite = SAPI::$Suite;
            $Suite->separate('HTTP Server');

            // @ Reconnection closure (for tests that close the connection)
            $reconnect = static function () use ($TCP_Client_CLI, &$Socket): void {
               $context = stream_context_create([
                  'socket' => ['tcp_nodelay' => true]
               ]);
               $newSocket = @stream_socket_client(
                  "tcp://{$TCP_Client_CLI->host}:{$TCP_Client_CLI->port}",
                  $errno,
                  $errstr,
                  timeout: 5,
                  context: $context
               );
               if ($newSocket !== false) {
                  stream_set_blocking($newSocket, false);
                  $Socket = $newSocket;
               }
            };

            // @ Inject `X-Bootgly-Test: N` into the first HTTP request of a
            //   raw byte payload. Used for index-based handler dispatch (see
            //   `Server::boot($testIndex)` and `Encoder_Testing::encode()`).
            //   Leaves payloads that don't start with an HTTP request-line
            //   (pipelined/smuggled bytes, non-HTTP fuzz) untouched.
            $injectTestIndex = static function (string $bytes, int $testIndex): string {
               // Find the end of the request-line (first CRLF).
               $eol = strpos($bytes, "\r\n");
               if ($eol === false) {
                  return $bytes;
               }
               // Heuristic: only inject if the request-line looks like
               //   `METHOD SP request-target SP HTTP/x.y`.
               $requestLine = substr($bytes, 0, $eol);
               if (! preg_match('#^[A-Z]+ \S+ HTTP/\d\.\d$#', $requestLine)) {
                  return $bytes;
               }
               return substr($bytes, 0, $eol + 2)
                  . "X-Bootgly-Test: {$testIndex}\r\n"
                  . substr($bytes, $eol + 2);
            };

            // @@ Iterate Test Cases
            // !
            $testFiles = SAPI::$tests[self::class] ?? [];
            $specIndex = 0;
            foreach ($testFiles as $index => $value) {
               // @ Reset connection state from previous test
               $Connection->expired = false;
               $Connection->input = '';

               // @ Detect dead/stale connection from previous test's reject/close
               $r = [$Socket]; $w = null; $e = null;
               if (@feof($Socket) || @stream_select($r, $w, $e, 0, 10000) > 0) {
                  // @@ Drain stale data from socket buffer
                  while (@fread($Socket, 65535) !== '' && !@feof($Socket)) {}
                  // @ Reconnect with fresh socket
                  $reconnect();
               }

               /** @var Specification|null $test */
               $test = SAPI::$Tests[self::class][$specIndex] ?? null;

               if ($test instanceof Specification) {
                  $test->index(case: $test->case ?? ((int) $index + 1));
               }
               // @ Init Test
               $Suite->case = $test->case ?? ((int) $index + 1);

               $Test = $Suite->test($test);
               if ($Test === null || !($test instanceof \Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification)) {
                  $Suite->skip();
                  $specIndex++;
                  continue;
               }

               // @ Multi-request test
               if ($test->requests !== []) {
                  $responses = [];
                  $failed = false;

                  foreach ($test->requests as $reqIndex => $requestClosure) {
                     // ! Server
                     $responseLength = $test->responseLengths[$reqIndex] ?? null;
                     // ! Client
                     // ? Request
                     $requestData = $requestClosure("{$TCP_Client_CLI->host}:{$TCP_Client_CLI->port}", $specIndex + $reqIndex);
                     // @ Inject handler-dispatch header (index-based).
                     $requestData = $injectTestIndex($requestData, $specIndex + $reqIndex);
                     $requestLength = strlen($requestData);
                     // @ Send Request to Server
                     $Connection->output = $requestData;
                     if ( ! $Connection->writing($Socket, $requestLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                        // @ Reconnect and retry
                        $reconnect();
                        if ( ! $Connection->writing($Socket, $requestLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                           $failed = true;
                           break;
                        }
                     }
                     // ? Response
                     $timeout = 2;
                     $input = '';
                     // @ Get Response from Server
                     if ( $Connection->reading($Socket, $responseLength, $timeout) ) {
                        $input = $Connection->input;
                     }
                     // @ Reconnect and retry if response is empty (half-closed connection)
                     if ($input === '' && $Connection->expired) { // @phpstan-ignore identical.alwaysTrue, booleanAnd.rightAlwaysFalse
                        $reconnect();
                        $Connection->expired = false;
                        $Connection->output = $requestData;
                        if ($Connection->writing($Socket, $requestLength)) {
                           if ($Connection->reading($Socket, $responseLength, $timeout)) {
                              $input = $Connection->input;
                           }
                        }
                     }

                     if ($Connection->expired) { // @phpstan-ignore if.alwaysFalse
                        $failed = true;
                        break;
                     }

                     $responses[] = $input;
                  }

                  $specIndex += count($test->requests);

                  if ($failed) {
                     $Test->fail();
                     break;
                  }

                  // @ Execute Test
                  $Test->test($responses);
                  // @ Output Test result
                  if ($Test->passed) {
                     $Test->pass();
                  }
                  else {
                     $Test->fail();
                     break;
                  }

                  continue;
               }

               // @ Single-request test (existing behavior)
               $specIndex++;

               // ! Server
               $responseLength = $test->responseLength;
               // ! Client
               // ? Request
               $request = $test->request;
               $requestResult = $request !== null
                  ? $request("{$TCP_Client_CLI->host}:{$TCP_Client_CLI->port}", $specIndex - 1)
                  : '';

               // @ Generator: yield chunks with delay for server event loop
               if ($requestResult instanceof Generator) {
                  $injected = false;
                  foreach ($requestResult as $chunk) {
                     /** @var string $chunk */
                     if (! $injected) {
                        $chunk = $injectTestIndex($chunk, $specIndex - 1);
                        $injected = true;
                     }
                     $chunkLength = strlen($chunk);
                     $Connection->output = $chunk;
                     if ( ! $Connection->writing($Socket, $chunkLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                        $reconnect();
                        if ( ! $Connection->writing($Socket, $chunkLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                           break 2;
                        }
                     }
                     usleep(10000); // 10ms for server event loop to process
                  }

                  // ? Response
                  $timeout = 2;
                  $input = '';
                  if ( $Connection->reading($Socket, $responseLength, $timeout) ) {
                     $input = $Connection->input;
                  }

                  // @ Execute Test
                  $Test->test($input);
                  if (! $Connection->expired && $Test->passed) { // @phpstan-ignore booleanNot.alwaysTrue
                     $Test->pass();
                  }
                  else {
                     $Test->fail();
                     break;
                  }

                  continue;
               }

               $requestData = $requestResult;
               // @ Inject handler-dispatch header (index-based).
               //   `$specIndex` was pre-incremented above for single-request
               //   tests, so the correct slot is `$specIndex - 1`.
               $requestData = $injectTestIndex($requestData, $specIndex - 1);
               $requestLength = strlen($requestData);
               // @ Send Request to Server
               $Connection->output = $requestData;
               if ( ! $Connection->writing($Socket, $requestLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                  // @ Reconnect and retry
                  $reconnect();
                  if ( ! $Connection->writing($Socket, $requestLength) ) { // @phpstan-ignore booleanNot.alwaysTrue
                     $Test->fail();
                     break;
                  }
               }
               // ? Response
               $timeout = 2;
               $input = '';
               // @ Get Response from Server
               if ( $Connection->reading($Socket, $responseLength, $timeout) ) {
                  $input = $Connection->input;
               }
               // @ Reconnect and retry if response is empty (half-closed connection)
               if ($input === '' && $Connection->expired) { // @phpstan-ignore identical.alwaysTrue, booleanAnd.rightAlwaysFalse
                  $reconnect();
                  $Connection->expired = false;
                  $Connection->output = $requestData;
                  if ($Connection->writing($Socket, $requestLength)) {
                     if ($Connection->reading($Socket, $responseLength, $timeout)) {
                        $input = $Connection->input;
                     }
                  }
               }

               // @ Execute Test
               $Test->test($input);
               // @ Output Test result
               if (! $Connection->expired && $Test->passed) { // @phpstan-ignore booleanNot.alwaysTrue
                  $Test->pass();
               }
               else {
                  $Test->fail();
                  break;
               }
            }

            $Suite->summarize();

            // @ Reset CLI Logger
            Logger::$display = Logger::DISPLAY_MESSAGE;

            // @ Destroy Client Event Loop
            $TCP_Client_CLI::$Event->destroy();
         }
      );
      $TCP_Client_CLI->start();

      return true;
   }
}
