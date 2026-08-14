<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


use function count;
use Closure;
use Throwable;
use WeakMap;

use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * Idempotent terminal owner for one admitted HTTP exchange.
 *
 * Request aliases carry the token through controlled Response snapshots. The
 * worker may reuse its live Request while a captured deferred Request remains
 * active; each weak alias then continues to identify the correct exchange
 * without relying on reusable Response or Fiber object identities.
 */
final class Exchange implements Disconnecting
{
   /** @var null|WeakMap<Request,self> */
   private static null|WeakMap $Owners = null;
   /** @var null|WeakMap<object,self> */
   private static null|WeakMap $Snapshots = null;
   private static null|Response $Context = null;
   private static null|Packages $Package = null;
   private static null|Request $Request = null;
   private static null|self $Exchange = null;

   // * Metadata
   /** @var null|WeakMap<Request,true> */
   private null|WeakMap $Aliases = null;
   private bool $finished = false;
   /** @var array<int,Closure(self,null|int):void> */
   private array $Observers = [];
   private null|int $code = null;


   /**
    * Bind a newly admitted exchange to its live Request owner.
    */
   public static function admit (Request $Owner, self $Exchange): void
   {
      if ($Exchange->finished) { // @phpstan-ignore if.alwaysFalse
         return;
      }

      $Owners = self::$Owners ??= new WeakMap;

      // ! Drain a displaced owner before publishing the requested exchange.
      //   A terminal observer may re-enter admission; looping ensures that an
      //   intervening token cannot be silently orphaned or replace the caller's
      //   final binding.
      while (true) {
         $Previous = $Owners[$Owner] ?? null;
         if ($Previous === null || $Previous === $Exchange) {
            break;
         }

         unset($Owners[$Owner]);
         if ($Previous->Aliases !== null) {
            unset($Previous->Aliases[$Owner]);
            if (count($Previous->Aliases) === 0) {
               $Previous->finish(null);
            }
         }
      }

      if ($Exchange->finished) { // @phpstan-ignore if.alwaysFalse
         return;
      }

      $Owners[$Owner] = $Exchange;
      $Aliases = $Exchange->Aliases ??= new WeakMap;
      $Aliases[$Owner] = true;
   }

   /**
    * Share one source Request's exchange with a captured Request alias.
    */
   public static function share (Request $From, Request $To): null|self
   {
      $Exchange = self::fetch($From);
      if ($Exchange !== null) {
         self::admit($To, $Exchange);
      }

      return $Exchange;
   }

   /**
    * Release one reusable Request alias from its previous exchange.
    *
    * A deferred capture keeps its own alias alive while the connection's
    * Request object moves on to the next HTTP message. The next admission can
    * therefore detach the reusable object without cancelling still-owned work.
    */
   public static function release (Request $Owner): null|self
   {
      $Owners = self::$Owners;
      if ($Owners === null) {
         return null;
      }

      $Released = null;
      while (($Exchange = $Owners[$Owner] ?? null) !== null) {
         $Released ??= $Exchange;
         unset($Owners[$Owner]);
         $Aliases = $Exchange->Aliases;
         if ($Aliases !== null) {
            unset($Aliases[$Owner]);
            if (count($Aliases) === 0) {
               // ! A terminal observer may re-admit another token on this
               //   reusable Request. The loop drains that intervening owner
               //   before the next HTTP message is allowed to proceed.
               $Exchange->finish(null);
            }
         }
      }

      return $Released;
   }

   /**
    * Bind the current pre-reset transport context for one Response.
    *
    * The encoder consumes this context immediately through Response::guard()
    * before invoking public Received listeners. Keeping it here avoids adding
    * lifecycle arguments to Response's public, overridable method signature.
    */
   public static function bind (
      Response $Owner,
      Packages $Package,
      Request $Request,
      self $Exchange,
   ): void
   {
      self::$Context = $Owner;
      self::$Package = $Package;
      self::$Request = $Request;
      self::$Exchange = $Exchange;
   }

