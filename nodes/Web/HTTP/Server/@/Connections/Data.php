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

   public function read (&$Socket, bool $write = false) : bool
   {
      $read = parent::read($Socket, $write);

      if ($read === false) {
         return false;
      }

      // Do something...?

      if ($write === false) {
         #Server::$Event->add($Socket, Server::$Event::EVENT_WRITE, 'write');
         $this->write($Socket);
      }

      return true;
   }
   public function write (&$Socket, bool $handle = false, ? int $length = null) : bool
   {
      if (self::$input === '') {
         return false;
      }

      // @ Check cache
      // TODO Response cache (dynamic response)
      $writed = false;
      #if ($this->changed === false && $this->output !== '') {
      #   parent::write($Socket, $handle);
      #   @fflush($Socket);
      #   return true;
      #}

      // @ Instance callbacks
      $Request = $this->callbacks[0];
      $Response = $this->callbacks[1];
      $Router = $this->callbacks[2];

      // @ Set HTTP Data
      // ! HTTP
      // ? Response Content
      $Response->Content->raw = ($this->Connection->Server->handler)(
         $Request,
         $Response,
         $Router
      );
      $Response->Content->length = strlen($Response->Content->raw);
      // ? Response Header
      // ...
      $Response->Header->set('Content-Length', $Response->Content->length);
      // ? Response Meta
      // ...

      // TODO implement Timer Event loop with tick to update this every 1 second
      #$date = gmdate('D, d M Y H:i:s T');

      // @ Set Buffer output
      $this->output = <<<HTTP_RAW
      {$Response->Meta->raw}
      {$Response->Header->raw}
      {$Response->Content->raw}
      HTTP_RAW;

      #$this->log($this->output . PHP_EOL);

      // @ Send data to client
      $writed ?: $writed = parent::write($Socket, $handle, strlen($this->output));
      if ($writed === false) {
         return false;
      }

      // @ On success
      // Delete event from loop
      #Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);
      // Reset Request data
      if ($this->changed) {
         $Request->reset();
         $Response->Header->raw = '';
      }
      // Reset Buffer I/O
      #self::$input = '';
      #$this->output = '';
      // Reset Parser
      if ($this->changed) {
         self::$parsed = false;
         self::$parsing = false;
         self::$pointer = 0;
      }

      return true;
   }
}

return new Data($this->Connection);