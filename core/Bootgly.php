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


abstract class Bootgly
{
   // * Meta
   public readonly bool $booted;

   public static Project $Project;
   public static Template $Template;


   public static function boot ()
   {
      self::$Project = new Project;
      self::$Template = new Template;
   }
}
