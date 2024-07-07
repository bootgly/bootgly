<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API;


abstract class Component
{
   // * Config
   // @ render
   public const RETURN_OUTPUT = 1;
   public const WRITE_OUTPUT = 2;
   public int $render = self::WRITE_OUTPUT;


   abstract protected function render (int $mode = self::WRITE_OUTPUT); // @phpstan-ignore-line
}
