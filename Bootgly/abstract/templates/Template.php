<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\templates;


use Bootgly\File;


class Template // TODO refactor
{
   // * Config
   public array $parameters;

   // * Data
   protected array $directives;
   public string $raw;

   // * Meta
   public string $compiled;

   public ? File $Output;
   public string $output;


   public function __construct ()
   {
      // * Config
      Config::EXECUTE_MODE_REQUIRE;

      $this->parameters = [];

      // * Data
      $this->directives = [];
      $this->raw = '';

      // * Meta
      $this->compiled = '';

      $this->Output = null;
      $this->output = '';


      $this->boot();
   }
   public function __get ($name)
   {
      return $this->$name;
   }
   public function __set ($name, $value)
   {
      $this->$name = $value;
   }

   protected function boot ()
   {
      $resource = 'directives/';

      $bootables = require $resource . '@.php';

      $files = $bootables['files'];
      foreach ($files as $file) {
         $directives = require $resource . $file . '.php';

         foreach ($directives as $directive => $Closure) {
            $this->directives[$directive] = $Closure;
         }
      }
   }
   public function extend (string $pattern, \Closure $Callback)
   {
      $this->directives[$pattern] ??= $Callback;
   }
   #public function parse () {}

   private function compile ()
   {
      $compiled = preg_replace_callback_array($this->directives, $this->raw);

      $this->compiled = $compiled;
   }
   public function render () : self
   {
      $this->compile();

      $this->cache();

      // $this->debug();
      $this->execute();

      return $this;
   }

   private function cache () : bool
   {
      $this->Output = new File(
         BOOTGLY_WORKABLES_DIR .
         'workspace/cache/' .
         'views/' .
         sha1($this->raw) .
         '.php'
      );

      if ($this->Output->exists) {
         return false;
      }

      $this->Output->open('w+');
      $this->Output->write($this->compiled);
      $this->Output->close();

      return true;
   }

   public function debug ()
   {
      Debug('<code>'.htmlspecialchars($this->compiled).'</code>');
   }
   private function execute (? Config $Mode = null) : bool
   {
      if ($Mode === null) {
         $Mode = Config::EXECUTE_MODE_REQUIRE;
      }

      try {
         extract($this->parameters);

         ob_start();
         switch ($Mode) {
            case Config::EXECUTE_MODE_REQUIRE:
               require (string) $this->Output; break;
            case Config::EXECUTE_MODE_EVAL:
               eval('?>'.$this->compiled); break;
         }
         $this->output = ob_get_clean();

         return true;
      } catch (\Throwable $Throwable) {
         Debug('Error!', $Throwable->getMessage(), $Throwable->getLine());
         $this->output = '';
      }
   }
}


enum Config
{
   use \Bootgly\Set;


   case EXECUTE_MODE_REQUIRE;
   case EXECUTE_MODE_EVAL;
   const EXECUTE_MODE_DEFAULT = self::EXECUTE_MODE_EVAL;

   case COMPILE_ECHO;
   case COMPILE_IF;
   case COMPILE_FOREACH;
   case COMPILE_FOR;
   case COMPILE_WHILE;
}
