<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Process;


use const BOOTGLY_STORAGE_DIR;
use function basename;
use function count;
use function glob;
use function is_array;
use function is_int;
use function is_string;
use function strlen;
use function substr;
use Throwable;

use Bootgly\ACI\Process\State;


/**
 * Discovery over the instance registry (`storage/pids/`): locate, enumerate and
 * re-authenticate running instances by their ENCODED id — the filename-safe form
 * produced by the project registry (callers encode before calling; this layer
 * never derives names).
 */
class States
{
   /**
    * Locate a running instance's authenticated process data.
    *
    * @param string $id Encoded registry id (e.g. `Demo~HTTP_Server_CLI`).
    * @param null|string $instance Optional instance qualifier — the bound port for servers,
    *                              the master PID for console instances.
    *
    * @return null|array{master:int,workers:array<int>,started:int,status?:string,type:string,host?:string,port?:int,tap?:string,project?:string,AutoTLS?:array<string,mixed>}
    */
   public static function locate (string $id, null|string $instance = null): null|array
   {
      try {
         $State = new State($id, $instance);
      }
      catch (Throwable) {
         return null;
      }

      $data = $State->read();
      if (
         is_array($data) === false
         || is_int($data['master'] ?? null) === false
         || $data['master'] <= 0
         || is_array($data['workers'] ?? null) === false
         || count($data['workers']) > 4096
         || is_int($data['started'] ?? null) === false
         || is_string($data['type'] ?? null) === false
         || $data['type'] === ''
         || (
            $data['type'] === 'WPI'
            && (
               is_string($data['host'] ?? null) === false
               || is_int($data['port'] ?? null) === false
            )
         )
         || $State->authenticate($data['master']) === false
      ) {
         return null;
      }

      $Workers = [];
      $seen = [];
      foreach ($data['workers'] as $workerPID) {
         if (
            is_int($workerPID) === false
            || $workerPID <= 0
            || isSet($seen[$workerPID])
         ) {
            return null;
         }
         $seen[$workerPID] = true;
         if ($State->authenticate($workerPID, parent: $data['master'])) {
            $Workers[] = $workerPID;
         }
      }
      $data['workers'] = $Workers;

      // ? The Auto-TLS helper PID rides in the SAME runtime-writable discovery
      //   state as everything else here, but unlike master/workers it used to
      //   pass through unauthenticated — the stop path then signalled it after
      //   only a `/proc/<pid>/cmdline` substring match, so a compromised
      //   runtime UID could point it at another project's similarly titled
      //   helper and let a privileged operator deliver the signal (audit M6).
      //
      // ! Bind it to the already-authenticated master HERE, while that master
      //   is still alive. The signal site runs after the master is SIGKILLed,
      //   at which point the helper may already be reparented and PPid can no
      //   longer prove anything.
      $lease = $data['AutoTLS'] ?? null;
      if (is_array($lease)) {
         $helper = $lease['helper'] ?? null;
         if (
            is_int($helper) === false
            || $helper < 2
            || $State->authenticate($helper, parent: $data['master']) === false
         ) {
            unset($lease['helper']);
            $data['AutoTLS'] = $lease;
         }
      }

      /** @var array{master:int,workers:array<int>,started:int,status?:string,type:string,host?:string,port?:int,tap?:string,project?:string,AutoTLS?:array<string,mixed>} $data */
      return $data;
   }

   /**
    * List all running instances registered under an encoded id.
    *
    * @param string $id Encoded registry id.
    *
    * @return array<string, array{master:int,workers:array<int>,started:int,status?:string,type:string,host?:string,port?:int,tap?:string,project?:string,AutoTLS?:array<string,mixed>}>
    *         Keys are instance qualifiers ('' for legacy unqualified files, the bound
    *         port — or console master PID — otherwise)
    */
   public static function scan (string $id): array
   {
      $pidsDir = BOOTGLY_STORAGE_DIR . 'pids/';
      $instances = [];

      // @ Primary instance
      $primary = self::locate($id);
      if ($primary !== null) {
         $instances[''] = $primary;
      }

      // @ Qualified instances (e.g. Demo~HTTP_Server_CLI.8082.json)
      $pattern = "$pidsDir$id.*.json";
      $files = glob($pattern);
      if ($files !== false) {
         foreach ($files as $file) {
            $basename = basename($file, '.json'); // Demo~HTTP_Server_CLI.8082
            $instance = substr($basename, strlen($id) + 1); // 8082
            $data = self::locate($id, $instance);
            if ($data !== null) {
               $instances[$instance] = $data;
            }
         }
      }

      return $instances;
   }

   /**
    * Re-authenticate a PID immediately before a control action.
    *
    * @param string $id Encoded registry id.
    * @param string $instance Instance qualifier ('' for the unqualified file).
    * @param int $PID Process to authenticate.
    * @param null|int $parent Required parent PID, when the role demands one.
    *
    * @phpstan-impure The kernel process and flock state can change between calls.
    */
   public static function authenticate (
      string $id,
      string $instance,
      int $PID,
      null|int $parent = null
   ): bool
   {
      try {
         $State = new State($id, $instance !== '' ? $instance : null);
      }
      catch (Throwable) {
         return false;
      }

      return $State->authenticate($PID, $parent);
   }
}
