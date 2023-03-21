<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use Bootgly\Debugger;
use Bootgly\File;
// @
use Bootgly\Template\Config;


class Template
{
   // * Config
   private array $compilables;
   private array $parameters;

   // * Data
   public string $raw;

   // * Meta
   public array $compiled;
   public array $patterns;
   public string $output;
   public ? File $Output;


   public function __construct ()
   {
      /*
      if ($_SERVER['HTTP_HOST'] === 'bootgly.slayer.tech') {
         Debugger::$debug = true;
         Debug(Config::get());
      }
      */

      // * Config
      Config::EXECUTE_MODE_REQUIRE;

      $this->compilables = [
         'echo',
         // ! Directives
         // ? Conditionals
         'if',
         // ? Loops
         'foreach',
         'for',
         'while'
      ];
      $this->parameters = [];

      // * Data
      $this->raw = '';

      // * Meta
      $this->compiled = [];
      $this->patterns = [];
      $this->output = '';
      $this->Output = null;
   }
   public function __get ($name)
   {
      return $this->$name;
   }
   public function __set ($name, $value)
   {
      $this->$name = $value;
   }

   public function load (string $view, array $parameters) : bool
   {
      if ($this->raw !== '') {
         return true;
      }

      // ! Load Raw file/string
      $File = new File;
      $File->construct = false;
      $File->convert = false;
      $File(Bootgly::$Project . $view . '.template.php');

      if ($File->File) {
         $this->raw = $File->contents;
      } else {
         $this->raw = $view;
      }

      // ! Set Parameters
      $this->parameters = $parameters;

      return true;
   }

   #public function parse () {}

