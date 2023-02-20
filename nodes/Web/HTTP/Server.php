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

// extend
use Bootgly\CLI\_\ {
   Tester\Tests
};
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
         // status
         '1.2-respond_with_status_302',
         '1.2.1-respond_with_status_500_no_body',
         // ! Header
         // Content-Type
         '1.3-respond_with_content_type_text_plain',
         // ? Header \ Cookie
         '1.4-respond_with_set_cookies',
         // ! Content
         // @ send
         '1.5-send_content_in_json',
         // @ upload
         '1.6-upload_small_file',
         '1.6.1.1-upload_file_with_offset_length_1',
         '1.6.2.1-upload_file_with_range-requests_1',
         '1.6.3-upload_large_file'
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
         $_POST = [];
         $_FILES = [];

         $Request = Server::$Request = new Request;

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
         $Response = Server::$Response = new Response;

         #$Response->reset();

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

      $TCPClient = new Client;
      $TCPClient->configure(
         host: $TCPServer->host === '0.0.0.0' ? '127.0.0.1' : $TCPServer->host,
         port: $TCPServer->port,
         workers: 0
      );
      $TCPClient->on(
         // on Worker instance 
         instance: function ($Client) {
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

            // @ Init Tests
            $Tests = new Tests($tests);

            $TCPServer->log('@\;');

            // @ Run test cases
            foreach ($tests as $index => $value) {
               $spec = SAPI::$Tests[self::class][$index];

               // @ Init Test
               $Test = $Tests->test($spec);

               if (! $spec || ! is_array($spec) || count($spec) < 4) {
                  $Test->skip();
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

               // @ Execute Test assert
               $Test->assert($input);

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
