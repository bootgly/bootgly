<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP;

use Bootgly\Logger;
use Bootgly\SAPI;
use Bootgly\Web;

use Bootgly\Web\TCP;
use Bootgly\Web\TCP\Server\Packages;
use Bootgly\Web\TCP\Client;

use Bootgly\Web\protocols\HTTP;

use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response;
use Bootgly\Web\HTTP\Server\Router;


class Server extends TCP\Server implements HTTP
{
   public static Web $Web;

   // * Meta
   public readonly array $versions;

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


   public function __construct (Web $Web)
   {
      self::$Web = $Web;

      // * Meta
      $this->versions = [ // @ HTTP 1.1
         'min' => '1.1',
         'max' => '1.1' // TODO HTTP 2
      ];

      parent::__construct();

      self::$Request = new Request;
      self::$Response = new Response;
      self::$Router = new Router;

      // @ Init Data callback
      if ($this->status === self::STATUS_BOOTING) {
         self::$Request->Meta;
         self::$Request->Header;
         self::$Request->Content;

         // TODO initial Response Data API
         #self::$Response->Meta;
         #self::$Response->Header;
         #self::$Response->Content;
      }
   }

   public static function boot ()
   {
      SAPI::$file = \Bootgly\HOME_DIR . 'projects/sapi.http.constructor.php';
      SAPI::boot(true);
   }

   public static function decode (Packages $Package, string &$buffer, int $length)
   {
      static $inputs = []; // @ Instance local cache

      // @ Check local cache and return
      if ( $length <= 512 && isSet($inputs[$buffer]) ) {
         #Server::$Request = $inputs[$buffer];
         return $length;
      }

      // @ Instance callbacks
      $Request = Server::$Request;

      // ! Request
      // @ Check if Request Content is waiting data
      if ($Request->Content->waiting) {
         // @ Finish filling the Request Content raw with TCP read buffer
         $Content = &$Request->Content;

         $Content->raw .= $buffer;
         $Content->downloaded += $length;

         if ($Content->length > $Content->downloaded) {
            return 0;
         }

         $Content->waiting = false;

         return $Content->length;
      }

      // @ Handle Package cache
      if ($Package->changed) {
         // @ Reset Globals
         $_POST = [];
         $_FILES = [];
         // @ Instance new Request
         $Request = Server::$Request = new Request;
         // Reset Request
         // $Request->reset();
      }

      // @ Write to local cache
      if ($length <= 512) {
         #$inputs[$buffer] = clone $Request;
         $inputs[$buffer] = $length;

         if (count($inputs) > 1) { // @ Cache only the last Request?
            unSet($inputs[key($inputs)]);
         }
      }

      // ! Request
      // @ Boot HTTP Request
      return $Request->boot($Package, $buffer, $length); // @ Return Request Content length
   }
   public static function encode (Packages $Package, &$length)
   {
      // @ Instance callbacks
      $Request = Server::$Request;
      $Response = Server::$Response;

      // @ Handle Package cache
      if ($Package->changed) {
         $Response->reset();
      } else if ($Response->Content->raw) {
         // TODO check if Response raw is static or dynamic
         return $Response->raw;
      }

      // ! Response
      // @ Try to Invoke SAPI Closure
      try {
         (SAPI::$Handler)($Request, $Response, Server::$Router);
      } catch (\Throwable) {
         $Response->Meta->status = 500; // @ Set 500 HTTP Server Error Response

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

   protected static function test ($Server)
   {
      Logger::$display = Logger::DISPLAY_NONE;

      // TODO with HTTP Client
      $TCPClient = new Client;

      if ($Server->host === '0.0.0.0') {
         $host = '127.0.0.1';
      }

      $TCPClient->configure(host: $host ?? $Server->host, port: $Server->port, workers: 0);

      $TCPClient->on(
         // on Worker instance
         instance: function ($Client) {
            // @ Connect to Server
            $Socket = $Client->connect();
      
            if ($Socket) {
               $Client::$Event->loop();
            }
         },
         // on Connection connect
         connect: static function ($Socket, $Connection) use ($Server, $TCPClient) {
            Logger::$display = Logger::DISPLAY_MESSAGE;

            // @ Set test -suite-
            // TODO set in Console Wizard
            $tests = [
               '1.1-respond_hello_world',
            ];

            // @ Init test variables
            $passed = 0;
            $failed = 0;
            $total = count($tests);
            $started = microtime(true);

            // @ Run test cases
            foreach ($tests as $testing) {
               // @ Construct Test case file path
               $file = __DIR__ . '/Server/tests/' . $testing . '.test.php';
               // @ Reset Cache of Test case file
               if ( function_exists('opcache_invalidate') )
                  opcache_invalidate($file, true);
               clearstatcache(false, $file);
               // @ Load Test case from file
               $test = require $file;

               $sapi = $test['sapi'];     // @ Set Server API
               $capi = $test['capi'];     // @ Set Client API
               $assert = $test['assert']; // @ Get assert
               $except = $test['except']; // @ Get except

               SAPI::$Handler = $sapi;
               $Connection::$output = $capi(); // CAPI::$Handler = $capi;

               if ( $Connection->write($Socket) ) {
                  $timeout = 2;
                  $latency = time();
   
                  $Connection::$input = '';
   
                  while ($Connection::$input === '') {
                     if (time() - $latency >= $timeout) { // Response Timeout
                        break;
                     }
   
                     if ( $Connection->read($Socket) ) {
                        $result = $assert($Connection::$input) || $except();

                        if ($result === true) {
                           $passed++;

                           $Server->log('[PASS] - ' . $testing . PHP_EOL, self::LOG_SUCCESS_LEVEL);
                        } else {
                           $failed++;

                           $Server->log('[FAIL] - ' . $result . PHP_EOL, self::LOG_ERROR_LEVEL);
                        }

                        break;
                     }
                  }
               }
            }

            $finished = microtime(true);
            $spent = number_format(round($finished - $started, 5), 6);

            $Server->log(<<<TESTS

            Tests: @:e: {$failed} failed @;, @:s:{$passed} passed @;, {$total} total
            Time: {$spent}s
            \033[90mRan all tests.\033[0m@\;

            TESTS);

            Logger::$display = Logger::DISPLAY_MESSAGE;

            // @ Reset SAPI (in master?)
            SAPI::boot(true);

            // @ Stop Client (destroy instance)
            $TCPClient::$Event->destroy();
         }
      );
      $TCPClient->start();

      return true;
   }
}
