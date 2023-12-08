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


use function extract;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;
use function preg_replace;
use function preg_replace_callback_array;
use function sha1;

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
   private ? string $file = null;
   // Cache
   private ? File $Cache;
   // Pipeline
   // 1
   // 1.1
   private string $precompiled;
   // 1.2
   private string $compiled;
   // 1.3
   private string $postcompiled;
   // 2
   public string $output;


   public function __construct (string|File $raw)
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
         $contents = $raw->contents;

         $this->file = $raw->file;
      }
      $this->raw = $contents ?? $raw;

      $this->precompile(minify: false, unindent: true);
   }

   private function precompile (bool $minify = true, bool $unindent = false) : bool
   {
      $precompiled = $this->raw;

      try {
         $directives = self::$Directives->tokens;

         if ($minify) {
            $minified = preg_replace(
               "/(?<!\S)(@[$directives].*[:;])\s+/m",
               '$1',
               $precompiled
            );
            $precompiled = (string) $minified;
         }

         if ($unindent) {
            $unindented = preg_replace(
               "/^[\t ]+(@[$directives].*[:;])/m",
               '$1',
               $precompiled
            );
            $precompiled = (string) $unindented;
         }
      }
      catch (\Throwable) {
         $precompiled = '';
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
      } catch (\Throwable) {
         return false;
      }

      $this->compiled = $compiled;

      return true;
   }
   private function postcompile () : bool
   {
      $compiled = &$this->compiled;

      try {
         $postcompiled = preg_replace(
            '/\?>\n+<\?php /m',
            "\n",
            $compiled
         );
      } catch (\Throwable) {
         return false;
      }

      $this->postcompiled = $postcompiled;

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
         // @ Compile
         $compiled = $this->compile();
         if ($compiled === false || $this->compiled === '') {
            return false;
         }
         // @ Postcompile
         $this->postcompile();

         $Cache->open(File::CREATE_READ_WRITE_MODE);

         $Cache->write($this->postcompiled);
         if ($this->file) {
            $Cache->write(<<<TAG
            <?php // FILE: {$this->file} FILE; ?>
            TAG);
         }

         $Cache->close();
      }

      return true;
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
      }
      catch (\Throwable) {
         ob_end_clean();

         $output = '';
      }

      return $this->output = $output;
   }
}
