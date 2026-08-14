<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints\Servers;


use SplObjectStorage;
use Throwable;
use WeakMap;


/**
 * Teardown ownership for public/non-final transport and protocol objects.
 *
 * The weak scope registry avoids adding collision-prone public methods to
 * Connection/Stream while keeping late and re-entrant attachment terminal.
 */
final class Ownership
{
   /**
    * Both collections are created on demand, never by the mere act of opening
    * or closing a scope. `attach()` is reachable only from a deferred response,
    * while `close()` runs for every HTTP/2 stream and every connection, so the
    * common scope must not pay for bookkeeping it never uses.
    *
    * @var null|WeakMap<object,array{
    *    closed:bool,
    *    Owners:null|SplObjectStorage<Disconnecting,true>,
    *    Notified:null|WeakMap<Disconnecting,true>
    * }>
    */
   private static null|WeakMap $Scopes = null;


   /**
    * Attach one teardown owner to a live scope.
    */
   public static function attach (object $Scope, Disconnecting $Owner): void
   {
      self::$Scopes ??= new WeakMap;
      $State = self::$Scopes[$Scope] ?? null;

      if (($State['closed'] ?? false) === true) {
         // ! Record terminal notification before invoking extension code.
         //   The same owner can then attach itself from disconnect() without
         //   recursing, and repeated late attachment remains exactly once.
         $Notified = $State['Notified'];
         if ($Notified !== null && isset($Notified[$Owner])) {
            return;
         }

         // ! A scope that closed without owners carries no Notified map yet.
         //   It has to exist before the callback, or a re-entrant attach from
         //   inside disconnect() would notify this same owner a second time.
         if ($Notified === null) {
            /** @var WeakMap<Disconnecting,true> $Notified */
            $Notified = new WeakMap;
            $State['Notified'] = $Notified;
         }

         $Notified[$Owner] = true;
         self::$Scopes[$Scope] = $State;

         try {
            $Owner->disconnect();
         }
         catch (Throwable) {
            // Late ownership cannot reopen or break a closed scope.
         }
         return;
      }

      if ($State === null) {
         $State = [
            'closed' => false,
            'Owners' => null,
            'Notified' => null,
         ];
      }

      $Owners = $State['Owners'];
      if ($Owners === null) {
         /** @var SplObjectStorage<Disconnecting,true> $Owners */
         $Owners = new SplObjectStorage;
         $State['Owners'] = $Owners;
      }

      $Owners->attach($Owner, true);
      self::$Scopes[$Scope] = $State;
   }

   /**
    * Detach one owner after its work completed normally.
    */
   public static function detach (object $Scope, Disconnecting $Owner): void
   {
      $State = self::$Scopes[$Scope] ?? null;
      if ($State === null) {
         return;
      }

      // ! A closed scope retains notified identities as terminal tombstones.
      //   Removing one would allow a later attach to notify it twice.
      if ($State['closed']) {
         return;
      }

      $State['Owners']?->detach($Owner);
      self::$Scopes[$Scope] = $State;
   }

   /**
    * Close a scope and notify every attached owner exactly once.
    */
   public static function close (object $Scope): void
   {
      self::$Scopes ??= new WeakMap;
      $State = self::$Scopes[$Scope] ?? null;

      if (($State['closed'] ?? false) === true) {
         return;
      }

      $Owners = $State['Owners'] ?? null;

      // ? Nothing ever attached — the common case for every HTTP/2 stream and
      //   every connection. Only the terminal tombstone is required, so a late
      //   attach still observes closure; both collections stay unallocated and
      //   attach() creates the Notified map on demand.
      if ($Owners === null) {
         self::$Scopes[$Scope] = [
            'closed' => true,
            'Owners' => null,
            'Notified' => $State['Notified'] ?? null,
         ];

         return;
      }

      /** @var WeakMap<Disconnecting,true> $Notified */
      $Notified = $State['Notified'] ?? new WeakMap;

      /** @var array<int,Disconnecting> $Pending */
      $Pending = [];
      foreach ($Owners as $Owner) {
         $Pending[] = $Owner;
         $Notified[$Owner] = true;
      }

      // ! Commit before callbacks. Weak notified tombstones stop recursive or
      //   repeated attachment without retaining a closed owner/scope cycle;
      //   the local snapshot keeps each pending owner alive through its call.
      //   The owner storage is dropped rather than replaced by an empty one —
      //   a closed scope never attaches again.
      self::$Scopes[$Scope] = [
         'closed' => true,
         'Owners' => null,
         'Notified' => $Notified,
      ];

      foreach ($Pending as $Owner) {
         try {
            $Owner->disconnect();
         }
         catch (Throwable) {
            // One teardown owner cannot suppress another or the scope close.
         }
      }
   }
}
