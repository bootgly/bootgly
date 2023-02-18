<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\Path;


function Path(...$vars)
{
   $Path = new Path(...$vars);
   return $Path;
}
