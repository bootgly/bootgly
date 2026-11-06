<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Process;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `Signals->send()` reaches every child of the Process: the children that
 * serve a slot and the retirees still finishing their work
 * (`Children->retire()`). A retiree's delivery is best-effort — one that is
 * already gone never fails the dispatch — while a serving child that cannot
 * be reached still does. Retirees are signalled at once: the 0.1 s spacing
 * between serving children is never paid per retiree, so a fan-out never
 * holds the master's loop for them.
 */
return new Test(
   description: 'Process\Signals: send() reaches the serving children and the retirees; a gone retiree never fails it',
   skip: function_exists('pcntl_fork') === false || function_exists('posix_kill') === false,
   test: new Assertions(Case: function (): Generator {
      $Process = new Process('SignalsSendTest', (string) getmypid());
      $marks = BOOTGLY_STORAGE_DIR . 'signals-send-' . getmypid();
      @mkdir($marks, 0700);

      // @ Two children that note every SIGUSR1 they receive
      $Process->fork(2, static function (Process $Worker, int $index) use ($marks): void {
         // ! Out without this runner's shutdown functions: a fresh image
         pcntl_exec('/bin/sh', ['-c', "trap 'echo x >> {$marks}/{$index}' USR1; while :; do sleep 0.05; done"]);
      });
      $PIDs = $Process->Children->PIDs;
      // ! Their traps are set once the shell runs
      usleep(300_000);

      // @ Slot 0 is retired: it keeps working, out of its slot
      $Process->Children->retire($PIDs[0] ?? -1);
      $sent = $Process->Signals->send(SIGUSR1, master: false);
      /** How many SIGUSR1 a child noted. */
      $Noted = static function (int $index) use ($marks): int {
         $deadline = hrtime(true) + 2_000_000_000;
         do {
            clearstatcache();
            $count = substr_count((string) @file_get_contents("{$marks}/{$index}"), 'x');
            if ($count > 0) {
               return $count;
            }
            usleep(20_000);
         } while (hrtime(true) < $deadline);

         return 0;
      };
      $Reached = [
         'sent' => $sent,
         'the serving child' => $Noted(1),
         'the retiree' => $Noted(0),
      ];

      // @ The retiree is gone (reaped by someone else, still listed)
      posix_kill($PIDs[0] ?? -1, SIGKILL);
      pcntl_waitpid($PIDs[0] ?? -1, $status);
      $Reached['a gone retiree never fails it'] = $Process->Signals->send(SIGUSR1, master: false);

      // @ A serving child that cannot be reached still fails it
      posix_kill($PIDs[1] ?? -1, SIGKILL);
      pcntl_waitpid($PIDs[1] ?? -1, $status);
      $Reached['a gone serving child fails it'] = $Process->Signals->send(SIGUSR1, master: false) === false;

      // ! Nothing left behind
      foreach ([0, 1] as $index) {
         @unlink("{$marks}/{$index}");
      }
      @rmdir($marks);

      yield new Assertion(description: 'send() reaches the serving children and the retirees')
         ->expect($Reached, Op::Identical, [
            'sent' => true,
            'the serving child' => 1,
            'the retiree' => 1,
            'a gone retiree never fails it' => true,
            'a gone serving child fails it' => true,
         ])
         ->assert();

      // @ Three retirees and no serving child: signalled at once
      $Retiring = new Process('SignalsSendTest', (string) getmypid());
      $Retiring->fork(3, static function (Process $Worker, int $index): void {
         // ! Out without this runner's shutdown functions: a fresh image
         pcntl_exec('/bin/sh', ['-c', "trap '' USR1; while :; do sleep 0.05; done"]);
      });
      $Retired = $Retiring->Children->PIDs;
      usleep(300_000);
      foreach ($Retired as $PID) {
         $Retiring->Children->retire($PID);
      }
      $started = hrtime(true);
      $sent = $Retiring->Signals->send(SIGUSR1, master: false);
      $elapsed = (hrtime(true) - $started) / 1_000_000_000;
      foreach ($Retired as $PID) {
         posix_kill($PID, SIGKILL);
         pcntl_waitpid($PID, $status);
         $Retiring->Children->remove($PID);
      }
      $seconds = round($elapsed, 3);

      yield new Assertion(description: "send() never spaces out the retirees ({$seconds} s for three)")
         ->expect(['sent' => $sent, 'under 0.1 s' => $elapsed < 0.1], Op::Identical, ['sent' => true, 'under 0.1 s' => true])
         ->assert();
   }),
);
