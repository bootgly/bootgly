<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging;


use Bootgly\ABI\Debugging;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Debugging\Data\Throwables\Errors;
use Bootgly\ABI\Debugging\Data\Throwables\Exceptions;
use Bootgly\ABI\Debugging\Data\Vars;


abstract class Data implements Debugging
{
   public static function debug (mixed ...$x): void
   {
      if (empty($x) === true) {
         return;
      }

      foreach ($x as $y) {
         if ($y instanceof \Error) {
            Errors::debug($y);
         } else if ($y instanceof \Exception) {
            Exceptions::debug($y);
         } else if ($y instanceof \Throwable) {
            Throwables::debug($y);
         } else {
            Vars::debug($y);
         }
      }
   }
}
