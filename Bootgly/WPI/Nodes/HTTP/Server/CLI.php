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


use Bootgly\ACI\Logs\Logger;

use Bootgly\ACI\Tests;
use Bootgly\ACI\Tests\Tester;

use Bootgly\API\Project;

use Bootgly\API\Server as SAPI;

use Bootgly\WPI\Interfaces\TCP;

use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Decoders\_Decoder;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Encoders\_Encoder;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;


class CLI extends TCP\Server implements HTTP, Server
{
   // * Config
   // ...

   // * Data
   // ...

   // * Meta
   public readonly array $versions;

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


   public function __construct ()
   {
      // * Meta
      $this->versions = [ // @ HTTP 1.1
         'min' => '1.1',
         'max' => '1.1' // TODO HTTP 2
      ];

      parent::__construct();

      // @ Configure Logger
      $this->Logger = new Logger(channel: 'Server.HTTP');

      // @ Configure Request
      self::$Request = new Request;
      // @ Configure Response
      self::$Response = new Response;
      // @ Configure Router
      self::$Router = new Router(static::class);

      // @
      self::$Decoder = new _Decoder;
      self::$Encoder = new _Encoder;
   }

   public static function boot (bool $production = true, bool $test = false)
   {
      // * Config
      if ($production) {
         SAPI::$production = Project::CONSUMER_DIR . 'Bootgly/WPI/HTTP-Server.API.php';
      }

      // * Data
      if ($test) {
         try {
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
         } catch (\Throwable) {
            // ...
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
            $testFiles = SAPI::$tests[self::class];

            $Tests = new Tester($testFiles);
            $Tests->separate('HTTP Server');

            // @ Run test cases
            foreach ($testFiles as $index => $value) {
               $spec = SAPI::$Tests[self::class][$index] ?? null;

               // @ Init Test
               $Test = $Tests->test($spec);

               if ($spec === null || count($spec) < 4) {
                  if ($Test) {
                     $Tests->skip();
                  }

                  continue;
               }

               $Test->separate(); // @ Output Test separators

               // ! Server
               $responseLength = @$spec['response.length'] ?? null;
               // ! Client
               // ? Request
               $requestData = $spec['request']($TCPClient->host . ':' . $TCPClient->port);
               $requestLength = strlen($requestData);
               // @ Send Request to Server
               $Connection::$output = $requestData;
               if ( ! $Connection->write($Socket, $requestLength) ) {
                  $Test->fail();
                  break;
               }

               // ? Response
               $timeout = 2;
               $input = '';
               // @ Get Response from Server
               if ( $Connection->read($Socket, $responseLength, $timeout) ) {
                  $input = $Connection::$input;
               }

               // @ Execute Test
               $Test->test($input);
               // @ Output Test result
               if (! $Connection->expired && $Test->success) {
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