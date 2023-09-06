<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Templates;


use Closure;
use Throwable;

use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates;


class Template implements Templates
{
   // * Config
   public Renderization $Renderization;

   // * Data
   protected array $directives;
   public string|File $raw;

   // * Meta
   // Cache
   private ? File $Cache;
   // Output
   private string $compiled;
   public string $output;


   public function __construct (string|File $raw)
   {
      // * Config
      $this->Renderization = Renderization::FILE_HASHED_MODE->set();

      // * Data
      $this->directives = [];
      $this->raw = $raw;

      // * Meta
      // Cache
      $this->Cache = null;
      // Output
      $this->compiled = '';
      $this->output = '';

      // @
      // directives
      $resource = 'directives/';
      $bootables = require($resource . '@.php');
      $files = $bootables['files'];
      foreach ($files as $file) {
         $directives = require($resource . $file . '.php');
         foreach ($directives as $directive => $Closure) {
            $this->directives[$directive] = $Closure;
         }
      }
      // raw
      $raw = $this->raw;
      if ($raw instanceof File) {
         $this->raw = $raw->contents;
      }
   }

   public function extend (string $pattern, Closure $Callback)
   {
      $this->directives[$pattern] ??= $Callback;
   }

   private function compile () : bool
   {
      // * Data
      $directives = $this->directives;
      $raw        = $this->raw;

      // @
      try {
         $compiled = preg_replace_callback_array(
            pattern: $directives,
            subject: $raw,
         );
      } catch (Throwable) {
         return false;
      }

      $this->compiled = $compiled;

      return true;
   }
   private function cache () : bool
   {
      // * Data
      $raw = $this->raw;
      // * Meta
      $Cache = $this->Cache = new File(
         BOOTGLY_WORKING_DIR . 'workdata/cache/views/' . sha1($raw) . '.php'
      );
      $Cache->convert = false;

      // @ Cache
      $created = $Cache->create(recursively: true);
      if ($created) {
         $compiled = $this->compile();
         if ($compiled) {
            $Cache->open(File::CREATE_READ_WRITE_MODE);
            $Cache->write($this->compiled);
            $Cache->close();
         }
      }

      return true;
   }

   public function debug ()
   {
      debug('<code>' . htmlspecialchars($this->compiled) . '</code>');
   }
   public function render (array $parameters = [], $mode = Renderization::FILE_HASHED_MODE) : bool
   {
      // @
      try {
         extract($parameters);

         ob_start();

         switch ($mode) {
            case Renderization::FILE_HASHED_MODE:
               $this->cache();
               include (string) $this->Cache->file;
               break;
            case Renderization::JIT_EVAL_MODE:
               $this->compile();
               eval('?>' . $this->compiled);
               break;
         }

         $this->output = ob_get_clean();
      } catch (Throwable $Throwable) {
         ob_end_clean();

         debug(
            'Error!',
            $Throwable->getMessage(),
            $Throwable->getLine()
         );

         $this->output = '';
      }

      return true;
   }
}


// * Config
enum Renderization
{
   use \Bootgly\ABI\Configs\Set;

   case FILE_HASHED_MODE;
   case JIT_EVAL_MODE;
}
