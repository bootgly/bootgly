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
use function count;
use function function_exists;
use function opcache_invalidate;
use function clearstatcache;
use function pcntl_signal;
use function strlen;
use function time;
use Closure;
use Exception;
use Throwable;

use Bootgly\ABI\Debugging\Data\Throwables\Exceptions;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Process;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environments;
use Bootgly\API\Server as SAPI;
use Bootgly\API\Server\Middlewares;
use const Bootgly\WPI;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_Testing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


class HTTP_Server_CLI extends TCP_Server_CLI implements HTTP, Server
{
   // * Config
   // ...inherited from TCP_Server_CLI

   // * Data
   // ...inherited from TCP_Server_CLI
   // # Hooks
   protected static null|Closure $onStarted = null;
   protected static null|Closure $onStopped = null;

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
      $this->socket = $this->ssl !== null
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
    * @param string $host The host to bind the server to.
    * @param int $port Port to bind the server to.
    * @param int $workers Number of workers to spawn.
    * @param null|array<string> $ssl SSL configuration.
    * @param null|string $user User to drop privileges to after socket binding.
    * @param null|string $group Group to drop privileges to after socket binding.
    * @param null|int $requestMaxFileSize Maximum file size in bytes for multipart/form-data downloads.
    * @param null|int $requestMaxBodySize Maximum body size in bytes for non-multipart requests.
    *
    * @return self The HTTP Server instance, for chaining 
    */
   public function configure (
      string $host, int $port, int $workers,
      null|array $ssl = null,
      null|string $user = null, null|string $group = null,
      null|int $requestMaxFileSize = null, null|int $requestMaxBodySize = null
   ): self
   {
      parent::configure($host, $port, $workers, $ssl, $user, $group);

      if ($host === '0.0.0.0') {
         $this->domain ??= 'localhost';
      }

      // * Config
      $this->socket = $this->ssl !== null
         ? 'https://'
         : 'http://';

      // @ Request limits
      if ($requestMaxFileSize !== null) {
         Request::$maxFileSize = $requestMaxFileSize;
      }
      if ($requestMaxBodySize !== null) {
         Request::$maxBodySize = $requestMaxBodySize;
      }

      return $this;
   }

   /**
    * Register hooks for the HTTP Server.
    *
    * @param Closure(Request, Response, Router): mixed $request The request handler.
    * @param null|Closure(static): void $started Called after workers are spawned (server is ready).
    * @param null|Closure(static): void $stopped Called when the server is stopping.
    *
    * @return self The HTTP Server instance, for chaining.
    */
   public function on (
      Closure $request,
      null|Closure $started = null,
      null|Closure $stopped = null
   ): self
   {
      // @ Request handler
      if (isset(SAPI::$Middlewares) === false) {
         SAPI::$Middlewares = new Middlewares;
      }
      SAPI::$Handler = $request;

      // @ Lifecycle hooks
      self::$onStarted = $started;
      self::$onStopped = $stopped;

      // :
      return $this;
   }

   public function start (): bool
   {
      $this->Status = Status::Starting;

      Logger::$display = Logger::$display === 0 ? 0 : Logger::DISPLAY_MESSAGE;
      $this->log('@\;Starting Server...', self::LOG_NOTICE_LEVEL);

      // @ Boot Server API
      if (self::$Application) {
         self::$Application::boot(Environments::Production);
      }
      else if (isSet(SAPI::$Handler) === false) {
         $this->log('@\;No request handler defined. Call on(request:) before start().@\;', self::LOG_ERROR_LEVEL);
         exit(1);
      }

      // # Process
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
         $Process->title = 'Bootgly_WPI_Server: child process (Worker #' . Process::$index . ')';

         Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

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
      $this->Process->title = 'Bootgly_WPI_Server: master process';

      // @ Save full process state (master + workers + host + port)
      $this->Process->State->save([
         'master'  => Process::$master,
         'workers' => $this->Process->Children->PIDs,
         'host'    => $this->host ?? '0.0.0.0',
         'port'    => $this->port ?? 0,
         'started' => time(),
         'type'    => 'WPI'
      ]);

      // # Hook
      // @ Invoke started hook (server is ready for connections)
      if (self::$onStarted !== null) {
         (self::$onStarted)($this);
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
      if (self::$onStopped !== null && $this->Process->level === 'master') {
         (self::$onStopped)($this);
      }

      parent::stop();
   }

   public static function boot (Environments $Environment): void
   {
      switch ($Environment) {
         case Environments::Test:
            try {
               self::$Encoder = new Encoder_Testing;

               if (
                  isset(SAPI::$Suite)
                  && isset(SAPI::$Tests[self::class])
               ) {
                  break;
               }

               // * Data
               // !
               $bootstrap = BOOTGLY_ROOT_DIR . __CLASS__ . '/tests/@.php';
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
               self::pretest($Suite);
            }
            catch (Throwable $Throwable) {
               Exceptions::report($Throwable);
            }

            break;
         default:
            self::$Encoder = new Encoder_;
      }
   }

   public static function pretest (null|Suite $Suite): void
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

      foreach ($selected as $index => $case) {
         $Test_Case_File = new File(
            BOOTGLY_ROOT_DIR . __CLASS__ . '/tests/' . $case . '.test.php'
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

      $TCP_Client_CLI = new TCP_Client_CLI;
      $TCP_Client_CLI->configure(
         host: ($TCP_Server_CLI->host === '0.0.0.0')
            ? '127.0.0.1'
            : ($TCP_Server_CLI->host ?? 'localhost'),
         port: $TCP_Server_CLI->port ?? 80,
      );
      $TCP_Client_CLI->on(
         // on Connection connect
         connect: static function ($Socket, $Connection)
         use ($TCP_Client_CLI) 
         {
            Logger::$display = Logger::DISPLAY_MESSAGE;

            // ! Suite
            $Suite = SAPI::$Suite;
            $Suite->separate('HTTP Server');

            // @@ Iterate Test Cases
            // !
            $testFiles = SAPI::$tests[self::class] ?? [];
            $specIndex = 0;
            foreach ($testFiles as $index => $value) {
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
                     $requestData = $requestClosure("{$TCP_Client_CLI->host}:{$TCP_Client_CLI->port}");
                     $requestLength = strlen($requestData);
                     // @ Send Request to Server
                     $Connection::$output = $requestData;
                     if ( ! $Connection->writing($Socket, $requestLength) ) {
                        $failed = true;
                        break;
                     }
                     // ? Response
                     $timeout = 2;
                     $input = '';
                     // @ Get Response from Server
                     if ( $Connection->reading($Socket, $responseLength, $timeout) ) {
                        $input = $Connection::$input;
                     }

                     if ($Connection->expired) {
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
               $requestData = $request !== null
                  ? $request("{$TCP_Client_CLI->host}:{$TCP_Client_CLI->port}")
                  : '';
               $requestLength = strlen($requestData);
               // @ Send Request to Server
               $Connection::$output = $requestData;
               if ( ! $Connection->writing($Socket, $requestLength) ) {
                  $Test->fail();
                  break;
               }
               // ? Response
               $timeout = 2;
               $input = '';
               // @ Get Response from Server
               if ( $Connection->reading($Socket, $responseLength, $timeout) ) {
                  $input = $Connection::$input;
               }

               // @ Execute Test
               $Test->test($input);
               // @ Output Test result
               if (! $Connection->expired && $Test->passed) {
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
