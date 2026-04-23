<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use const WNOHANG;
use function array_search;
use function cli_get_process_title;
use function cli_set_process_title;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getpid;
use Closure;

use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\ACI\Process\Children;
use Bootgly\ACI\Process\Group;
use Bootgly\ACI\Process\Signals;
use Bootgly\ACI\Process\State;
use Bootgly\ACI\Process\User;


class Process
{
   use LoggableEscaped;


   public Children $Children;

   public Signals $Signals;

   public State $State;

   public User $User;
   public Group $Group;

   // * Config
   // ...

   // * Data
   public string $title {
      get {
         $title = cli_get_process_title();

         if (!$title) {
            $title = 'Bootgly: unknown process';
         }

         return $title;
      }
      set (string $value) {
         cli_set_process_title($value);
      }
   }

   // * Metadata
   public int $id {
      get => posix_getpid();
   }
   public string $level {
      get => $this->id === self::$master
         ? 'master'
         : 'child';
   }
   // # Id
   public static int $index = 0;
   public static int $master;
   // # Lifecycle
   public bool $stopping = false;


   public function __construct (string $id, null|string $instance = null)
   {
      $this->Children = new Children;

      $this->Signals  = new Signals($this);

      $this->State    = new State($id, $instance);

      $this->User     = new User;
      $this->Group    = new Group;

      // * Metadata
      self::$master = posix_getpid();
   }

   public function fork (int $workers, Closure $instance): void
   {
      for ($index = 0; $index < $workers; $index++) {
         $PID = pcntl_fork();

         // # Child process
         if ($PID === 0) {
            $this->Children->push($this->id, $index);

            self::$index = $index + 1;

            $instance($this, $index);

            exit(0);
         }
         // # Master process
         else if ($PID > 0) {
            $this->Children->push($PID, $index);
         }
         // Error
         else if ($PID === -1) {
            die('Could not fork process!');
         }
      }
   }

   /**
    * Recover crashed children — reap via pcntl_waitpid.
    * Returns the dead worker index + PID for the caller to handle reforking.
    *
    * @return array{int,int}|null [deadIndex, deadPID] or null if none reaped.
    */
   public function recover (): null|array
   {
      if ($this->stopping) {
         return null;
      }

      $pid = pcntl_waitpid(-1, $status, WNOHANG);

      if ($pid <= 0) {
         return null;
      }

      $deadIndex = array_search($pid, $this->Children->PIDs, true);

      if ($deadIndex === false) {
         return null;
      }

      $this->Children->remove($pid);

      return [(int) $deadIndex, $pid];
   }
}
