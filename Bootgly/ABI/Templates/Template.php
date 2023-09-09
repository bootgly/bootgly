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


use Throwable;

use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates;


class Template implements Templates
{
   // * Config
   public Renderization $Renderization;

   // * Data
   public static Directives $Directives;
   public readonly string|File $raw;

   // * Meta
   // Cache
   private ? File $Cache;
   // Pipeline
   private string $compiled;
   public string $output;


   public function __construct (string|File $raw)
   {
      // * Config
      $this->Renderization = Renderization::FILE_HASHED_MODE->set();

      // * Data
      // @

      // * Meta
      // Cache
      $this->Cache = null;
      // Pipeline
      $this->compiled = '';
      $this->output = '';

      // @
      // $Directives
      self::$Directives ??= new Directives;
      // $raw
      if ($raw instanceof File) {
         $raw = $raw->contents;
      }
      // @ Preprocess
      // Minify
      $raw = $this->minify($raw);
      // @ Set
      $this->raw = $raw;
   }

   private function minify (string $compiled) : string
   {
      $directives = self::$Directives->tokens;

      $minified = preg_replace(
         "/(?<!\S)(@[$directives].*[:;])\s+/m",
         '$1',
         $compiled
      );

      return (string) $minified;
   }
   private function compile () : bool
   {
      // * Data
      $Directives = self::$Directives;
      $raw        = $this->raw;

      // @
      try {
         $compiled = preg_replace_callback_array(
            pattern: $Directives->directives,
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
   public function render (array $parameters = [], Renderization $mode = Renderization::FILE_HASHED_MODE) : string|false
   {
      // @
      try {
         extract($parameters);

         ob_start();

         switch ($mode) {
            case Renderization::FILE_HASHED_MODE:
               $this->cache();
               include $this->Cache->file;
               break;
            case Renderization::JIT_EVAL_MODE:
               $this->compile();
               eval('?>' . $this->compiled);
               break;
         }

         $output = ob_get_clean();
      } catch (Throwable $Throwable) {
         ob_end_clean();

         debug(
            'Error!',
            $Throwable->getMessage(),
            $Throwable->getLine()
         );

         $output = '';
      }

      return $this->output = $output;
   }
}


// * Config
enum Renderization
{
   use \Bootgly\ABI\Configs\Set;

   case FILE_HASHED_MODE;
   case JIT_EVAL_MODE;
}
