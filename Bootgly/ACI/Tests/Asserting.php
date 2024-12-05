<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


use Bootgly\ACI\Tests\Asserting\Fallbacking;


interface Asserting extends Fallbacking
{
   public function assert (mixed &$actual, mixed &$expected): bool;
}
