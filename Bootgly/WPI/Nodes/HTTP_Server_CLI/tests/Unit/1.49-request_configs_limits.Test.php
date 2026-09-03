<?php

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


/**
 * Every `Request\Configs` field owns one inbound limit. The mapping is
 * flat and repetitive, which is exactly where a swapped pair (fields ↔ files,
 * file size ↔ body size) survives every functional test: the server still
 * boots, still serves, and rejects at the wrong threshold.
 *
 * Distinct values per field, so a swap cannot pass.
 */
return new Test(
   description: 'Request\Configs seeds every inbound limit with its own value',
   test: new Assertions(Case: function (): Generator {
      // ! Statics survive the suite: snapshot every one adopt() writes
      $oldFileSize = Request::$maxFileSize;
      $oldBodySize = Request::$maxBodySize;
      $oldFieldSize = Request::$maxMultipartFieldSize;
      $oldHeaderSize = Request::$maxMultipartHeaderSize;
      $oldFields = Request::$maxMultipartFields;
      $oldFiles = Request::$maxMultipartFiles;
      $oldOnDisk = Downloads::$maxBytesOnDisk;
      $oldHealth = HTTP_Server_CLI::$health;
      $oldHTTP2 = HTTP_Server_CLI::$enableHTTP2;
      $OldProtocols = TCP_Server_CLI::$Protocols;

      try {
         $Server = new HTTP_Server_CLI(Mode: Modes::Test);
         $Server->configure(
            new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1),
            new HTTP_Server_CLI\Request\Configs(
               maxFileSize: 111,
               maxBodySize: 222,
               maxMultipartFieldSize: 333,
               maxMultipartHeaderSize: 444,
               maxMultipartFields: 555,
               maxMultipartFiles: 666,
               downloadsMaxBytesOnDisk: 777
            )
         );

         // @@ A) Each field reaches its own static — no swapped pair survives
         $wired = [
            'maxFileSize' => Request::$maxFileSize === 111,
            'maxBodySize' => Request::$maxBodySize === 222,
            'maxMultipartFieldSize' => Request::$maxMultipartFieldSize === 333,
            'maxMultipartHeaderSize' => Request::$maxMultipartHeaderSize === 444,
            'maxMultipartFields' => Request::$maxMultipartFields === 555,
            'maxMultipartFiles' => Request::$maxMultipartFiles === 666,
            'downloadsMaxBytesOnDisk' => Downloads::$maxBytesOnDisk === 777,
         ];
         $crossed = array_keys($wired, false, true);

         yield assert(
            assertion: $crossed === [],
            description: 'every Request\Configs field seeds its own limit — crossed: '
               . var_export($crossed, true)
         );

         // @@ B) A re-configuration without the limits keeps them
         $Server->configure(new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1));

         yield assert(
            assertion: Request::$maxBodySize === 222 && Downloads::$maxBytesOnDisk === 777,
            description: 'omitting Request\Configs keeps the configured limits — '
               . var_export([Request::$maxBodySize, Downloads::$maxBytesOnDisk], true)
         );

         // @@ C) A partial Request\Configs moves only the field it carries —
         //       every other limit keeps whatever is currently configured
         $Server->configure(
            new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1),
            new HTTP_Server_CLI\Request\Configs(maxBodySize: 888)
         );

         $untouched = [
            'maxFileSize' => Request::$maxFileSize === 111,
            'maxMultipartFieldSize' => Request::$maxMultipartFieldSize === 333,
            'maxMultipartHeaderSize' => Request::$maxMultipartHeaderSize === 444,
            'maxMultipartFields' => Request::$maxMultipartFields === 555,
            'maxMultipartFiles' => Request::$maxMultipartFiles === 666,
            'downloadsMaxBytesOnDisk' => Downloads::$maxBytesOnDisk === 777,
         ];
         $moved = array_keys($untouched, false, true);

         yield assert(
            assertion: Request::$maxBodySize === 888 && $moved === [],
            description: 'a partial Request\Configs moves only its own limit — moved: '
               . var_export($moved, true)
         );
      }
      finally {
         Request::$maxFileSize = $oldFileSize;
         Request::$maxBodySize = $oldBodySize;
         Request::$maxMultipartFieldSize = $oldFieldSize;
         Request::$maxMultipartHeaderSize = $oldHeaderSize;
         Request::$maxMultipartFields = $oldFields;
         Request::$maxMultipartFiles = $oldFiles;
         Downloads::$maxBytesOnDisk = $oldOnDisk;
         HTTP_Server_CLI::$health = $oldHealth;
         HTTP_Server_CLI::$enableHTTP2 = $oldHTTP2;
         TCP_Server_CLI::$Protocols = $OldProtocols;
      }
   })
);
