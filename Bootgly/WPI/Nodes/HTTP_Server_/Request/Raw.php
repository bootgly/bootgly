<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_\Request;


use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Body;


class Raw
{
   public Header $Header;
   public Body $Body;

   // * Data
   public string $data;


   public function __construct ()
   {
      $this->Header = new Header;
      $this->Body = new Body;
   }
}
