<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;


use Closure;
use Throwable;

use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;


/** Internal read-only authority for a Connection's terminal lifecycle. */
final class Authority
{
   // * Metadata
   /** Connection-scoped state reader, bound once without exposing mutation. */
   private static null|Closure $Reader = null;
   /** Process terminal-depth reader, bound once without exposing mutation. */
   private static null|Closure $Guard = null;
   /** Admission-manager lifecycle reader, bound without exposing mutation. */
   private static null|Closure $ManagerGuard = null;


   /**
    * Check whether a Connection is still authorized to process application I/O.
    *
    * Public status is retained as a fail-closed veto for compatibility, but
    * private lifecycle flags are the positive authority and cannot be reset by
    * application code after close().
    */
   public static function check (Connection $Connection): bool
   {
      if (self::$Reader === null) {
         self::$Reader = Closure::bind(
            static function (Connection $Connection): bool {
               if (
                  isSet(Connection::$Authorities) === false
                  || isSet(Connection::$Authorities[$Connection]) === false
               ) {
                  return false;
               }
               // ! Read the hookable compatibility veto first. Any side effect
               //   it triggers must complete before private authority is read.
               $status = $Connection->status;

               return $status <= Connections::STATUS_ESTABLISHED
                  && isSet(Connection::$Authorities[$Connection]) // @phpstan-ignore isset.offset
                  && $Connection->closing === false
                  && $Connection->closed === false;
            },
            null,
            Connection::class,
         );
      }

      try {
         return (self::$Reader)($Connection);
      }
      catch (Throwable) {
         return false;
      }
   }

   /**
    * Check framework lifecycle work or an existing same-key admission token.
    *
    * @param null|string $peer Immutable peer key for a prospective direct object.
    */
   public static function guard (null|string $peer = null): bool
   {
      if (self::$Guard === null) {
         self::$Guard = Closure::bind(
            static function (): bool {
               return Connection::$terminalDepth > 0;
            },
            null,
            Connection::class,
         );
      }
      if (self::$ManagerGuard === null) {
         self::$ManagerGuard = Closure::bind(
            static function (null|string $peer): bool {
               return Connections::$Construction !== null
                  || Connections::$Configuration !== null
                  || Connections::$Starting !== null
                  || Connections::$committing
                  || Connections::$admissionDepth > 0
                  || Connections::$withdrawalDepth > 0
                  || (
                     $peer !== null
                     && isSet(Connections::$Peers)
                     && isSet(Connections::$Peers[$peer])
                  );
            },
            null,
            Connections::class,
         );
      }

      try {
         return (self::$Guard)()
            || (self::$ManagerGuard)($peer)
            || Lease::guard();
      }
      catch (Throwable) {
         return true;
      }
   }
}
