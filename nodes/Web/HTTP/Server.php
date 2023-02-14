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
      // * Config
      SAPI::$production = \Bootgly\HOME_DIR . 'projects/sapi.http.constructor.php';
      // * Data
      SAPI::$tests[self::class] = [
         '1.1-respond_hello_world',
         // ! Meta
         // Meta status
         '1.2-respond_with_status_302',
         '1.2.1-respond_with_status_500_no_body',
         // ! Header
         // Header changed
         '1.3-respond_with_header_content_text_plain',
         // ? Header \ Cookie
         '1.4-respond_with_header_cookies',
         // ! Content
         // @ send
         '1.5-send_in_json',
         // @ upload
         '1.6.1.1-upload_file_with_offset_length_1',
         '1.6.2.1-upload_file_with_range_1'
      ];
      // * Meta
      SAPI::$Tests[self::class] = [];

      foreach (SAPI::$tests[self::class] as $test) {
         $file = __DIR__ . '/Server/tests/' . $test . '.test.php';

         // @ Reset Cache of Test case file
         if ( function_exists('opcache_invalidate') )
            opcache_invalidate($file, true);
         clearstatcache(false, $file);

         // @ Load Test case from file
         try {
            $spec = @require $file;
         } catch (\Throwable) {
            $spec = false;
         }

         // @ Set Closure to SAPI Tests
         SAPI::$Tests[self::class][] = $spec;
      }

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
         #$Response->reset();

         $Response = Server::$Response = new Response;

         if (SAPI::$mode === SAPI::MODE_TEST) {
            SAPI::boot(true, self::class);
         }
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

   protected static function test ($TCPServer)
   {
      Logger::$display = Logger::DISPLAY_NONE;

      // @ Instance Client
      $TCPClient = new Client;
      $TCPClient->configure(
         host: $TCPServer->host === '0.0.0.0' ? '127.0.0.1' : $TCPServer->host,
         port: $TCPServer->port,
         workers: 0
      );
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
         connect: static function ($Socket, $Connection) use ($TCPServer, $TCPClient) {
            Logger::$display = Logger::DISPLAY_MESSAGE;

            // @ Get test -suite-
            $tests = SAPI::$tests[self::class];

            // @ Init test variables
            $passed = 0;
            $failed = 0;
            $total = count($tests);
            $started = microtime(true);

            $TCPServer->log('@\;');

            // @ Run test cases
            foreach ($tests as $index => $testing) {
               $spec = SAPI::$Tests[self::class][$index];

               if (! $spec || !is_array($spec) || count($spec) < 4) {
                  $failed++;
                  continue;
               };

               // ! Server
               $responseLength = (int) @$spec['response.length'] ?? 0;
               // ! Client
               $capi = $spec['capi'];     // @ Set Client API
               $requestData = $capi($TCPServer->host . ':' . $TCPServer->port);
               #$requestLength = strlen($requestData);

               if ( $Connection->write($Socket, $requestData) ) {
                  $expired = false;
                  $timeout = 2;
                  $latency = microtime(true);
   
                  $Connection::$input = '';

                  $buffer = '';
   
                  while (true) {
                     if ($responseLength > 0) {
                        if (strlen($buffer) >= $responseLength) {
                           break;
                        }
                     } else if ($Connection::$input !== '') {
                        break;
                     }

                     if (microtime(true) - $latency >= $timeout) { // @ Check Response Timeout
                        $expired = true;
                        break;
                     }

                     if ( $Connection->read($Socket) ) {
                        $buffer .= $Connection::$input;
                     }
                  }

                  // @ Execute assert
                  $assert = $spec['assert']; // @ Get assert
                  $except = $spec['except']; // @ Get except

                  ob_start();
                  $result = $assert($buffer);
                  $debugged = ob_get_clean();

                  // @ Show result
                  if ($result === true && $expired === false) {
                     $passed++;
                     $TCPServer->log(
                        "\033[1;37;42m PASS \033[0m - " 
                        . '✅ ' . "\033[90m" . $testing . "\033[0m" . PHP_EOL
                     );
                  } else {
                     $failed++;
                     $TCPServer->log(
                        "\033[1;37;41m FAIL \033[0m - " 
                        . '❌ ' . $testing . " -> \"\033[91m"
                        . $except() . "\033[0m\"" . ':'
                        . $debugged . PHP_EOL
                     );
                  }
               }
            }

            $finished = microtime(true);
            $spent = number_format(round($finished - $started, 5), 6);

            $TCPServer->log(<<<TESTS

            Tests: @:e: {$failed} failed @;, @:s:{$passed} passed @;, {$total} total
            Time: {$spent}s
            \033[90mRan all tests.\033[0m

            TESTS);

            Logger::$display = Logger::DISPLAY_MESSAGE;

            // @ Stop Client (stop Event Loop and destroy instance)
            $TCPClient::$Event->destroy();
         }
      );
      $TCPClient->start();

      return true;
   }
}
