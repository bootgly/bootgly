<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Test;


use Exception;
use Throwable;


class IgnoreTestException extends Exception
{
   public function __construct (
      string $message = "",
      int $code = 0,
      Throwable $previous = null
      )
   {
      parent::__construct($message, $code, $previous);
   }
}
