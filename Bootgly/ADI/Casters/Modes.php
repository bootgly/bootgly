<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Casters;


/**
 * How strict a cast is.
 *
 * Every caller picks one explicitly: there is no lenient default.
 */
enum Modes
{
   /**
    * Exact textual forms only.
    *
    * For human-written input (env vars, config files): a value is converted only
    * when its form is unambiguous — `"42"`, `"1.5e3"`, `"yes"`, `"2026-11-08"` —
    * and refused otherwise (`" 1"`, `"0x1A"`, `"12abc"`, a float for an integer).
    */
   case Strict;
   /**
    * PHP's native scalar conversions.
    *
    * For values a typed source already produced (database drivers): a value
    * converts the way PHP's `(int)`, `(float)`, `(bool)` and `(string)` casts
    * convert it, except that a number the int range cannot hold is refused
    * instead of saturated or wrapped. `Casters::DateTime` also refuses what PHP
    * cannot parse as a date-time.
    */
   case Loose;
}