   // TODO REFACTOR: This function "compile" has 175 lines, which is greater than the 150 lines authorized.
   // TODO move to resources pattern
   public function compile (? string $name = null)
   {
      if ($name !== null) {
         $this->compilables = [];
         $this->compilables[] = $name;
      }

      if ( ! empty($this->compiled) && $name !== null ) {
         return true;
      }

      foreach ($this->compilables as $compilable) {
         // ! Compile - Level 0
         switch ($compilable) {
            // ? Loops
            case 'foreach':
               // @ Meta variable ($@)
               if (@$this->compiled['meta_']) {
                  break;
               }

               // @ $@->...;
               $pattern = '/(\$@){1}(->){1}/sx';
               $callback = function ($matches) {
                  if ($matches[1] === null) {
                     return '';
                  }

                  return '$_->';
               };
               $this->patterns[$pattern] = $callback;

               $this->compiled['meta_'] = true;
            case 'for':
            case 'while':
               // @ Break
               if (@$this->compiled['break']) {
                  break;
               }

               // @ break ?<level:number>;
               $pattern = "/@break[ ]?(\d+)?[ ]?;/sx";
               $callback = function ($matches) {
                  // @ ?<level:number>;
                  $level = $matches[1] ?? '';

                  return <<<PHP
                  <?php break $level; ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;

               // @ break ?<level:number> in <condition>;
               $pattern = "/@break[ ]+?(\d+)?[ ]?in[ ]+?(.+?)[ ]?;/sx";
               $callback = function ($matches) {
                  // @ ?<level:number>;
                  $level = $matches[1] ?? '';
                  // @ <conditional>;
                  $conditional = $matches[2];
   
                  return <<<PHP
                  <?php if ($conditional) break $level; ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;

               $this->compiled['break'] = true;

               // ? Continue
               if (@$this->compiled['continue']) {
                  break;
               }

               // @ continue ?<level:number>;
               $pattern = "/@continue[ ]?(\d+)?[ ]?;/sx";
               $callback = function ($matches) {
                  // @ ?<level:number>;
                  $level = $matches[1] ?? '';

                  return <<<PHP
                  <?php continue $level; ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;

               // @ continue ?<level:number> in <condition>;
               $pattern = "/@continue[ ]+?(\d+)?[ ]?in[ ]+?(.+?)[ ]?;/sx";
               $callback = function ($matches) {
                  // @ ?<level:number>;
                  $level = $matches[1] ?? '';
                  // @ <conditional>;
                  $conditional = $matches[2];
   
                  return <<<PHP
                  <?php if ($conditional) continue $level; ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;

               $this->compiled['continue'] = true;
         }

         // ! Compile - Level 1
         switch ($compilable) {
            case 'echo':
               $pattern = '/@>>\s*(.+?)\s*;(\r?\n)?/s';
               $callback = function ($matches) {
                  $wrapped = $matches[1];

                  $whitespace = $matches[2] ?? '';

                  return <<<PHP
                  <?php echo {$wrapped}; ?>{$whitespace}
                  PHP;
               };
               $this->patterns[$pattern] = $callback;
               break;
            // ? Conditionals
            case 'if':
               $pattern = "/(@if)[ ]+?(.+?)[ ]?:/sx";
               $callback = function ($matches) {
                  // @ Conditional
                  $conditional = $matches[2];
   
                  return <<<PHP
                  <?php if ({$conditional}): ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;
   
               $pattern = "/(@elseif)[ ]+?(.+?)[ ]?:/sx";
               $callback = function ($matches) {
                  // @ Conditional
                  $conditional = $matches[2];
   
                  return <<<PHP
                  <?php elseif ({$conditional}): ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;
   
               $pattern = "/(@else)[ ]?:/sx";
               $callback = function () {
                  return <<<PHP
                  <?php else: ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;
   
               $pattern = "/@if[ ]?;/sx";
               $callback = function () {
                  return <<<PHP
                  <?php endif; ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;
   
               break;
            // ? Loops
            case 'foreach':
               $pattern = "/(@foreach)[ ]+?(.+?)[ ]?:/sx";
               $callback = function ($matches) {
                  // @ <expression> as $key
                  $iterable = trim($matches[2], '()');
   
                  // TODO Add Loop Variables only if meta variable ($@) exists inside foreach
                  preg_match('/\$(.*) +as *(.*)$/is', $iterable, $_matches);
                  $iteratee = $_matches[1];
                  $iteration = $_matches[2];

                  //Debug($iteratee, $this->parameters[$iteratee]); exit;
                  $init = <<<PHP
                  \$_ = new \Bootgly\__Iterable(\$$iteratee);
                  PHP;

                  return <<<PHP
                  <?php {$init} foreach (\$_ as {$iteration}): ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;
   
               $pattern = "/@foreach[ ]?;/sx";
               $callback = function () {
                  return <<<PHP
                  <?php endforeach; ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;
   
               break;
            case 'for':
               $pattern = "/(@for)[ ]+?(.+?)[ ]?:/sx";
               $callback = function ($matches) {
                  // @ ...<expressions>
                  $expressions = trim($matches[2], '()');
   
                  return <<<PHP
                  <?php for ({$expressions}): ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;
   
               $pattern = "/@for[ ]?;/sx";
               $callback = function () {
                  return <<<PHP
                  <?php endfor; ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;
   
               break;
            case 'while':
               $pattern = "/(@while)[ ]+?(.+?)[ ]?:/sx";
               $callback = function ($matches) {
                  // @ <expression>
                  $expression = $matches[2];
   
                  return <<<PHP
                  <?php while ({$expression}): ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;
   
               $pattern = "/@while[ ]?;/sx";
               $callback = function () {
                  return <<<PHP
                  <?php endwhile; ?>
                  PHP;
               };
               $this->patterns[$pattern] = $callback;
   
               break;
         }

         $this->compiled[$compilable] = true;
      }

      return true;
   }
   public function render (string $view, array $parameters)
   {
      // ! Load Raw, Parameters
      $this->load($view, $parameters);

      // ! Compile Raw
      $this->compile();

      // ! Render Template
      $replaced = preg_replace_callback_array($this->patterns, $this->raw);

      // ! Save Output
      $this->save($replaced);

      // $this->debug();

      // ! Execute Output with Parameters
      $this->execute();
   }

   public function register (string $tag, string $type, \Closure $callback)
   {
      // TODO register custom tag
   }

   public function save (? string $replaced = null) : bool
   {
      if ($replaced !== null) {
         $this->output = $replaced;
      }

      $this->Output = new File(HOME_DIR . 'workspace/cache/' . 'views/' . sha1($this->raw) . '.php');

      if ($this->Output->exists) {
         return false;
      }

      $this->Output->open('w+');
      $this->Output->write($this->output);
      $this->Output->close();

      return true;
   }

   public function execute (? Config $Mode = null) : bool
   {
      if ($Mode === null) {
         $Mode = Config::EXECUTE_MODE_REQUIRE;
      }

      try {
         extract($this->parameters);

         switch ($Mode) {
            case Config::EXECUTE_MODE_REQUIRE:
               require (string) $this->Output; break;
            case Config::EXECUTE_MODE_EVAL:
               eval('?>'.$this->output); break;
         }

         return true;
      } catch (\Throwable $Throwable) {
         Debug('Error!', $Throwable->getMessage(), $Throwable->getLine());
         $this->output = '';
      }
   }

   public function debug ()
   {
      Debug('<code>'.htmlspecialchars($this->output).'</code>');
   }
}
