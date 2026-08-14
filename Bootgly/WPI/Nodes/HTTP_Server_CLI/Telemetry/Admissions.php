<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;


use const PHP_INT_MIN;
use Closure;
use Throwable;
use WeakMap;

use Bootgly\ABI\Events\Emitter;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;


/**
 * Non-stoppable request admission observers, isolated from the public event bus.
 *
 * Public Received listeners remain mutable and may stop propagation or throw.
 * Encoders invoke this final registry first so one application listener cannot
 * make an admitted exchange invisible to already-booted core telemetry.
 */
final class Admissions
{
   /** @var null|WeakMap<Emitter,array<int,Closure(Exchange):void>> */
   private static null|WeakMap $Emitters = null;


   /**
    * Register one core observer for an emitter.
    *
    * @param Closure(Exchange):void $Observer
    */
   public static function listen (Emitter $Emitter, Closure $Observer): void
   {
      // ? Encoders already perform a direct, allocation-free Received-listener
      //   lookup. Ensure that lookup also gates core admission, avoiding an
      //   Admissions method call on the fully unobserved synchronous path.
      if ($Emitter->check(RequestEvents::Received) === false) {
         $Emitter->listen(RequestEvents::Received, static function (): void {
            // Core admission runs before the mutable public event bus.
         }, priority: PHP_INT_MIN);
      }

      $Emitters = self::$Emitters ??= new WeakMap;
      /** @var array<int,Closure(Exchange):void> $Observers */
      $Observers = $Emitters[$Emitter] ?? [];
      $Observers[] = $Observer;
      $Emitters[$Emitter] = $Observers;
   }

   /**
    * Open and admit a lifecycle only when this emitter has core observers.
    *
    * Encoders call this only after their direct Received-listener gate. Keeping
    * the empty-registry branch allocation-free preserves the observability
    * opt-in contract while observed requests still receive their token before
    * the mutable public event bus runs.
    */
   public static function open (Emitter $Emitter, Request $Request): null|Exchange
   {
      $Emitters = self::$Emitters;
      if ($Emitters === null || ($Emitters[$Emitter] ?? []) === []) {
         return null;
      }

      $Exchange = new Exchange;
      Exchange::admit($Request, $Exchange);
      self::admit($Emitter, $Exchange);

      return $Exchange;
   }

   /**
    * Admit an exchange to every core observer without public propagation.
    */
   public static function admit (Emitter $Emitter, Exchange $Exchange): void
   {
      $Emitters = self::$Emitters;
      if ($Emitters === null) {
         return;
      }

      foreach (($Emitters[$Emitter] ?? []) as $Observer) {
         try {
            $Observer($Exchange);
         }
         catch (Throwable) {
            // ! Core observers are isolated: one broken registry must not
            //   blind later registries or abort application event delivery.
         }
      }
   }
}
