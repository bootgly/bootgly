<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;


use Closure;
use Throwable;
use WeakMap;
use WeakReference;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session as RequestSession;


/**
 * Non-stoppable callbacks that run during Session ID regeneration.
 *
 * Security components register privilege-bound state transitions here rather
 * than on the replaceable application Emitter. Persistent descriptors retain
 * configured invariants independently of middleware-object lifetime, while
 * object-owned callbacks remain weak and are reclaimed after their owner dies.
 */
final class Regenerators
{
   // * Data
   /**
    * @var array<int,array{
    *    Owner:null|WeakReference<object>,
    *    Callback:Closure(RequestSession):void
    * }>
    */
   private static array $Callbacks = [];
   /** @var null|WeakMap<object,int> */
   private static null|WeakMap $Owners = null;
   /** @var array<string,int> */
   private static array $Names = [];

   // * Metadata
   private static int $nextID = 0;


   /**
    * Register or replace the invariant callback owned by a component.
    *
    * @param Closure(RequestSession):void $Callback
    */
   public static function register (object $Owner, Closure $Callback): void
   {
      self::$Owners ??= new WeakMap;
      $id = self::$Owners[$Owner] ?? null;

      if ($id === null) {
         $id = self::$nextID++;
         self::$Owners[$Owner] = $id;
         self::$Callbacks[$id] = [
            'Owner' => WeakReference::create($Owner),
            'Callback' => $Callback,
         ];

         return;
      }

      self::$Callbacks[$id]['Callback'] = $Callback;
   }

   /**
    * Preserve or replace one configured invariant by stable descriptor.
    *
    * @param Closure(RequestSession):void $Callback
    */
   public static function preserve (string $name, Closure $Callback): void
   {
      $id = self::$Names[$name] ?? null;

      if ($id === null) {
         $id = self::$nextID++;
         self::$Names[$name] = $id;
         self::$Callbacks[$id] = [
            'Owner' => null,
            'Callback' => $Callback,
         ];

         return;
      }

      self::$Callbacks[$id]['Callback'] = $Callback;
   }

   /**
    * Execute every live invariant before ordinary regeneration observers.
    */
   public static function execute (RequestSession $Session): void
   {
      $Failure = null;

      foreach (self::$Callbacks as $id => $Entry) {
         $Owner = $Entry['Owner'];
         if ($Owner !== null && $Owner->get() === null) {
            unset(self::$Callbacks[$id]);
            continue;
         }

         try {
            $Entry['Callback']($Session);
         }
         catch (Throwable $Throwable) {
            // ! Complete every security invariant before propagating the
            //   first failure. Upstream recovery cannot otherwise expose a
            //   partially transitioned privilege context.
            $Failure ??= $Throwable;
         }
      }

      if ($Failure !== null) {
         throw $Failure;
      }
   }
}
