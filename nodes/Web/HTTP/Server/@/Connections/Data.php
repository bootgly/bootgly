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
use Bootgly\Web\TCP\Server\Connections;
use Bootgly\Web\TCP\Server\Connections\Data as TCPData;


class Data extends TCPData
{
   // * Meta
   // @ Parser
   public static bool $parsed = false;
   public static bool $parsing = false;
   public static int $pointer = 0; // @ Current Line of parser read


   public function __construct (Connections &$Connections)
   {
      // * Meta
      parent::__construct($Connections);
   }

   public function write (&$Socket, bool $handle = false, ? int $length = null) : bool
   {
      if (self::$input === '') {
         return false;
      }

      // @ Instance callbacks
      $Request = $this->callbacks[0];
      $Response = $this->callbacks[1];
      $Router = $this->callbacks[2];

      // @ Set HTTP Request data
      // $Request->input();
      $Request->parse();

      #$Request->raw;

      // @ Set HTTP Response data
      try {
         $Response->Content->raw = (SAPI::$Handler)($Request, $Response, $Router);
      } catch (\Throwable) {
         // $this->Content->raw = '';
         $Response->Meta->status = 500; // @ 500 HTTP Server Error
      }

      $Response->parse();

      // @ Set HTTP raw output
      self::$output = <<<HTTP_RAW
      {$Response->Meta->raw}
      {$Response->Header->raw}

      {$Response->Content->raw}
      HTTP_RAW;

      #$this->log(self::$output . PHP_EOL . PHP_EOL . PHP_EOL);

      // @ Send HTTP raw output to client
      $writed = parent::write($Socket, $handle, strlen(self::$output));
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
         #self::$output = '';

         // TODO move to Request
         // Reset Parser
         self::$parsed = false;
         self::$parsing = false;
         self::$pointer = 0;
      }

      return true;
   }
}

return new Data($this->Connections);