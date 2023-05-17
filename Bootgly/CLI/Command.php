<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


abstract class Command
{
   // * Config
   // ...

   // * Data
   public string $name;
   public string $description;

   // * Meta
   // ...


   abstract public function run (array $arguments, array $options) : bool;
}
