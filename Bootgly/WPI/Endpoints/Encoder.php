<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints;


use Bootgly\WPI\Connections\Packages;


interface Encoder
{
   /**
    * Encodes the given package into a string.
    *
    * @param Packages $Package
    * @param int<0,max>|null $length 
    *
    * @return string 
    */
   public static function encode (Packages $Package, null|int &$length): string;
}
