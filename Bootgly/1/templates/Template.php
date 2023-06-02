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


use Bootgly\streams\File;


class Template
{
   // * Config
   public array $parameters;
   public Mode $Execution;

   // * Data
   protected array $directives;
   public string $raw;
   public string $output;

   // * Meta
   private string $compiled;
   // Cache
   private ? File $Output;


   public function __construct ()
   {
      // * Config
      $this->parameters = [];
      // execute
      $this->Execution = Mode::REQUIRE->set();

      // * Data
      $this->directives = [];
      $this->raw = '';
      $this->output = '';

      // * Meta
      $this->compiled = '';
      // cache
      $this->Output = null;


      $this->boot();
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

   private function compile (? string $raw = null)
   {
      $compiled = preg_replace_callback_array(
         $this->directives,
         $raw ?? $this->raw
      );

      $this->compiled = $compiled;
   }
   public function render () : self
   {
      $this->compile();

      $this->cache();

      #$this->debug();
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
      debug('<code>'.htmlspecialchars($this->compiled).'</code>');
   }
   private function execute () : bool
   {
      try {
         extract($this->parameters);

         ob_start();

         $Mode = $this->Execution->get();
         match ($Mode) {
            Mode::REQUIRE => require (string) $this->Output,
            Mode::EVAL => eval('?>'.$this->compiled),
            default => null
         };

         $this->output = ob_get_clean();
      } catch (\Throwable $Throwable) {
         ob_end_clean();

         debug(
            'Error!',
            $Throwable->getMessage(),
            $Throwable->getLine()
         );

         $this->output = '';
      } finally {
         return true;
      }
   }
}


// * Config
enum Mode
{
   use \Bootgly\Set;

   case REQUIRE;
   case EVAL;
}
