<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Handlers\File;
use Bootgly\ACI\Logs\Handlers\Memory;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * The Memory handler holds records for a later replay — per process.
 *
 * A root launch keeps its pre-demote records here and replays them through
 * the file sink once privileges are dropped; a forked worker inherits the
 * array but must never replay what its parent held.
 */
return new Test(
   description: 'Memory handler holds records in order, replays them once, and a fork starts empty',
   test: function () {
      $dir = Temporaries::reserve('logs-memory');
      $file = "$dir/replayed.log";
      $marker = "$dir/child.txt";

      $Previous = Memory::hold();

      try {
         $Memory = new Memory;
         $Memory->handle(new Record(Levels::Notice, 'Web', 'first'));
         $Memory->handle(new Record(Levels::Info, 'Web', 'second'));

         yield assert(
            assertion: count($Memory->Records) === 2
               && $Memory->Records[0]->message === 'first'
               && $Memory->Records[1]->message === 'second',
            description: 'records are held in arrival order'
         );

         // @@ A fork inherits the array, not the hold
         $pid = pcntl_fork();
         if ($pid === 0) {
            $Memory->handle(new Record(Levels::Info, 'Web', 'child-only'));
            $held = array_map(static fn (Record $Record): string => $Record->message, $Memory->Records);
            file_put_contents($marker, implode(',', $held));
            exit(0);
         }
         $status = 0;
         pcntl_waitpid($pid, $status);

         yield assert(
            assertion: $pid > 0
               && (string) file_get_contents($marker) === 'child-only'
               && count($Memory->Records) === 2,
            description: 'a forked child holds only what it logged itself; the parent keeps its two records'
         );

         // @@ A child that adopts the inheritance keeps it — the detached master's case
         $pid = pcntl_fork();
         if ($pid === 0) {
            $Memory->adopt();
            $Memory->handle(new Record(Levels::Info, 'Web', 'adopted-plus-own'));
            $held = array_map(static fn (Record $Record): string => $Record->message, $Memory->Records);
            file_put_contents($marker, implode(',', $held));
            exit(0);
         }
         pcntl_waitpid($pid, $status);

         yield assert(
            assertion: $pid > 0 && (string) file_get_contents($marker) === 'first,second,adopted-plus-own',
            description: 'a forked child that adopt()s keeps what it inherited and appends its own'
         );

         // @@ Bounded: past LIMIT the oldest record gives way
         $Bounded = new Memory;
         for ($index = 0; $index <= Memory::LIMIT; $index++) {
            $Bounded->handle(new Record(Levels::Info, 'Web', "record-$index"));
         }

         yield assert(
            assertion: count($Bounded->Records) === Memory::LIMIT
               && $Bounded->Records[0]->message === 'record-1'
               && $Bounded->Records[Memory::LIMIT - 1]->message === 'record-' . Memory::LIMIT,
            description: 'the hold keeps at most LIMIT records, dropping the oldest'
         );

         // @@ Replay through a file sink, then nothing is left to replay
         $Sinks = new Handlers;
         $Sinks->push(new File($file));
         $replayed = $Memory->replay(...$Sinks->Handlers);
         $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));

         yield assert(
            assertion: $replayed === 2
               && count($lines) === 2
               && str_contains($lines[0], '"first"')
               && str_contains($lines[1], '"second"')
               && $Memory->Records === []
               && $Memory->replay(...$Sinks->Handlers) === 0,
            description: 'replay() hands every held record to the sink in order and forgets them'
         );

         // @@ The hold a privileged launch installs is known by IDENTITY
         $Hold = new Memory;
         $Other = new Memory;
         $Withheld = [new File($file), $Other];
         $registered = Memory::hold($Hold, $Withheld);
         $read = Memory::hold();
         $kept = Memory::hold($Hold);

         yield assert(
            assertion: $registered === $Hold && $read === $Hold && $kept === $Hold
               && $Hold->Withheld === $Withheld
               && $Other->Withheld === []
               && $read !== $Other,
            description: 'hold() registers one Memory handler per process with what it stands in for, reads it back by identity, '
               . 'and re-registering it with nothing named keeps the withheld handlers'
         );

         Memory::release();
         $released = Memory::hold();

         yield assert(
            assertion: $released === null,
            description: 'release() forgets the hold — hold() reads null until a launch registers another'
         );
      }
      finally {
         Memory::release();
         if ($Previous !== null) {
            Memory::hold($Previous, $Previous->Withheld);
         }
         foreach ([$file, $marker] as $entry) {
            if (is_file($entry) === true) {
               unlink($entry);
            }
         }
         rmdir($dir);
      }
   }
);
