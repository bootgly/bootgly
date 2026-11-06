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
 * `Process->recover()` reaps one tracked child per call and returns its slot
 * index, its PID and its raw wait status — so a supervisor tells an orderly
 * exit and its code (a worker recycle exits 75) from a kill — and returns
 * null while the Process is stopping or reloading, and once nothing is left.
 *
 * A retiree (`Children->retire()`) — a child replaced in its slot while it
 * finishes its work — leaves the slot count and the serving PIDs, is still
 * reaped by `recover()` (flagged retired: its exit needs no replacement) and
 * still signalled and reaped by `terminate()`.
 */
return new Test(
   description: 'Process: recover() reaps a child with its index, PID, wait status and retirement',
   skip: function_exists('pcntl_fork') === false || function_exists('posix_kill') === false,
   test: new Assertions(Case: function (): Generator {
      $Process = new Process('ProcessRecoverTest', (string) getmypid());

      // @ Two children: slot 0 exits 75, slot 1 is killed
      $Process->fork(2, static function (Process $Worker, int $index): void {
         // ! Out without this runner's shutdown functions: a fresh image
         //   stops itself, then exits 75
         if ($index === 0) {
            pcntl_exec('/bin/sh', ['-c', 'kill -STOP $$; exit 75']);
         }

         posix_kill(posix_getpid(), SIGKILL);
      });
      $PIDs = $Process->Children->PIDs;

      // ! Slot 0 stops itself first: held while recover() must refuse
      $deadline = hrtime(true) + 5_000_000_000;
      $stopped = false;
      while ($stopped === false && hrtime(true) < $deadline) {
         $stopped = pcntl_waitpid($PIDs[0] ?? -1, $status, WNOHANG | WUNTRACED) === ($PIDs[0] ?? -1)
            && pcntl_wifstopped($status);
         usleep(5_000);
      }

      // @ Refused while stopping or reloading
      $Process->stopping = true;
      $whileStopping = $Process->recover();
      $Process->stopping = false;
      $Process->reloading = true;
      $whileReloading = $Process->recover();
      $Process->reloading = false;

      // @ Reap both, in any order
      posix_kill($PIDs[0] ?? -1, SIGCONT);
      $Reaped = [];
      $deadline = hrtime(true) + 5_000_000_000;
      while (count($Reaped) < 2 && hrtime(true) < $deadline) {
         $dead = $Process->recover();
         if ($dead === null) {
            usleep(5_000);
            continue;
         }
         [$index, $PID, $status, $retired] = $dead;
         $Reaped[$index] = [
            'retired' => $retired,
            'PID tracked' => $PID === ($PIDs[$index] ?? null),
            'exited' => pcntl_wifexited($status),
            'code' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
            'signal' => pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null,
         ];
      }
      ksort($Reaped);
      // ! Whatever is left (a failed run) is not leaked
      foreach ($Process->Children->PIDs as $PID) {
         posix_kill($PID, SIGKILL);
         pcntl_waitpid($PID, $status);
      }

      yield new Assertion(description: 'recover() returns [index, PID, status] per reaped child, and nothing while stopping, reloading or empty')
         ->expect([
            'stopped first' => $stopped,
            'stopping' => $whileStopping,
            'reloading' => $whileReloading,
            'reaped' => $Reaped,
            'then empty' => $Process->recover(),
            'untracked' => $Process->Children->PIDs,
         ], Op::Identical, [
            'stopped first' => true,
            'stopping' => null,
            'reloading' => null,
            'reaped' => [
               0 => ['retired' => false, 'PID tracked' => true, 'exited' => true, 'code' => 75, 'signal' => null],
               1 => ['retired' => false, 'PID tracked' => true, 'exited' => false, 'code' => null, 'signal' => SIGKILL],
            ],
            'then empty' => null,
            'untracked' => [],
         ])
         ->assert();

      // @ Retirees — slot 0 is retired and refilled, slot 1 serves on
      // ! The children below are ended by SIGTERM, which may reach one before
      //   its exec: born with the default action and the signal unblocked, it
      //   dies of it — a PHP handler or mask left in this runner by an earlier
      //   suite would swallow it or hold it pending through the exec
      //   (the mask is read first: installing a handler unblocks the signal)
      pcntl_sigprocmask(SIG_UNBLOCK, [SIGTERM], $Mask);
      $Handler = pcntl_signal_get_handler(SIGTERM);
      pcntl_signal(SIGTERM, SIG_DFL);
      try {
         $Process->fork(2, static function (Process $Worker, int $index): void {
            // ! Waits to be signalled, without this runner's shutdown functions
            pcntl_exec('/bin/sh', ['-c', 'exec sleep 30']);
         });
         $Old = $Process->Children->PIDs;
         $retired = $Process->Children->retire($Old[0] ?? -1);
         $unknown = $Process->Children->retire(-1);
         $twice = $Process->Children->retire($Old[0] ?? -1);
         $Retirement = [
            'retired' => $retired,
            'an unknown PID is refused' => $unknown,
            'a retiree is not retired twice' => $twice,
            'serving' => $Process->Children->PIDs === [1 => $Old[1] ?? -1],
            'retirees' => $Process->Children->retirees === [($Old[0] ?? -1) => 0],
            'slots counted' => $Process->Children->count(),
         ];
         // @ Its replacement takes slot 0 while the retiree lives
         $Process->Children->push($Old[1] ?? -1, 1);
         $Replacement = pcntl_fork();
         if ($Replacement === 0) {
            pcntl_exec('/bin/sh', ['-c', 'exec sleep 30']);
         }
         $Process->Children->push($Replacement, 0);
      }
      finally {
         // ! The runner keeps its own SIGTERM policy
         pcntl_signal(SIGTERM, $Handler);
         pcntl_sigprocmask(SIG_SETMASK, $Mask);
      }
      $Retirement['two PIDs for slot 0'] = $Process->Children->PIDs[0] === $Replacement
         && isSet($Process->Children->retirees[$Old[0] ?? -1]);
      $Retirement['slots counted with a replacement'] = $Process->Children->count();

      // @ The retiree ends: reaped as retired, the slot untouched
      posix_kill($Old[0] ?? -1, SIGTERM);
      $Dead = null;
      $deadline = hrtime(true) + 5_000_000_000;
      while ($Dead === null && hrtime(true) < $deadline) {
         $Dead = $Process->recover();
         usleep(5_000);
      }
      $Retirement['reaped as retired'] = $Dead !== null
         && $Dead[0] === 0
         && $Dead[1] === ($Old[0] ?? -1)
         && $Dead[3] === true
         && pcntl_wifsignaled($Dead[2]);
      $Retirement['slot 0 still served'] = ($Process->Children->PIDs[0] ?? 0) === $Replacement
         && $Process->Children->retirees === [];

      // @ A retiree is terminated with the serving children
      $Process->Children->retire($Replacement);
      $Survivors = [$Replacement, $Old[1] ?? -1];
      $Process->Children->terminate(timeout: 2);
      $alive = 0;
      foreach ($Survivors as $PID) {
         if ($PID > 0 && posix_kill($PID, 0)) {
            $alive++;
            posix_kill($PID, SIGKILL);
            pcntl_waitpid($PID, $status);
         }
      }
      $Retirement['terminate() ends serving and retired children'] = $alive === 0
         && $Process->Children->PIDs === []
         && $Process->Children->retirees === [];

      yield new Assertion(description: 'a retiree leaves the slot count but stays owned: recover() flags it retired, terminate() ends it')
         ->expect($Retirement, Op::Identical, [
            'retired' => true,
            'an unknown PID is refused' => false,
            'a retiree is not retired twice' => false,
            'serving' => true,
            'retirees' => true,
            'slots counted' => 1,
            'two PIDs for slot 0' => true,
            'slots counted with a replacement' => 2,
            'reaped as retired' => true,
            'slot 0 still served' => true,
            'terminate() ends serving and retired children' => true,
         ])
         ->assert();
   }),
);
