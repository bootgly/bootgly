<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header as Heading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


final class Cookies extends Heading\Cookies
{
   public function __construct (Header $Header)
   {
      $this->Header = $Header;

      // * Config
      // ...

      // * Data
      $this->cookies = [];

      // * Metadata
      // ...
   }
}
