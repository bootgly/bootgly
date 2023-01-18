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

use Bootgly\Web\TCP\Server\Connections;


class Select implements Event\Loops
{
   use Logging;


   public Connections $Connections;

   // * Config
   const EVENT_ACCEPT = 0;

   const EVENT_READ = 1;
   const EVENT_WRITE = 2;
   const EVENT_EXCEPT = 3;
   // * Data
   // @ Sockets
   private array $reads = [];
   private array $writes = [];
   private array $excepts = [];
   // * Meta
   // @ Actions
   private array $reading = [];
   private array $writing = [];
   private array $excepting = [];


   public function __construct (Connections &$Connections)
   {
      $this->Connections = $Connections;
   }

   public function add ($Socket, int $flag, $action, ? array $arguments = null)
   {
      switch ($flag) {
         case self::EVENT_READ:
            // System call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.
            if (count($this->reads) >= 1000) {
               return false;
            }

            $id = (int) $Socket;

            $this->reads[$id] = $Socket;

            $this->reading[$id] = $action;

            return true;
         case self::EVENT_WRITE:
            // System call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.
            if (count($this->writes) >= 1000) {
               return false;
            }

            $id = (int) $Socket;

            $this->writes[$id] = $Socket;

            $this->writing[$id] = $action;

            return true;
         case self::EVENT_EXCEPT:
            // System call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.
            if (count($this->excepts) >= 1000) {
               return false;
            }

            $id = (int) $Socket;

            $this->excepts[$id] = $Socket;

            $this->excepting[$id] = $action;

            return true;
      }

      return false;
   }
   public function del ($Socket, int $flag)
   {
      switch ($flag) {
         case self::EVENT_READ:
            $id = (int) $Socket;

            unset($this->reading[$id]);

            unset($this->reads[$id]);

            return true;
         case self::EVENT_WRITE:
            $id = (int) $Socket;

            unset($this->writing[$id]);

            unset($this->writes[$id]);

            return true;
         case self::EVENT_EXCEPT:
            $id = (int) $Socket;

            unset($this->excepting[$id]);

            unset($this->excepts[$id]);

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

         if ($read || $write || $except) {
            try {
               // Waiting $this->timeout for read / write / excepts events.
               $connections = @stream_select($read, $write, $except, null);
            } catch (\Throwable) {
               $connections = false;
            }
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
            foreach ($read as $id => $Socket) {
               // @ Select action
               match (@$this->reading[$id]) {
                  'accept' => $this->Connections->accept($Socket),
                  'read' => $this->Connections->Data->read($Socket),
                  default => null
               };
            }
         }

         if ($write) {
            foreach ($write as $Socket) {
               $this->Connections->Data->write($Socket);
            }
         }

         // TODO add timer ticks?
         // if ($except) {}
      }
   }
   public function destroy ()
   {}
}