   /**
    * Capture and clear the pre-reset context bound to one Response.
    *
    * @return null|array{Package:Packages,Request:Request,Exchange:self}
    */
   public static function capture (Response $Owner): null|array
   {
      if (self::$Context !== $Owner) {
         return null;
      }

      $Package = self::$Package;
      $Request = self::$Request;
      $Exchange = self::$Exchange;

      self::$Context = null;
      self::$Package = null;
      self::$Request = null;
      self::$Exchange = null;

      if ($Package === null || $Request === null || $Exchange === null) {
         return null;
      }

      return [
         'Package' => $Package,
         'Request' => $Request,
         'Exchange' => $Exchange,
      ];
   }

   /**
    * Retain or clear one weak lifecycle snapshot for a non-Request owner.
    *
    * Unlike active Request aliases, snapshots deliberately survive terminal
    * completion while their weak owner remains alive. A retained Response
    * clone can then reject a late deferred/SSE write after its request ended,
    * without retaining that Response or its body from this registry.
    */
   public static function track (object $Owner, null|self $Exchange): void
   {
      if ($Exchange === null) {
         $Snapshots = self::$Snapshots;
         if ($Snapshots !== null) {
            unset($Snapshots[$Owner]);
         }

         return;
      }

      $Snapshots = self::$Snapshots ??= new WeakMap;
      $Snapshots[$Owner] = $Exchange;
   }

   /**
    * Fetch the exchange carried by one active Request alias or snapshot.
    */
   public static function fetch (object $Owner): null|self
   {
      if ($Owner instanceof Request) {
         $Owners = self::$Owners;
         $Exchange = $Owners === null ? null : ($Owners[$Owner] ?? null);
         if ($Exchange !== null) {
            return $Exchange;
         }
      }

      $Snapshots = self::$Snapshots;

      return $Snapshots === null ? null : ($Snapshots[$Owner] ?? null);
   }


   /**
    * Check whether this exchange has reached its terminal transition.
    */
   public function check (): bool
   {
      return $this->finished;
   }

   /**
    * Inspect whether an active exchange has terminal observers.
    *
    * A public Received listener alone still creates an exchange for clone
    * tombstones, but only an observer such as Telemetry requires a scheduler
    * that can deliver deterministic cancellation.
    */
   public function inspect (): bool
   {
      return $this->finished === false && $this->Observers !== [];
   }


   /**
    * Observe this exchange's terminal transition.
    *
    * A late observer is notified immediately with the retained terminal
    * result. Terminal observers are contained: instrumentation or cleanup
    * must never prevent another observer, serialization or teardown.
    *
    * @param Closure(self,null|int):void $Observer
    */
   public function observe (Closure $Observer): bool
   {
      if ($this->finished) {
         try {
            $Observer($this, $this->code);
         }
         catch (Throwable) {
            // Same containment contract as observers present at transition.
         }

         return false;
      }

      $this->Observers[] = $Observer;

      return true;
   }

   /**
    * Finish the exchange exactly once.
    *
    * A null Response denotes transport/scheduler cancellation before a final
    * response became observable. Core request/duration metrics still close;
    * no response-status class is invented for that cancellation.
    */
   public function finish (null|Response $Response): bool
   {
      if ($this->finished) {
         return false;
      }

      // ! Set before callbacks so a re-entrant disconnect/finish is a no-op.
      $this->finished = true;
      // ! Retain only the immutable status needed by late telemetry observers.
      //   Holding the terminal Response would also pin its captured Request,
      //   body and resources for as long as any listener retained this token.
      $this->code = $Response?->code;

      // ! Remove every discovery alias before callbacks. A retained Request
      //   clone must never propagate a completed exchange into later work,
      //   and re-entrant admission is then free to publish a new token.
      $Aliases = $this->Aliases;
      $this->Aliases = null;
      $Owners = self::$Owners;
      if ($Aliases !== null && $Owners !== null) {
         foreach ($Aliases as $Owner => $_) {
            if (($Owners[$Owner] ?? null) === $this) {
               unset($Owners[$Owner]);
            }
         }
      }

      $Observers = $this->Observers;
      $this->Observers = [];

      foreach ($Observers as $Observer) {
         try {
            $Observer($this, $this->code);
         }
         catch (Throwable) {
            // Observability/ownership cleanup cannot break exchange teardown.
         }
      }

      return true;
   }

   /**
    * Close an unfinished exchange after transport or scheduler teardown.
    */
   public function disconnect (): void
   {
      $this->finish(null);
   }

   /**
    * Safety net for an admitted token whose final owner graph was collected.
    */
   public function __destruct ()
   {
      $this->finish(null);
   }
}
