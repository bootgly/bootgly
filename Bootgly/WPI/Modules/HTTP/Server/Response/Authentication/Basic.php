<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Response\Authentication;


use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication;


class Basic implements Authentication
{
   public function __construct (
      public string $realm = "Protected area"
   )
   {}
}
