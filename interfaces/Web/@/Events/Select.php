<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\_\Events;


use Bootgly\CLI\_\ {
   Logger\Logging
};

use Bootgly\Web\TCP\Server;
use Bootgly\Web\TCP\Server\Connection;
use Bootgly\Web\TCP\Server\Connections;


class Select // TODO implements Events
{
   use Logging;


   public Connection $Connection;

   // * Config
   const EVENT_READ = 1;
   const EVENT_WRITE = 2;
   const EVENT_EXCEPT = 3;

   protected $timeout = 100000000; // 100s
   // * Data
   // @ Sockets
   protected $reads = [];
   protected $writes = [];
   protected $excepts = [];
   // * Meta
   public $events = [];


   public function __construct (Connection &$Connection)
   {
      $this->Connection = $Connection;
   }

   public function add ($Socket, int $flag, $action)
   {
      $SocketID = (int) $Socket;

      switch ($flag) {
         case self::EVENT_READ:
            $count = count($this->reads);

            // System call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.
            if ($count >= 1000) {
               return false;
            }

            $this->events[$SocketID][$flag] = $action;

            $this->reads[$SocketID] = $Socket;

            return true;
         case self::EVENT_WRITE:
            $count = count($this->writes);

            // System call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.
            if ($count >= 1000) {
               return false;
            }

            $this->events[$SocketID][$flag] = $action;

            $this->writes[$SocketID] = $Socket;

            return true;
         case self::EVENT_EXCEPT:
            $count = count($this->excepts);

            // System call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.
            if ($count >= 1000) {
               return false;
            }

            $this->events[$SocketID][$flag] = $action;

            $this->excepts[$SocketID] = $Socket;

            return true;
      }

      return false;
   }

   public function del ($Socket, int $flag)
   {
      $SocketID = (int) $Socket;

      switch ($flag) {
         case self::EVENT_READ:
            unset($this->events[$SocketID][$flag]);
            unset($this->reads[$SocketID]);

            if (empty($this->events[$SocketID])) {
               unset($this->events[$SocketID]);
            }

            return true;
         case self::EVENT_WRITE:
            unset($this->events[$SocketID][$flag]);
            unset($this->writes[$SocketID]);

            if (empty($this->events[$SocketID])) {
               unset($this->events[$SocketID]);
            }

            return true;
         case self::EVENT_EXCEPT:
            unset($this->events[$SocketID][$flag]);
            unset($this->excepts[$SocketID]);

            if (empty($this->events[$SocketID])) {
               unset($this->events[$SocketID]);
            }

            return true;
      }

      return false;
   }

   public function loop ()
   {
      #$this->log('Event loop started!' . PHP_EOL);

      while (1) {
         pcntl_signal_dispatch();

         $read   = $this->reads;
         $write  = $this->writes;
         $except = $this->excepts;

         $connections = false;

         if ($read || $write || $except) {
            try {
               // Waiting $this->timeout for read / write / excepts events.
               $connections = @stream_select($read, $write, $except, 0, $this->timeout);
            } catch (\Throwable $Throwable) {}
         } else {
            // @ Sleep for 1 second and continue (Used to Server pause)
            sleep(1);
            continue;
         }

         if ($connections === false || $connections === 0) {
            continue;
         }

         // @ Call
         if ($read) {
            foreach ($read as $Socket) {
               // @ Select action
               match ($this->events[(int) $Socket][self::EVENT_READ]) {
                  'accept' => $this->Connection->accept($Socket),
                  'read' => $this->Connection->Data->read($Socket)
               };
            }
         }

         if ($write) {
            foreach ($read as $Socket) {
               $this->Connection->Data->read($Socket);
            }
         }

         // if ($except) {}
      }
   }

   public function destroy ()
   {}
}
