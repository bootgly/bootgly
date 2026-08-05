<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage;


/**
 * Abstract coverage report formatter.
 */
abstract class Report
{
   // * Config
   /**
    * Whether the rendered output should include per-file diff details.
    */
   public bool $diff = false;


   /**
    * Render the hit map.
    *
    * @param array<string, array<int, int>> $data
    */
   abstract public function render (array $data): string;
}
