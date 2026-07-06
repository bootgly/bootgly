<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes;


use const BOOTGLY_ROOT_DIR;
use function array_values;
use function clearstatcache;
use function count;
use function feof;
use function fread;
use function function_exists;
use function is_a;
use function opcache_invalidate;
use function preg_match;
use function str_replace;
use function stream_context_create;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_client;
use function strlen;
use function strpos;
use function substr;
use function usleep;
use BackedEnum;
use Closure;
use Exception;
use Generator;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Throwables\Exceptions;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Event;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Events as TCP_Client_Events;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_Testing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


class HTTP_Server_CLI extends TCP_Server_CLI implements HTTP, Server
{
   // * Config
   // ...inherited from TCP_Server_CLI

   // * Data
   // ...inherited from TCP_Server_CLI

   // * Metadata
   // ...inherited from TCP_Server_CLI
   // # Socket
   protected string $process = 'Bootgly_HTTP_Server';
   // # HTTP/2
   /**
    * @var bool Whether HTTP/2 is served at all — gates both the TLS-ALPN
    * advertisement and the cleartext prior-knowledge preface probe.
    * Driven by `configure(enableHTTP2:)`; `false` makes the server
    * HTTP/1.x-only.
    */
   public static bool $enableHTTP2 = true;

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
    * @param null|array<string,Closure(object):Response\Resource> $responseResources Lazy response resource factories.
    *
    * @return self The HTTP Server instance, for chaining 
    */
   public function configure (
      string $host, int $port, int $workers,
      null|array $secure = null,
      null|string $user = null, null|string $group = null,
      null|bool $enableHTTP2 = null,
      null|int $requestMaxFileSize = null, null|int $requestMaxBodySize = null,
      null|int $requestMaxMultipartFieldSize = null,
      null|int $requestMaxMultipartHeaderSize = null,
      null|int $requestMaxMultipartFields = null,
      null|int $requestMaxMultipartFiles = null,
      null|int $downloadsMaxBytesOnDisk = null,
      null|int $maxConnections = null,
      null|int $maxConnectionsPerIP = null,
      null|array $responseResources = null
   ): self
   {
      // @ HTTP/2 — on by default; `enableHTTP2: false` disables BOTH the
      //   TLS-ALPN advertisement (RFC 9113 §3.2) and the cleartext
      //   prior-knowledge preface probe (§3.3), making the server
      //   HTTP/1.x-only.
      self::$enableHTTP2 = ($enableHTTP2 !== false);

      if ($secure !== null && self::$enableHTTP2) {
         $secure['alpn_protocols'] ??= 'h2,http/1.1';

         self::$Protocols['h2'] = static function (Connection $Connection): void {
            // ! The input cache keys on repeated identical reads — useless
            //   for multiplexed binary frames.
            $Connection->cache = false;

            $Decoder = new Decoder_HTTP2;
            $Connection->Decoder = $Decoder;
            $Connection->decoded = $Decoder;
         };
      }
      else {
         // ? Statics survive re-configuration in the same process — never
         //   inherit a previous instance's installer.
         unset(self::$Protocols['h2']);
      }

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
      // @ Connection-exhaustion caps (audit F-2)
      if ($maxConnections !== null) {
         self::$maxConnections = $maxConnections;
      }
      if ($maxConnectionsPerIP !== null) {
         self::$maxConnectionsPerIP = $maxConnectionsPerIP;
      }
      if ($responseResources !== null) {
         self::$Response->Resources->load($responseResources);
      }

      return $this;
   }

   /**
    * Register an event handler for the HTTP Server.
    *
    * @param Event&BackedEnum $Event The event to listen to.
    * @param Closure $Callback The event callback.
    *
    * @return self The HTTP Server instance, for chaining.
    */
   public function on (
      Event & BackedEnum $Event,
      Closure $Callback
   ): self
   {
      if ($Event instanceof Events === false) {
         throw new InvalidArgumentException('Invalid HTTP Server event.');
      }

      if (isset($this->Events[$Event->value])) {
         throw new InvalidArgumentException("The event '{$Event->value}' is already registered.");
      }
      $this->Events[$Event->value] = true;

      match ($Event) {
         Events::RequestReceived => $this->listen($Callback),
         Events::ServerStarted => $this->onServerStarted = $Callback,
         Events::ServerStopped => $this->onServerStopped = $Callback,
      };

      // :
      return $this;
   }

   private function listen (Closure $Callback): void
   {
      if (isset(SAPI::$Middlewares) === false) {
         SAPI::$Middlewares = new Middlewares;
      }

      SAPI::$Handler = $Callback;
   }

   /**
    * Boot the Server API (honoring `Modes::Test`), or bail when no request
    * handler is wired. Overrides the base for the HTTP-specific error message.
    *
    * ! Honor server Mode: under `Modes::Test` the constructor installs
    *   `Encoder_Testing`; booting `Production` here would flip it back to
    *   `Encoder_` right before fork, leaving workers racing SIGUSR1
    *   (`@test init`) against the first request and serving 503 from `Encoder_`
    *   before the handler is installed.
    */
   protected function loading (): void
   {
      if (self::$Application) {
         self::$Application::boot(
            $this->Mode === Modes::Test
               ? Environments::Test
               : Environments::Production
         );
      }
      else if (isSet(SAPI::$Handler) === false) {
         $this->Logger->log(error: '@\;No request handler defined. Call on(Events::RequestReceived, ...) before start().@\;');
         exit(1);
      }
   }

