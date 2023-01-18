<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP;


use Bootgly\Web\TCP\Client\Connections\Data;
// extend
use Bootgly\CLI\_\ {
   Logger\Logging
};


class Client
{
   use Logging;


   public $Socket;

   // * Config
   #protected string $resource;
   protected ? string $host;
   protected ? int $port;
   protected int $workers;
   // * Meta
   public const VERSION = '0.0.1';
   public array $error = [];
   protected static int $started = 0;
   // @ Status
   protected static int $status = 0;
   protected const STATUS_BOOTING = 1;
   protected const STATUS_CONFIGURING = 2;
   protected const STATUS_STARTING = 4;
   protected const STATUS_RUNNING = 8;
   protected const STATUS_PAUSED = 16;
   protected const STATUS_STOPING = 32;

   // ! Data
   public Data $Data;


   public function __construct ()
   {
      if (\PHP_SAPI !== 'cli') {
         return false;
      }

      // * Config
      // @ Mode
      // * Data
      // * Meta
      $this->error = [
         'code' => 0,
         'message' => ''
      ];
      static::$started = time();
      // @ Status
      $this->status = self::STATUS_BOOTING;

      // ! Data
      $this->Data = new Data($this);
   }

   public function configure (string $host, int $port)
   {
      $this->status = self::STATUS_CONFIGURING;

      // TODO validate configuration user data inputs

      $this->host = $host;
      $this->port = $port;

      return $this;
   }

   public function connect () : bool
   {   
      $context = stream_context_create([
         'socket' => [ 
            // Used to limit the number of outstanding connections in the socket's listen queue.
            'backlog' => 102400,

            // Allows multiple bindings to a same ip:port pair, even from separate processes.
            'so_reuseport' => false,

            // Setting this option to true will set SOL_TCP, NO_DELAY=1 appropriately, 
            // thus disabling the TCP Nagle algorithm.
            'tcp_nodelay' => true,

            // Enables sending and receiving data to/from broadcast addresses.
            'so_broadcast' => false
         ]
      ]);

      $this->Socket = false;
      try {
         $this->Socket = @stream_socket_client(
            'tcp://' . $this->host . ':' . $this->port,
            $this->error['code'],
            $this->error['message'],
            timeout: 30,
            flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT,
            context: $context
         );
      } catch (\Throwable) {};

      if ($this->Socket === false) {
         return false;
      }

      // @ On success
      stream_set_blocking($this->Socket, false);
      
      $this->status = self::STATUS_RUNNING;

      return true;
   }

   public function close ()
   {
      if ($this->Socket === null || $this->Socket === false) {
         // $this->log('@\;$this->Socket is already closed?@\;');
         exit(1);
      }

      $closed = false;
      try {
         $closed = @fclose($this->Socket);
      } catch (\Throwable) {}

      if ($closed === false) {
         // $this->log('@\;Failed to close $this->Socket!', self::LOG_ERROR_LEVEL);
      } else {
         // $this->log('@\;Sockets closed successful.', self::LOG_SUCCESS_LEVEL);
      }

      $this->Socket = null;
   }
}
