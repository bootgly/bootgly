<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * Inspired by Workerman\Timer
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Events;


class Timer
{
   // * Config
   // ...

   // * Data
   /**
    * @var array<int,array<int,array{0:int,1:callable,2:array<mixed>,3:bool}>>
    */
   protected static array $tasks = [];
   /** @var array<int,bool> */
   protected static array $status = [];

   // * Metadata
   protected static int $id = 0;


   public static function init (callable $handler): bool
   {
      if (function_exists('pcntl_signal')) {
         return pcntl_signal(SIGALRM, $handler, false);
      }

      return false;
   }

   /**
    * Add a timer.
    *
    * @param int $interval
    * @param callable $handler
    * @param array<mixed> $args
    * @param bool $persistent
    * @return int|false
    */
   public static function add (
      int $interval, callable $handler, array $args = [], bool $persistent = true
   ): int|false
   {
      if ($interval <= 0) {
         return false;
      }

      if ( empty(self::$tasks) ) {
         pcntl_alarm(1);
      }

      $runtime = time() + $interval;

      if ( ! isSet(self::$tasks[$runtime]) ) {
         self::$tasks[$runtime] = [];
      }

      self::$id = (self::$id === PHP_INT_MAX) ? 1 : ++self::$id;

      self::$status[self::$id] = true;
      self::$tasks[$runtime][self::$id] = [
         $interval, $handler, $args, $persistent
      ];

      return self::$id;
   }

   public static function tick (): void
   {
      if ( empty(self::$tasks) ) {
         pcntl_alarm(0);

         return;
      }

      pcntl_alarm(1);

      foreach (self::$tasks as $runtime => $tasks) {
         if (time() >= $runtime) {
            foreach ($tasks as $index => $task) {
               $interval   = $task[0];
               $handler    = $task[1];
               $args       = $task[2];
               $persistent = $task[3];

               try {
                  call_user_func_array($handler, $args);
               }
               catch (\Throwable) {
                  // ...
               }

               if ($persistent && ! empty(self::$status[$index])) {
                  $_runtime_ = time() + $interval;

                  if ( ! isSet(self::$tasks[$_runtime_]) ) {
                     self::$tasks[$_runtime_] = [];
                  }

                  self::$tasks[$_runtime_][$index] = [
                     $interval, $handler, $args, $persistent
                  ];
               }
            }

            unset(self::$tasks[$runtime]);
         }
      }
   }

   public static function del (int $id = 0): bool
   {
      // @ Delete all tasks
      if ($id === 0) {
         self::$tasks = [];
         self::$status = [];
   
         pcntl_alarm(0);

         return true;
      }

      // @ Delete one task by id
      foreach (self::$tasks as $runtime => $tasks) {
         if ( array_key_exists($id, $tasks) ) {
            unset(self::$tasks[$runtime][$id]);
         }
      }

      // @ Delete status
      if ( array_key_exists($id, self::$status) ) {
         unset(self::$status[$id]);
      }

      // @ Reset timer alarm if no status
      if (empty(self::$status)) {
         pcntl_alarm(0);
      }

      return true;
   }
}
