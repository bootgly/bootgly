<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use function date;
use function time;
use function array_walk_recursive;
use function clearstatcache;
use function is_file;
use function unlink;
use AllowDynamicProperties;

use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Downloader;


#[AllowDynamicProperties]
class Request extends Server\Request
{
   use Raw;


   // * Config
   // ..

   // * Data
   /** @var array<string> */
   protected array $_SERVER;

   // * Metadata
   public readonly string $on;
   public readonly string $at;
   public readonly int $time;

   private Downloader $Downloader;


   public function __construct ()
   {
      $this->Header = new Header;
      $this->Body = new Body;

      // * Config
      $this->base = '';
      // TODO pre-defined filters
      // $this->Filter->sanitize(...) | $this->Filter->validate(...)

      // * Data
      // ... dynamically
      $_POST = [];
      #$_FILES = []; // Reseted on __destruct only
      $_SERVER = [];

      // * Metadata
      $this->on = date("Y-m-d");
      $this->at = date("H:i:s");
      $this->time = time();


      $this->Downloader = new Downloader($this);
   }

   public function __clone ()
   {
      $this->_SERVER = $_SERVER;
   }
   public function reboot (): void
   {
      if ( isSet($this->_SERVER) ) {
         $_SERVER = $this->_SERVER;
      }
   }
   /**
    * Download the request body data (files and fields).
    *
    * @return array<array<string>>|null The request method.
    */
   public function download (? string $key = null): array|null
   {
      // ?
      $boundary = $this->Body->parse(
         content: 'Form-data',
         type: $this->Header->get('Content-Type')
      );

      // @ Set FILES data
      if ($boundary) {
         $this->Downloader->downloading($boundary);
      }

      // :
      if ($key === null) {
         return $_FILES;
      }

      if ( isSet($_FILES[$key]) ) {
         return $_FILES[$key];
      }

      return null;
   }
   /**
    * Receive the request body data.
    *
    * @return array<array<string>>|string|null The request method.
    */
   public function receive (? string $key = null): array|string|null
   {
      $parsed = $this->Body->parse(
         content: 'raw',
         type: $this->Header->get('Content-Type')
      );

      // @ Set POST data
      if ($parsed) {
         $this->Downloader->downloading($parsed);
      }

      // : parsed $_POST || null
      if ($key === null) {
         return $_POST;
      }

      if ( isSet($_POST[$key]) ) {
         return $_POST[$key];
      }

      return null;
   }

   public function __destruct ()
   {
      // @ Delete files downloaded by server in temp folder
      if (empty($_FILES) === false) {
         // @ Clear cache
         clearstatcache();

         // @ Delete temp files
         array_walk_recursive($_FILES, function ($value, $key) {
            if ($key === 'tmp_name' && is_file($value) === true) {
               unlink($value);
            }
         });

         // @ Reset $_FILES
         $_FILES = [];
      }
   }
}