   /**
    * Pre-fork setup: initialize the cross-worker upload byte counter (master-
    * side, before fork) so workers inherit the SHM segment + lockfile, then
    * purge temp files orphaned by a previous (crashed) run.
    */
   protected function booting (): void
   {
      // @ Inherited by workers via the SHM segment + lockfile descriptor.
      Downloads::init();
      // @ Purge temp files orphaned by a previous (crashed) run before the
      //   first fork — no worker is in-flight yet, so a full sweep is safe
      //   (audit F-10). The SHM counter is reset to 0 by init().
      Downloads::sweep();
   }

   public function stop (): void
   {
      // @ Tear down cross-worker upload counter (master only). The
      //   `ServerStopped` hook itself is fired by the base `stop()`.
      if (isset($this->Process) && $this->Process->level === 'master') {
         Downloads::destroy();
      }

      parent::stop();
   }

   public function instance ()
   {
      $Socket = parent::instance();

      // @ Per-worker upload-counter hygiene (audit F-10). Runs in every
      //   (re)spawned worker — both the initial fork and the SIGCHLD refork
      //   reach the worker through `$this->instance()`. Sweep temp files
      //   orphaned by a crashed worker (older than `ORPHAN_TTL`, so a live
      //   in-flight upload is untouched) and reconcile the SHM counter
      //   against the bytes actually on disk, healing any reservation a dead
      //   worker stranded on the shared counter.
      Downloads::sweep(Downloads::ORPHAN_TTL);
      Downloads::reconcile();

      return $Socket;
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
      Display::show(Display::NONE);

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
         TCP_Client_Events::ClientConnect,
         static function ($Socket, $Connection)
         use ($TCP_Client_CLI) 
         {
            Display::show(Display::MESSAGE);

            // ! Suite
            $Suite = SAPI::$Suite;
            $Suite->separate($Suite->name);

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

               // @ Fixture lifecycle — early prepare() so request: closure can
               //   read seeded state. Idempotent: base Test::pretest() will
               //   call prepare() again later as a no-op.
               $test->Fixture?->prepare();

               // @ Multi-request test
               if ($test->requests !== []) {
                  $responses = [];
                  $failed = false;

                  foreach ($test->requests as $reqIndex => $requestClosure) {
                     // ! Server
                     $responseLength = $test->responseLengths[$reqIndex] ?? null;
                     // ! Client
                     // ? Request
                     try {
                        $requestArguments = ["{$TCP_Client_CLI->host}:{$TCP_Client_CLI->port}", $specIndex + $reqIndex];
                        $requestArguments = self::inject($requestArguments, $requestClosure, $test->Fixture);

                        $requestData = $requestClosure(...$requestArguments);
                     }
                     catch (Throwable $Throwable) {
                        $test->Fixture?->dispose();

                        throw $Throwable;
                     }
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
                     $test->Fixture?->dispose();

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
               try {
                  if ($request !== null) {
                     $requestArguments = ["{$TCP_Client_CLI->host}:{$TCP_Client_CLI->port}", $specIndex - 1];
                     $requestArguments = self::inject($requestArguments, $request, $test->Fixture);

                     $requestResult = $request(...$requestArguments);
                  }
                  else {
                     $requestResult = '';
                  }
               }
               catch (Throwable $Throwable) {
                  $test->Fixture?->dispose();

                  throw $Throwable;
               }

               // @ Generator: yield chunks with delay for server event loop
               if ($requestResult instanceof Generator) {
                  $injected = false;
                  try {
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
                              $test->Fixture?->dispose();

                              break 2;
                           }
                        }
                        usleep(10000); // 10ms for server event loop to process
                     }
                  }
                  catch (Throwable $Throwable) {
                     $test->Fixture?->dispose();

                     throw $Throwable;
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
                     $test->Fixture?->dispose();

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
            Display::show(Display::MESSAGE);

            // @ Destroy Client Event Loop
            $TCP_Client_CLI::$Event->destroy();
         }
      );
      $TCP_Client_CLI->start();

      return true;
   }
   private static function check (null|ReflectionType $Type, Fixture $Fixture): bool
   {
      if ($Type === null) {
         return true;
      }

      if ($Type instanceof ReflectionUnionType) {
         foreach ($Type->getTypes() as $Inner) {
            if (self::check($Inner, $Fixture)) {
               return true;
            }
         }

         return false;
      }

      if ($Type instanceof ReflectionIntersectionType) {
         foreach ($Type->getTypes() as $Inner) {
            if (self::check($Inner, $Fixture) === false) {
               return false;
            }
         }

         return true;
      }

      if ($Type instanceof ReflectionNamedType) {
         $name = $Type->getName();
         if ($name === 'mixed' || $name === 'object') {
            return true;
         }
         if ($Type->isBuiltin()) {
            return false;
         }

         return is_a($Fixture, $name);
      }

      return false;
   }
   /**
    * @param array<int,mixed> $arguments
    *
    * @return array<int,mixed>
    */
   private static function inject (array $arguments, Closure $Closure, null|Fixture $Fixture): array
   {
      $arguments = array_values($arguments);

      if ($Fixture === null) {
         return $arguments;
      }

      $Function = new ReflectionFunction($Closure);
      $parameters = $Function->getParameters();
      $Parameter = $parameters[count($arguments)] ?? null;

      if ($Parameter === null) {
         foreach ($parameters as $Candidate) {
            if ($Candidate->isVariadic()) {
               $Parameter = $Candidate;
               break;
            }
         }
      }

      if ($Parameter === null) {
         return $arguments;
      }

      if (self::check($Parameter->getType(), $Fixture) === false) {
         return $arguments;
      }

      $arguments[] = $Fixture;

      return $arguments;
   }
}
