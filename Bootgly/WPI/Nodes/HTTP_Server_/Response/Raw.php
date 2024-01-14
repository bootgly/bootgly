<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_\Response;


use Bootgly\WPI\Nodes\HTTP_Server_ as Server;


class Raw
{
   public Meta $Meta;
   public Header $Header;
   public Body $Body;

   // * Data
   public string $data;


   public function __construct ()
   {
      $this->Meta = new Meta;
      $this->Header = new Header;
      $this->Body = new Body;
   }
}
