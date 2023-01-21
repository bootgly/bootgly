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


use Bootgly\SAPI;
use Bootgly\Web\HTTP\Server;
use Bootgly\Web\TCP\Server\Connections as TCP;


// abstract Packages
class Packages extends TCP\Packages
{
   public function __construct (TCP &$Connections)
   {
      parent::__construct($Connections);
   }

   public function write (&$Socket, bool $handle = false, ? int $length = null) : bool
   {
      if (self::$input === '') {
         return false;
      }

      // @ Instance callbacks
      $Request = Server::$Request;
      $Response = Server::$Response;
      $Router = Server::$Router;

      // ! Request
      // @ Input HTTP Request
      if ($Request->input(self::$input) === 0) {
         parent::write($Socket, false);

         $this->Connections->close($Socket);
      }

      // ! Response
      // @ Output HTTP Response
      self::$output = $Response->output($Request, $Response, $Router);

      #$this->log(self::$output . PHP_EOL . PHP_EOL . PHP_EOL);

      // @ Send HTTP raw output to client
      $writed = parent::write($Socket, $handle, strlen(self::$output));
      if ($writed === false) {
         return false;
      }

      // @ On success
      if ($this->changed) {
         // @ Delete event from loop
         #Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);

         // @ Reset HTTP Request/Response Data
         $Request->reset();
         $Response->reset();

         // @ Reset Buffer I/O
         #self::$input = '';
         #self::$output = '';
      }

      return true;
   }
}

return new Packages($this->Connections);