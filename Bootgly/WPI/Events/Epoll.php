<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Events;


use function is_resource;
use function microtime;
use Ev;
use EvIo;
use EvSignal;
use Fiber;

use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events;


class Epoll implements Events, Loops, Scheduler
{
   public Connections $Connections;

   // * Config
   public bool $loop = true;

   // * Data
   // # Watchers
   /** @var array<int,EvIo> */
   private array $readWatchers = [];
   /** @var array<int,EvIo> */
   private array $writeWatchers = [];

   // * Metadata
   // # Events
   // Client/Server
   /** @var array<int,mixed> */
   private array $connecting = [];
   // # Async
   // Tick-based (resumed every iteration)
   /** @var array<int,Fiber<mixed,mixed,mixed,mixed>> */
   private array $Fibers = [];
   // I/O-bound (resumed when epoll signals readiness)
   /** @var array<int,Fiber<mixed,mixed,mixed,mixed>> */
   private array $awaiting = [];
   // # Loop
   public readonly float $started;
   public readonly float $finished;


   public function __construct (Connections &$Connections)
   {
      $this->Connections = $Connections;
   }

   /**
    * Add a socket to the event loop.
    *
    * @param resource $Socket
    * @param int $flag
    * @param mixed $payload
    *
    * @return bool
    */
   public function add ($Socket, int $flag, mixed $payload): bool
   {
      $id = (int) $Socket;

      switch ($flag) {
         case self::EVENT_CONNECT:
            $this->connecting[$id] = $payload;

            $Connections = $this->Connections;
            $this->readWatchers[$id] = new EvIo(
               $Socket,
               Ev::READ,
               function (EvIo $watcher) use ($Connections, $id): void {
                  if ( isSet($this->connecting[$id]) ) {
                     $Connections->connect();
                  }
               }
            );

            return true;

         case self::EVENT_READ:
            // @ Stop existing read watcher if any (avoid duplicates)
            if (isSet($this->readWatchers[$id])) {
               $this->readWatchers[$id]->stop();
               unset($this->readWatchers[$id]);
            }

            $this->readWatchers[$id] = new EvIo(
               $Socket,
               Ev::READ,
               function (EvIo $watcher) use ($payload, $Socket): void {
                  /** @var Connections\Packages $Package */
                  $Package = $payload;
                  $Package->reading($Socket);
               }
            );

            return true;

         case self::EVENT_WRITE:
            // @ Stop existing write watcher if any
            if (isSet($this->writeWatchers[$id])) {
               $this->writeWatchers[$id]->stop();
               unset($this->writeWatchers[$id]);
            }

            $this->writeWatchers[$id] = new EvIo(
               $Socket,
               Ev::WRITE,
               function (EvIo $watcher) use ($payload, $Socket): void {
                  // @ Stop WRITE watcher before dispatch (one-shot semantics)
                  $watcher->stop();
                  /** @var Connections\Packages $Package */
                  $Package = $payload;
                  $Package->writing($Socket);
               }
            );

            return true;

         case self::EVENT_EXCEPT:
            // Not commonly used — no-op for now
            return true;
      }

      return false;
   }

   /**
    * Remove a socket from the event loop.
    *
    * @param resource $Socket
    * @param int $flag
    *
    * @return bool
    */
   public function del ($Socket, int $flag): bool
   {
      $id = (int) $Socket;

      switch ($flag) {
         case self::EVENT_CONNECT:
            unset($this->connecting[$id]);

            if (isSet($this->readWatchers[$id])) {
               $this->readWatchers[$id]->stop();
               unset($this->readWatchers[$id]);
            }

            return true;

         case self::EVENT_READ:
            if (isSet($this->readWatchers[$id])) {
               $this->readWatchers[$id]->stop();
               unset($this->readWatchers[$id]);
            }

            return true;

         case self::EVENT_WRITE:
            if (isSet($this->writeWatchers[$id])) {
               $this->writeWatchers[$id]->stop();
               unset($this->writeWatchers[$id]);
            }

            return true;

         case self::EVENT_EXCEPT:
            return true;
      }

      return false;
   }

