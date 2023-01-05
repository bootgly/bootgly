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


use Bootgly\Event;

use Bootgly\CLI\_\ {
   Logger\Logging
};

use Bootgly\Web\TCP\Server\Connection;


class Select implements Event\Loops
{
   use Logging;


   public Connection $Connection;

   // * Config
   const EVENT_READ = 1;
   const EVENT_WRITE = 2;
   const EVENT_EXCEPT = 3;

   protected int $timeout = 100000000; // 100s
   // * Data
   // @ Sockets
   private array $reads = [];
   private array $writes = [];
   private array $excepts = [];
   // * Meta
   private array $events = [];


   public function __construct (Connection &$Connection)
   {
      $this->Connection = $Connection;
   }

   public function add ($Socket, int $flag, $action, ? array $arguments = null)
   {
      switch ($flag) {
         case self::EVENT_READ:
            // System call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.
            if (count($this->reads) >= 1000) {
               return false;
            }

            $SocketId = (int) $Socket;
            $this->events[$SocketId][$flag] = $action;
            $this->reads[$SocketId] = $Socket;

            return true;
         case self::EVENT_WRITE:
            // System call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.
            if (count($this->writes) >= 1000) {
               return false;
            }

            $SocketId = (int) $Socket;
            $this->events[$SocketId][$flag] = $action;
            $this->writes[$SocketId] = $Socket;

            return true;
         case self::EVENT_EXCEPT:
            // System call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.
            if (count($this->excepts) >= 1000) {
               return false;
            }

            $SocketId = (int) $Socket;
            $this->events[$SocketId][$flag] = $action;
            $this->excepts[$SocketId] = $Socket;

            return true;
      }

      return false;
   }
   public function del ($Socket, int $flag)
   {
      switch ($flag) {
         case self::EVENT_READ:
            $SocketID = (int) $Socket;

            unset($this->events[$SocketID][$flag]);
            unset($this->reads[$SocketID]);

            if (empty($this->events[$SocketID])) {
               unset($this->events[$SocketID]);
            }

            return true;
         case self::EVENT_WRITE:
            $SocketID = (int) $Socket;

            unset($this->events[$SocketID][$flag]);
            unset($this->writes[$SocketID]);

            if (empty($this->events[$SocketID])) {
               unset($this->events[$SocketID]);
            }

            return true;
         case self::EVENT_EXCEPT:
            $SocketID = (int) $Socket;

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

      while (true) {
         pcntl_signal_dispatch();

         $read   = $this->reads;
         $write  = $this->writes;
         $except = $this->excepts;

         $connections = false;

         if ($read || $write || $except) {
            try {
               // Waiting $this->timeout for read / write / excepts events.
               $connections = @stream_select($read, $write, $except, 0, $this->timeout);
            } catch (\Throwable) {}
         } else {
            // @ Sleep for 1 second and continue (Used to pause the Server)
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
               match (@$this->events[(int) $Socket][self::EVENT_READ]) {
                  'accept' => $this->Connection->accept($Socket),
                  'read' => $this->Connection->Data->read($Socket),
                  default => null
               };
            }
         }

         if ($write) {
            foreach ($write as $Socket) {
               $this->Connection->Data->write($Socket);
            }
         }

         // if ($except) {}
      }
   }
   public function destroy ()
   {}
}
