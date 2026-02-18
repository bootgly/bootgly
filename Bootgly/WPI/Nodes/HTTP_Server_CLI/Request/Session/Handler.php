<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\File;


class Handler
{
   // * Config
   /** @var class-string<Handling> */
   public static string $class = File::class;
   public static mixed $config = null;

   // * Data
   public static Handling|null $instance = null;


   public static function init (): void
   {
      // !
      if (static::$config === null) {
         static::$instance = new static::$class();
      } else {
         static::$instance = new static::$class(static::$config);
      }
   }
}
