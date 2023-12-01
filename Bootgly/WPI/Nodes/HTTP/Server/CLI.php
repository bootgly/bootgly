<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP\Server;


use Bootgly\ABI\Debugging\Data\Throwables\Exceptions;
use Bootgly\ACI\Logs\Logger;

use Bootgly\ACI\Tests;
use Bootgly\ACI\Tests\Tester;

use Bootgly\API\Projects;

use Bootgly\API\Server as SAPI;

use Bootgly\WPI\Interfaces\TCP;

use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Encoders\Encoder_Testing;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;


class CLI extends TCP\Server implements HTTP, Server
{
   // * Config
   // ...inherited from TCP\Server

   // * Data
   // ...inherited from TCP\Server

   // * Meta
   // ...inherited from TCP\Server
   public readonly array $versions;

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


   public function __construct (int $mode = self::MODE_MONITOR)
   {
      // * Config
      // ...inherited from TCP\Server

      // * Data
      // ...inherited from TCP\Server

      // * Meta
      $this->versions = [ // @ HTTP 1.1
         'min' => '1.1',
         'max' => '1.1' // TODO HTTP 2
      ];

      parent::__construct();

      // * Config
      $this->socket = ($this->ssl !== null
         ? 'https'
         : 'http'
      );

      // @ Configure Logger
      $this->Logger = new Logger(channel: 'Server.HTTP');

      // @ Configure Request
      self::$Request = new Request;
      // @ Configure Response
      self::$Response ??= new Response;
      // @ Configure Router
      self::$Router = new Router(static::class);

      // @
      self::$Decoder = new Decoder_;

      $this->mode = $mode;
      switch ($mode) {
         case self::MODE_TEST:

            self::$Encoder = new Encoder_Testing;
            break;
         default:
            self::$Encoder = new Encoder_;
      }
   }

   public function configure (
      string $host, int $port, int $workers, ? array $ssl = null
   )
   {
      parent::configure($host, $port, $workers, $ssl);

      try {
         if ($host === '0.0.0.0') {
            $this->domain ??= 'localhost';
         }

         // * Config
         $this->socket = ($this->ssl !== null
            ? 'https://'
            : 'http://'
         );
      } catch (\Throwable $Throwable) {
         Exceptions::report($Throwable);
      }
   }
   public static function boot (bool $production = true, bool $test = false)
   {
      if ($production) {
         try {
            SAPI::$production = Projects::CONSUMER_DIR . 'Bootgly/WPI/HTTP_Server_CLI-1.SAPI.php';
            self::$Encoder = new Encoder_;
         }
         catch (\Throwable $Throwable) {
            Exceptions::report($Throwable);
         }
      }

      if ($test) {
         try {
            self::$Encoder = new Encoder_Testing;

            // * Config
            $loader = __DIR__ . '/CLI/tests/@.php';

            // @ Reset Cache of Test boot file
            if ( function_exists('opcache_invalidate') ) {
               opcache_invalidate($loader, true);
            }
            clearstatcache(false, $loader);

            $files = (@require $loader)['tests'];
            SAPI::$tests[self::class] = Tests::list($files);
            // * Meta
            SAPI::$Tests[self::class] = [];

            foreach (SAPI::$tests[self::class] as $index => $case) {
               $file = __DIR__ . '/CLI/tests/' . $case . '.test.php';
               if (! file_exists($file) ) {
                  continue;
               }

               // @ Reset Cache of Test case file
               if ( function_exists('opcache_invalidate') ) {
                  opcache_invalidate($file, true);
               }
               clearstatcache(false, $file);

               // @ Load Test case from file
               try {
                  $spec = @require $file;
               } catch (\Throwable) {
                  $spec = null;
               }
               // @ Set Closure to SAPI Tests
               SAPI::$Tests[self::class][] = $spec;
            }
         }
         catch (\Throwable $Throwable) {
            Exceptions::report($Throwable);
         }
      }

      SAPI::boot(true);
   }

   protected static function test (TCP\Server $TCPServer)
   {
      Logger::$display = Logger::DISPLAY_NONE;

      self::boot(production: false, test: true);

      $TCPClient = new TCP\Client;
      $TCPClient->configure(
         host: $TCPServer->host === '0.0.0.0' ? '127.0.0.1' : $TCPServer->host,
         port: $TCPServer->port
      );
      $TCPClient->on(
         // on Connection connect
         connect: static function ($Socket, $Connection) use ($TCPClient) {
            Logger::$display = Logger::DISPLAY_MESSAGE;

            // @ Get test files
            $testFiles = SAPI::$tests[self::class] ?? [];

            $Tests = new Tester($testFiles);
            $Tests->separate('HTTP Server');

            // @ Run test cases
            foreach ($testFiles as $index => $value) {
               $spec = SAPI::$Tests[self::class][$index] ?? null;

               // @ Init Test
               $Test = $Tests->test($spec);

               if ($spec === null || count($spec) < 3) {
                  if ($Test) {
                     $Tests->skip();
                  }

                  continue;
               }

               // ! Server
               $responseLength = @$spec['response.length'] ?? null;
               // ! Client
               // ? Request
               $requestData = $spec['request']($TCPClient->host . ':' . $TCPClient->port);
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
               } else {
                  $Test->fail();
                  break;
               }
            }

            $Tests->summarize();

            // @ Reset CLI Logger
            Logger::$display = Logger::DISPLAY_MESSAGE;

            // @ Destroy Client Event Loop
            $TCPClient::$Event->destroy();
         }
      );
      $TCPClient->start();

      return true;
   }
}
