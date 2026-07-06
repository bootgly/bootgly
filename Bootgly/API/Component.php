<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
