<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging;


abstract class Exceptions
{
   // * Data
   protected static array $exceptions = [];


   public static function collect (\Error|\Exception $E)
   {
      self::$exceptions[] = $E;
   }

   public static function dump (\Error|\Exception $E)
   {
      // @ Output
      $output = "\n";
      // class
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[0;30;41m ",
         default => ''
      };
      $output .= get_class($E);
      $output .= match (\PHP_SAPI) {
         'cli' => " \033[0m\n\n",
         default => ''
      };
      // message
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[97m ",
         default => ''
      };
      $output .= $E->getMessage();
      $output .= match (\PHP_SAPI) {
         'cli' => " \033[0m\n\n",
         default => ''
      };
      // file
      $output .= " at ";
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[92m",
         default => ''
      };
      $output .= $E->getFile();
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[0m",
         default => ''
      };
      // line
      $output .= match (\PHP_SAPI) {
         'cli' => ":\033[96m",
         default => ''
      };
      $output .= $E->getLine();
      $output .= match (\PHP_SAPI) {
         'cli' => "\033[0m\n\n",
         default => ''
      };

      echo $output;
   }
}
