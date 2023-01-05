<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\_\Connections;


use Bootgly\Web\TCP\Server;
use Bootgly\Web\TCP\Server\Connection;
use Bootgly\Web\TCP\Server\Connections\Data as TCPData;


class Data extends TCPData
{
   // * Meta
   // @ Parser
   public static bool $parsed = false;
   public static bool $parsing = false;
   public static int $pointer = 0; // @ Current Line of parser read


   public function __construct (Connection &$Connection)
   {
      // * Meta
      parent::__construct($Connection);
   }

   public function write (&$Socket, bool $handle = false, ? int $length = null) : bool
   {
      if (self::$input === '') {
         return false;
      }

      // @ Instance callbacks
      $Request = $this->callbacks[0];
      $Response = $this->callbacks[1];
      // $Router = $this->callbacks[2];

      // @ Set HTTP Request/Response Data and Output Buffer
      $this->output = $Response->parse($this->Connection->handler, $this->callbacks);

      // @ Send data to client
      $writed = parent::write($Socket, $handle, strlen($this->output));
      if ($writed === false) {
         return false;
      }

      // @ On success
      if ($this->changed) {
         // Delete event from loop
         #Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);

         // Reset HTTP Request/Response Data
         $Request->reset();
         $Response->reset();

         // Reset Buffer I/O
         #self::$input = '';
         #$this->output = '';

         // Reset Parser
         self::$parsed = false;
         self::$parsing = false;
         self::$pointer = 0;
      }

      return true;
   }
}

return new Data($this->Connection);