<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Events;


use Closure;
use Throwable;
use WeakMap;

use Bootgly\WPI\Endpoints\Servers\Disconnecting;


/**
 * One terminal generation of scheduled work.
 *
 * Weak aliases let the scheduler and response locate the same generation
 * without adding collision-prone methods to public/non-final reactor classes.
 * A completed old token cannot affect a Fiber after that object is pooled and
 * linked to a new generation.
 */
final class Cancellation implements Disconnecting
{
   private const int ACTIVE = 0;
   private const int FINISHED = 1;
   private const int CANCELLED = 2;

   /** @var null|WeakMap<object,self> */
   private static null|WeakMap $Owners = null;

   /** @var null|WeakMap<object,true> */
   private null|WeakMap $Aliases = null;
   private int $state = self::ACTIVE;
   /**
    * Whether this generation ended by cancellation — as opposed to a normal
    * completion or a handoff, which settle it the same way for `check()`.
    */
   public bool $cancelled {
      get => $this->state === self::CANCELLED;
   }
   /** @var array<int,Closure(self,bool):void> */
   private array $Observers = [];


   /**
    * Open and publish a fresh generation for one owner.
    */
   public static function open (object $Owner): self
   {
      $Token = new self;
      self::link($Owner, $Token);

      return $Token;
   }

   /**
    * Link another weak owner alias to this exact generation.
    */
   public static function link (object $Owner, self $Token): void
   {
      if ($Token->state !== self::ACTIVE) { // @phpstan-ignore notIdentical.alwaysFalse
         return;
      }

      $Owners = self::$Owners ??= new WeakMap;

      // ! Cancel and fully drain a previous generation before publishing the
      //   requested one. Its observers may re-enter link(); the loop removes
      //   every intervening generation without letting a stale callback win.
      while (true) {
         $Previous = $Owners[$Owner] ?? null;
         if ($Previous === null || $Previous === $Token) {
            break;
         }

         unset($Owners[$Owner]);
         if ($Previous->Aliases !== null) {
            unset($Previous->Aliases[$Owner]);
         }
         $Previous->cancel();
      }

      if ($Token->state !== self::ACTIVE) { // @phpstan-ignore notIdentical.alwaysFalse
         return;
      }

      $Owners[$Owner] = $Token;
      $Aliases = $Token->Aliases ??= new WeakMap;
      $Aliases[$Owner] = true;
   }

   /**
    * Claim one owner for a generation without terminalizing its prior token.
    *
    * Deferred nesting uses this as a transactional selection lease: the child
    * must be visible while its callback runs, while the parent remains active
    * until scheduler admission commits. A failed child can therefore restore
    * the returned prior generation and let the parent select an error response.
    */
   public static function claim (object $Owner, self $Token): null|self
   {
      $Owners = self::$Owners ??= new WeakMap;
      $Previous = $Owners[$Owner] ?? null;

      if ($Token->state !== self::ACTIVE) {
         return $Previous;
      }
      if ($Previous === $Token) {
         return $Previous;
      }

      if ($Previous !== null) {
         unset($Owners[$Owner]);
         if ($Previous->Aliases !== null) {
            unset($Previous->Aliases[$Owner]);
         }
      }

      $Owners[$Owner] = $Token;
      $Aliases = $Token->Aliases ??= new WeakMap;
      $Aliases[$Owner] = true;

      return $Previous;
   }

   /**
    * Fetch the generation currently linked to one owner.
    */
   public static function fetch (object $Owner): null|self
   {
      $Owners = self::$Owners;

      return $Owners === null ? null : ($Owners[$Owner] ?? null);
   }


   /**
    * Check whether this generation is terminal.
    */
   public function check (): bool
   {
      return $this->state !== self::ACTIVE;
   }

   /**
    * Observe terminal completion or cancellation.
    *
    * @param Closure(self,bool):void $Observer The boolean is true only for
    *    cancellation; a normal handoff/completion reports false.
    */
   public function observe (Closure $Observer): bool
   {
      if ($this->state !== self::ACTIVE) {
         try {
            $Observer($this, $this->state === self::CANCELLED);
         }
         catch (Throwable) {
            // Terminal observers are isolated from one another.
         }

         return false;
      }

      $this->Observers[] = $Observer;

      return true;
   }

   /**
    * Mark this generation normally complete exactly once.
    */
   public function finish (): bool
   {
      return $this->settle(self::FINISHED);
   }

   /**
    * Cancel this generation exactly once.
    */
   public function cancel (): bool
   {
      return $this->settle(self::CANCELLED);
   }

   /**
    * Cancel work owned by a closing transport or protocol scope.
    */
   public function disconnect (): void
   {
      $this->cancel();
   }

   /**
    * Commit one terminal state and notify a stable observer snapshot.
    */
   private function settle (int $state): bool
   {
      if ($this->state !== self::ACTIVE) {
         return false;
      }

      $this->state = $state;

      // ! Unpublish this exact generation before callbacks so pooled owners
      //   can be linked to new work without an old observer touching it.
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
            $Observer($this, $state === self::CANCELLED);
         }
         catch (Throwable) {
            // Cancellation/cleanup must continue through every observer.
         }
      }

      return true;
   }
}