   /**
    * Start the event loop (epoll-backed via libev).
    *
    * @return void
    */
   public function loop (): void
   {
      $this->started = microtime(true);

      // @ Install signal watchers via libev (replaces pcntl_signal_dispatch)
      /** @var array<int,EvSignal> $signalWatchers */
      $signalWatchers = [];
      $signals = [\SIGINT, \SIGTERM, \SIGQUIT, \SIGALRM];
      foreach ($signals as $sig) {
         $handler = \pcntl_signal_get_handler($sig);
         if ($handler !== \SIG_DFL && $handler !== \SIG_IGN && \is_callable($handler)) {
            $signalWatchers[$sig] = new EvSignal($sig, function () use ($sig, $handler): void {
               /** @var callable $handler */
               $handler($sig);
            });
         }
      }

      if ($this->Fibers) {
         // @ Fiber-aware loop: poll + resume
         while ($this->loop) {
            foreach ($this->Fibers as $fid => $Fiber) {
               if ($Fiber->isSuspended()) {
                  $value = $Fiber->resume();

                  if ( !$Fiber->isTerminated() && is_resource($value)) {
                     unset($this->Fibers[$fid]);

                     $socketId = (int) $value;
                     $this->awaiting[$socketId] = $Fiber;
                     $this->readWatchers[$socketId] = new EvIo(
                        $value,
                        Ev::READ,
                        function (EvIo $watcher) use ($socketId): void {
                           $this->resumeAwaiting($socketId);
                        }
                     );

                     continue;
                  }
               }

               if ($Fiber->isTerminated()) {
                  unset($this->Fibers[$fid]);
               }
            }

            $flags = $this->Fibers ? Ev::RUN_NOWAIT : Ev::RUN_ONCE;
            Ev::run($flags);
         }
      }
      else {
         // @ Fast path: no Fibers — let libev drive everything
         Ev::run();
      }

      // @ Clean up signal watchers
      foreach ($signalWatchers as $w) {
         $w->stop();
      }

      $this->finished = microtime(true);
   }

   /**
    * Resume an I/O-awaiting Fiber.
    */
   private function resumeAwaiting (int $id): void
   {
      if ( !isSet($this->awaiting[$id]) ) {
         return;
      }

      $Fiber = $this->awaiting[$id];

      unset($this->awaiting[$id]);

      if (isSet($this->readWatchers[$id])) {
         $this->readWatchers[$id]->stop();
         unset($this->readWatchers[$id]);
      }

      if ($Fiber->isSuspended()) {
         $value = $Fiber->resume();

         if ( !$Fiber->isTerminated()) {
            if (is_resource($value)) {
               $newId = (int) $value;

               $this->awaiting[$newId] = $Fiber;
               $this->readWatchers[$newId] = new EvIo(
                  $value,
                  Ev::READ,
                  function (EvIo $watcher) use ($newId): void {
                     $this->resumeAwaiting($newId);
                  }
               );
            }
            else {
               $this->Fibers[] = $Fiber;
            }
         }
      }
   }

   /**
    * Schedule a suspended Fiber for resumption in the event loop.
    *
    * @param Fiber<mixed, mixed, mixed, mixed> $Fiber
    * @param mixed $value The suspended value from Fiber::start() or resume().
    *
    * @return bool
    */
   public function schedule (Fiber $Fiber, mixed $value = null): bool
   {
      if ($Fiber->isTerminated()) {
         return false;
      }

      if (is_resource($value)) {
         $id = (int) $value;

         $this->awaiting[$id] = $Fiber;
         $this->readWatchers[$id] = new EvIo(
            $value,
            Ev::READ,
            function (EvIo $watcher) use ($id): void {
               $this->resumeAwaiting($id);
            }
         );

         return true;
      }

      $this->Fibers[] = $Fiber;

      return true;
   }

   /**
    * Stop the event loop.
    *
    * @return void
    */
   public function destroy (): void
   {
      // @ Stop all watchers
      foreach ($this->readWatchers as $watcher) {
         $watcher->stop();
      }
      foreach ($this->writeWatchers as $watcher) {
         $watcher->stop();
      }

      $this->readWatchers = [];
      $this->writeWatchers = [];

      // # Async
      $this->awaiting = [];
      $this->Fibers = [];

      // # Loop
      $this->loop = false;

      Ev::stop();
   }
}
