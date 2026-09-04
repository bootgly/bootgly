<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * Inspired by Workerman\Timer
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Events\Timer;


use const PHP_INT_MAX;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_reverse;
use Closure;
use Throwable;


/** Process-local notification boundary for a full Timer wheel reset. */
final class Reset
{
   // * Metadata
   /** @var array<int,Closure> */
   private static array $Observers = [];
   /** @var array<int,bool> Owners that must recover after bounded dispatch. */
   private static array $Recoveries = [];
   /** @var array<int,bool> Identifiers reserved by the current dispatch. */
   private static array $reserved = [];
   private static int $id = 0;
   private static bool $notifying = false;
   private static bool $pending = false;
   /** Nested-notification generation used to identify the causal observer. */
   private static int $generation = 0;
   /** Last observer ID visited by the process-global round-robin order. */
   private static int $cursor = 0;
   /** Maximum callbacks executed by one outer reset notification. */
   private const int NOTIFY_BUDGET = 256;
   /** Maximum callbacks executed by the sealed infrastructure tier. */
   private const int RECOVERY_BUDGET = 8;


   /**
    * Register an owner callback that revalidates state after a full reset.
    *
    * @param Closure $Observer Owner revalidation callback.
    */
   public static function add (Closure $Observer): int
   {
      $id = self::identify();
      self::$Observers[$id] = $Observer;

      return $id;
   }

   /** Register one sealed infrastructure recovery callback. */
   private static function keep (Closure $Observer): int // @phpstan-ignore method.unused
   {
      $id = self::identify();
      self::$Observers[$id] = $Observer;
      self::$Recoveries[$id] = true;

      return $id;
   }

   /** Remove one reset observer. */
   public static function del (int $id): void
   {
      if (isSet(self::$Recoveries[$id])) {
         return;
      }
      $Observer = self::$Observers[$id] ?? null;
      unset(self::$Observers[$id]);
      self::release($Observer);
   }

   /** Remove one sealed infrastructure recovery callback. */
   private static function drop (int $id): void // @phpstan-ignore method.unused
   {
      $Observer = self::$Observers[$id] ?? null;
      unset(self::$Observers[$id], self::$Recoveries[$id]);
      self::release($Observer);
   }

   /** Allocate an identifier without replacing a live or dispatching observer. */
   private static function identify (): int
   {
      do {
         self::$id = self::$id === PHP_INT_MAX ? 1 : self::$id + 1;
      }
      while (
         array_key_exists(self::$id, self::$Observers)
         || array_key_exists(self::$id, self::$reserved)
      );

      return self::$id;
   }

   /** Notify a stable snapshot without trusting observer failures. */
   public static function notify (): void
   {
      if (self::$notifying) {
         self::$pending = true;
         self::$generation = self::$generation === PHP_INT_MAX
            ? 1
            : self::$generation + 1;
         return;
      }
      self::$notifying = true;
      $suppressed = [];
      $remaining = self::NOTIFY_BUDGET;
      $exhausted = false;
      try {
         do {
            self::$pending = false;
            $Observers = self::$Observers;
            foreach (array_keys($Observers) as $id) {
               self::$reserved[$id] = true;
            }
            foreach (self::order(array_keys($Observers)) as $id) {
               $Observer = $Observers[$id] ?? null;
               unset($Observers[$id]);
               if ($Observer === null) {
                  continue;
               }
               if (isSet(self::$Recoveries[$id])) {
                  self::release($Observer);
                  continue;
               }
               if ($remaining <= 0) {
                  self::release($Observer);
                  while ($Observers !== []) {
                     $id = array_key_first($Observers);
                     $Observer = $Observers[$id];
                     unset($Observers[$id]);
                     self::release($Observer);
                  }
                  self::$pending = false;
                  $exhausted = true;
                  break;
               }
               $remaining--;
               self::$cursor = $id;
               $generation = self::$generation;
               if (array_key_exists($id, $suppressed)) {
                  self::release($Observer);
                  continue;
               }

               try {
                  $Observer();
               }
               catch (Throwable) {
                  // One owner cannot prevent other reset recovery callbacks.
               }
               self::release($Observer);
               if ($generation !== self::$generation) {
                  $suppressed[$id] = true;
               }
            }
         }
         while (self::$pending && $exhausted === false);
         // @ Ordinary observers may spend the entire finite budget and issue
         //   one final nested wheel reset. Required infrastructure owners run
         //   after that boundary so Timer::del() cannot return unsupervised.
         self::recover();
      }
      finally {
         self::$pending = false;
         self::$notifying = false;
         self::$reserved = [];
      }
   }

   /** Execute recovery rounds while suppressing every causal resetter. */
   private static function recover (): void
   {
      $suppressed = [];
      // ! Freeze one recovery generation. Newly registered successors join
      //   the next outer notification, so a callback cannot manufacture an
      //   unbounded chain inside this synchronous reset.
      $IDs = array_reverse(array_keys(self::$Recoveries));
      $remaining = self::RECOVERY_BUDGET;
      do {
         self::$pending = false;
         foreach ($IDs as $id) {
            if ($remaining <= 0) {
               break;
            }
            if (array_key_exists($id, $suppressed)) {
               continue;
            }
            $Observer = self::$Observers[$id] ?? null;
            if ($Observer === null) {
               continue;
            }
            $remaining--;
            $generation = self::$generation;
            try {
               $Observer();
            }
            catch (Throwable) {
               // One infrastructure owner cannot block other recoveries.
            }
            self::release($Observer);
            if ($generation !== self::$generation) {
               // @ This exact observer caused at least one nested reset. Do
               //   not let it erase recovery state on the following round.
               $suppressed[$id] = true;
            }
         }
      }
      while (self::$pending && $remaining > 0);
   }

   /**
    * Rotate the descending snapshot after the last observer that consumed work.
    *
    * @param array<int,int> $IDs
    * @return array<int,int>
    */
   private static function order (array $IDs): array
   {
      $Before = [];
      $After = [];
      foreach (array_reverse($IDs) as $id) {
         if (self::$cursor > 0 && $id >= self::$cursor) {
            $After[] = $id;
            continue;
         }
         $Before[] = $id;
      }

      return [...$Before, ...$After];
   }

   /**
    * Release one detached observer without leaking capture destructor failures.
    *
    * @param-out null $Observer
    */
   private static function release (null|Closure &$Observer): void
   {
      if ($Observer === null) {
         return;
      }
      try {
         $Observer = null;
      }
      // @phpstan-ignore-next-line Closure captures may own throwing destructors.
      catch (Throwable) {
         // Observer registry state is already committed before user destruction.
      }
   }
}
