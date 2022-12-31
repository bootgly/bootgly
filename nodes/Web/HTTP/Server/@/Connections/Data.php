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


use Bootgly\Web\Servers;
use Bootgly\Web\TCP\Server;
use Bootgly\Web\TCP\Server\Connection;
use Bootgly\Web\TCP\Server\Connections\Data as TCPData;

use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response;
use Bootgly\Web\HTTP\Server\Router;


class Data extends TCPData
{
   // * Meta
   // @ Parser
   public static bool $parsed = false;
   public static bool $parsing = false;
   public static int $pointer = 0; // @ Current Line of parser read


   public function __construct (Connection &$Connection)
   {
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
         #Server::$Event->add($Socket, Server::$Event::EV_WRITE, [$this, 'write']);

         $this->write($Socket);
      }

      return true;
   }
   public function write (&$Socket, bool $handle = false) : bool
   {
      // TODO pass to Response, implement file upload, etc.
      $contents = [];
      $contents['raw'] = ($this->Connection->Server->handler)(...$this->callbacks);
      $contents['length'] = strlen($contents['raw']);

      $this->output = <<<HTTP_RAW
      HTTP/1.1 200 OK
      Server: Test Server
      Content-Type: text/plain; charset=UTF-8
      Content-Length: {$contents['length']}

      {$contents['raw']}
      HTTP_RAW;

      $writed = parent::write($Socket, $handle);

      // Reset Request properties
      $Request = $this->callbacks[0];
      $Request->reset();

      if ($writed === false) {
         // Try to write again?
         return false;
      }

      self::$input = '';
      // @ Parser
      self::$parsed = false;
      self::$parsing = false;
      self::$pointer = 0;

      return true;
   }
}

return new Data($this->Connection);