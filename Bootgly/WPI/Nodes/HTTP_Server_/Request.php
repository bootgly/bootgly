<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_;


use function date;
use AllowDynamicProperties;

use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Session;


#[AllowDynamicProperties]
class Request extends Server\Request
{
   // * Config
   // ..

   // * Data
   // ...

   // * Metadata
   public readonly string $on;
   public readonly string $at;
   public readonly int $time;

   public Body $Body;
   public Header $Header;

   public Session $Session;


   public function __construct ()
   {
      $this->Header = new Header;
      $this->Body = new Body;

      // * Config
      $this->base = '';
      // TODO pre-defined filters
      // $this->Filter->sanitize(...) | $this->Filter->validate(...)

      // * Data
      // ...

      // * Metadata
      $this->on = date("Y-m-d");
      $this->at = date("H:i:s");
      $this->time = $_SERVER['REQUEST_TIME'];


      $this->Session = new Session;
   }
}
