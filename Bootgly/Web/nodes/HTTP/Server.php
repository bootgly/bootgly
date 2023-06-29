<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\nodes\HTTP;


use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Tester;
use Bootgly\API\Project;
use Bootgly\API\Server as SAPI;

use Bootgly\Web\interfaces\TCP;
use Bootgly\Web\interfaces\TCP\Server\Packages;
use Bootgly\Web\interfaces\TCP\Client;
use Bootgly\Web\modules\HTTP;
use Bootgly\Web\nodes\HTTP\Server\Request;
use Bootgly\Web\nodes\HTTP\Server\Response;
use Bootgly\Web\modules\HTTP\Server\Router;


class Server extends TCP\Server implements HTTP
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

      self::$Request = new Request;
      self::$Request->Meta;
      self::$Request->Header;
      self::$Request->Content;

      self::$Response = new Response;
      // TODO initial Response Data API
      #self::$Response->Meta;
      #self::$Response->Header;
      #self::$Response->Content;

      self::$Router = new Router(static::class);
   }

   public static function boot (bool $production = true, bool $test = false)
   {
      // * Config
      if ($production) {
         SAPI::$production = Project::PROJECTS_DIR . 'Bootgly/Web/HTTP-Server.API.php';
      }

      // * Data
      if ($test) {
         try {
            // * Config
            $loader = __DIR__ . '/Server/tests/@.php';

            // @ Reset Cache of Test boot file
            if ( function_exists('opcache_invalidate') ) {
               opcache_invalidate($loader, true);
            }
            clearstatcache(false, $loader);

            $files = (@require $loader)['files'];
            SAPI::$tests[self::class] = Tester::list($files);
            // * Meta
            SAPI::$Tests[self::class] = [];

            foreach (SAPI::$tests[self::class] as $index => $case) {
               $file = __DIR__ . '/Server/tests/' . $case . '.test.php';
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

   public static function decode (Packages $Package, string $input, int $length)
   {
      static $inputs = []; // @ Instance local cache

      // @ Check local cache and return
      if ( $length <= 512 && isSet($inputs[$input]) ) {
         Server::$Request = $inputs[$input];
         return $length;
      }

      // @ Instance callbacks
      $Request = Server::$Request;

      // TODO move to another decoder
      // @ Check if Request Content is waiting data
      if ($Request->Content->waiting) {
         // @ Finish filling the Request Content raw with TCP read buffer
         $Content = &$Request->Content;

         $Content->raw .= $input;
         $Content->downloaded += $length;

         if ($Content->length > $Content->downloaded) {
            return 0;
         }

         $Content->waiting = false;

         return $Content->length;
      }

      // @ Handle Package cache
      if ($Package->changed) {
         $Request = Server::$Request = new Request;
      }

      // @ Boot HTTP Request
      $requestLength = $Request->boot($Package, $input, $length);

      // @ Write to local cache
      if ($length <= 512) {
         $inputs[$input] = clone $Request;

         if (count($inputs) > 512) {
            unset($inputs[key($inputs)]);
         }
      }

      return $requestLength;
   }
   public static function encode (Packages $Package, &$length)
   {
      // @ Instance callbacks
      $Request  = Server::$Request;
      $Response = Server::$Response;
      $Router   = Server::$Router;

      // @ Perform test mode
      if (SAPI::$mode === SAPI::MODE_TEST) {
         $Response = new Response;
         $Response->Header->preset('Date', null);

         SAPI::boot(reset: true, base: self::class);
      }

      // ! Response
      // @ Try to Invoke API Closure
      try {
         (SAPI::$Handler)($Request, $Response, $Router);
      } catch (\Throwable $Throwable) {
         $Response->Meta->status = 500; // @ Set 500 HTTP Server Error Response

         debug($Throwable->getMessage());

         if ($Response->Content->raw === '') {
            $Response->Content->raw = ' ';
         }
      }
      // @ Check if Request Content is waiting data
      if ($Request->Content->waiting) {
         return '';
      }
      // @ Output/Stream HTTP Response
      return $Response->output($Package, $length); // @ Return Response raw
   }

   protected static function test (TCP\Server $TCPServer)
   {
      Logger::$display = Logger::DISPLAY_NONE;

      self::boot(production: false, test: true);

      $TCPClient = new Client;
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
               $requestData = $spec['capi']($TCPClient->host . ':' . $TCPClient->port);
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
