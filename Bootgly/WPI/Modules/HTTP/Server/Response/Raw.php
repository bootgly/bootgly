<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Response;


use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Ack;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Payload;


abstract class Raw
{
   // * Data
   public string $data;


   public function __toString (): string
   {
      return $this->data ?? '';
   }
}
