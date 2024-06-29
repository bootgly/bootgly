<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


use Bootgly\WPI\Modules\HTTP\Server\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Payload;


class Raw extends Response\Raw
{
   public Header $Header;
   public Payload $Payload;


   public function __construct ()
   {
      $this->Header = new Header;
      $this->Payload = new Payload;
   }
}
