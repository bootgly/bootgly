<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * Inspired by Workerman\Timer
 * --------------------------------------------------------------------------
 */

namespace Bootgly\OS\Process;


use \Throwable;


class Timer
{
   // * Config
   // ...

   // * Data
   protected static array $tasks = [];
   protected static array $status = [];

   // * Meta
   protected static int $id = 0;


   public static function init (callable $handler) : bool
   {
      if (function_exists('pcntl_signal')) {
         return pcntl_signal(SIGALRM, $handler, false);
      }

      return false;
   }

   public static function add (int $interval, callable $handler, array $args = [], bool $persistent = true)
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

   // TODO Refactor this function to reduce its Cognitive Complexity from 21 to the 15 allowed.
   public static function tick ()
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
               } catch (Throwable) {
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

   public static function del (int $id = 0)
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
