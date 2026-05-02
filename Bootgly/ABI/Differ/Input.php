<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ;


/**
 * Contract for input adapters that parse external diff representations into
 * Bootgly Differ structures.
 */
interface Input
{
   /**
    * @return list<mixed>
    */
   public function parse (string $input): array;
}
