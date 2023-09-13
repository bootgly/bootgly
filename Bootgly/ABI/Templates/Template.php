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
   private Iterators $Iterators;

   // * Config
   // ...

   // * Data
   public static Directives $Directives;
   public readonly string|File $raw;

   // * Meta
   // Cache
   private ? File $Cache;
   // Pipeline
   private string $precompiled;
   private string $compiled;
   public string $output;


   public function __construct (string|File $raw, bool $minify = true)
   {
      $this->Iterators = new Iterators; // @ Used to preload Iterators

      // * Config
      // ...

      // * Data
      // @

      // * Meta
      // Cache
      // ...
      // Pipeline
      $this->output = '';

      // @
      // $Directives
      self::$Directives ??= new Directives;
      // $raw
      if ($raw instanceof File) {
         $raw = $raw->contents;
      }
      $this->raw = $raw;

      $this->precompile(minify: $minify);
   }

   private function precompile (bool $minify = true) : bool
   {
      $precompiled = $this->raw;

      try {
         if ($minify) {
            $directives = self::$Directives->tokens;
            $minified = preg_replace(
               "/(?<!\S)(@[$directives].*[:;])\s+/m",
               '$1',
               $precompiled
            );
            $precompiled = (string) $minified;
         }
      } catch (Throwable $Throwable) {
         debug($Throwable);
      }

      $this->precompiled = $precompiled;

      return true;
   }
   private function compile () : bool
   {
      $Directives  = &self::$Directives;
      $precompiled = &$this->precompiled;

      if ($precompiled === '') {
         return false;
      }

      try {
         $compiled = preg_replace_callback_array(
            pattern: $Directives->directives,
            subject: $precompiled,
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
      if ($created || $Cache->contents === '') {
         $compiled = $this->compile();

         if ($compiled === false || $this->compiled === '') {
            return false;
         }

         $Cache->open(File::CREATE_READ_WRITE_MODE);
         $Cache->write($this->compiled);
         $Cache->close();
      }

      return true;
   }

   public function debug ()
   {
      debug('<code>' . htmlspecialchars($this->compiled) . '</code>');
   }
   public function render (array $parameters = []) : string|false
   {
      // @
      try {
         $cached = $this->cache();
         if ($cached === false) {
            return false;
         }

         $started = ob_start();
         if ($started === false) {
            @ob_end_clean();
            return false;
         }

         (static function ($__file__, $parameters) {
            extract($parameters);
            include $__file__;
         })(
            $this->Cache->file,
            $parameters
         );
 
         $output = @ob_get_clean();
      } catch (Throwable $Throwable) {
         ob_end_clean();

         debug(
            'Error!',
            $Throwable->getMessage(),
            $Throwable->getLine(),
            $Throwable->getFile()
         );

         $output = '';
      }

      return $this->output = $output;
   }
}
