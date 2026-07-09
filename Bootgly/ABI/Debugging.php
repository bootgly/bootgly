<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI;


interface Debugging
{
   // # Render targets
   public const int TARGET_CLI = 1;
   public const int TARGET_HTML = 2;


   // ...Used to define and indentify subclasses (instance of).
   public static function debug (mixed ...$data): void;
}
