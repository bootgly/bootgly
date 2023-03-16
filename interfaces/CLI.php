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


use Bootgly\CLI\ {
   Commands,
   Terminal
};


class CLI
{
   // * Config
   public array $includes;
   // * Data
   // * Meta
   // ! Escaping
   public const _START_ESCAPE = "\033[";

   public static Commands $Commands;
   public static Terminal $Terminal;


   public function __construct ()
   {
      if (PHP_SAPI !== 'cli') {
         return;
      }

      // * Config
      // TODO move to bootgly config path
      $this->includes = [
         'scripts' => [
            HOME_DIR . 'bootgly',
            HOME_DIR . './bootgly' // TODO normalize path
         ]
      ];

      $Commands = self::$Commands = new Commands;
      $Terminal = self::$Terminal = new Terminal;

      // @ Validate include
      $script = $_SERVER['PWD'] . DIRECTORY_SEPARATOR . $_SERVER['SCRIPT_FILENAME'];
      $included = array_search($script, $this->includes['scripts']);

      if ($included === false) {
         return ;
      }

      // @ Load CLI constructor
      @include Bootgly::$Project::PROJECT_DIR . 'cli.constructor.php';
   }
}
