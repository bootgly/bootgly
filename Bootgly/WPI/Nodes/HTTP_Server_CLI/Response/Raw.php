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


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Meta;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Body;


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

      // * Data
      #$this->data = '';
   }

   public function __toString () : string
   {
      return $this->data ?? '';
   }
}
