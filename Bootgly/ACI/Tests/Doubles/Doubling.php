<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Doubles;


/**
 * Contract shared by resettable test doubles.
 */
interface Doubling
{
   /**
    * Reset the double's recorded state.
    */
   public function reset (): static;
}