<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Shares;


/**
 * H-HSC-3 — one worker memory budget.
 *
 * Every retention owner charges the allocator footprint of what it holds to
 * one ledger (`TCP_Server_CLI::$maxWorkerPendingBytes`), inside its share:
 * Inbound at most half, Resident at most a quarter, so Transport always keeps
 * a quarter. `weigh()` never prices a page-sized string a class below what it
 * spends, `reset()` opens a new generation, and `start()` fits the budget
 * under `memory_limit`.
 */
return new Test(
   description: 'H-HSC-3: worker ledger shares, footprint weigh, epoch and memory_limit fit',
   test: new Assertions(Case: function (): Generator {
      // ! Run on a private ledger: save the worker statics, start from zero,
      //   restore them exactly afterwards.
      $Total = new ReflectionProperty(Buffers::class, 'total');
      $Held = new ReflectionProperty(Buffers::class, 'held');
      $Epoch = new ReflectionProperty(Buffers::class, 'epoch');
      $saved = [
         $Total->getValue(),
         $Held->getValue(),
         $Epoch->getValue(),
         TCP_Server_CLI::$pendingBytes,
         TCP_Server_CLI::$maxWorkerPendingBytes,
      ];
      $Total->setValue(null, 0);
      $Held->setValue(null, [0, 0, 0]);
      // ! A fresh generation: a token reserved before this case holds nothing here
      $Epoch->setValue(null, $saved[2] + 1);
      TCP_Server_CLI::$pendingBytes = 0;

      /** @var array<int,Buffers> $Tokens */
      $Tokens = [];

      try {
         // # (a) weigh(): a string past a third of a chunk is charged the whole
         //   chunk, whichever allocation path built it, and a padded string
         //   past 3,040 bytes takes a page.
         $band = [
            Buffers::weigh(696_288),
            Buffers::weigh(696_289),
            Buffers::weigh(3_040),
            Buffers::weigh(3_041),
            Buffers::weigh(PHP_INT_MAX - 4_128),
            Buffers::weigh(PHP_INT_MAX),
         ];
         $pins = [
            Buffers::weigh(0),
            Buffers::weigh(100),
            Buffers::weigh(1_000),
            Buffers::weigh(102_414),
            Buffers::weigh(1_045_000),
            Buffers::weigh(1_048_576),
            Buffers::weigh(8_388_608),
         ];

         yield new Assertion(
            description: '(a) weigh() steps to a whole chunk at 696,289 B, to a page at 3,041 B, and pins every other class'
         )
            ->expect([$band, $pins], Op::Identical, [
               [1_048_576, 2_097_152, 3_072, 4_113, PHP_INT_MAX - 4_095, PHP_INT_MAX],
               [0, 128, 1_280, 116_509, 2_097_152, 2_097_152, 8_392_704],
            ])
            ->assert();

         // @ What 40 strings grown side by side (64 KiB at a time) really
         //   cost: growth moves them out of each other's way, so they pack
         //   one fewer per chunk than their pages allow
         $code = 'foreach ([600000, 1000000] as $target) { gc_mem_caches(); $base = memory_get_usage(true);'
            . ' $S = array_fill(0, 40, "");'
            . ' for ($grown = 0; $grown < $target; $grown += 65536) { $step = min(65536, $target - $grown);'
            . ' for ($i = 0; $i < 40; $i++) { $S[$i] .= str_repeat("x", $step); } }'
            . ' gc_mem_caches(); echo memory_get_usage(true) - $base, " "; unset($S); }';
         [$grown600, $grown1000] = array_map('intval', explode(' ', trim((string) shell_exec(
            escapeshellarg(PHP_BINARY) . ' -n -d memory_limit=-1 -r ' . escapeshellarg($code)
         ))) + [0, 0]);

         yield new Assertion(
            description: '(a) strings grown side by side spend more than ideal packing and no more than their weights'
         )
            ->expect(
               [
                  $grown600 > 40 * 699_051 && $grown600 <= 40 * Buffers::weigh(600_000),
                  $grown1000 > 40 * 1_048_576 && $grown1000 <= 40 * Buffers::weigh(1_000_000),
               ],
               Op::Identical,
               [true, true]
            )
            ->assert();

         // @ What 16 repeated strings of 1,044,449 B really cost the allocator
         $code = '$base = memory_get_usage(true); $S = [];'
            . ' for ($i = 0; $i < 16; $i++) { $S[] = str_repeat("x", 1044449); }'
            . ' echo memory_get_usage(true) - $base;';
         $spent = (int) shell_exec(
            escapeshellarg(PHP_BINARY) . ' -n -d memory_limit=-1 -r ' . escapeshellarg($code)
         );

         yield new Assertion(
            description: '(a) 16 repeated 1,044,449 B strings spend more than 16 MiB and no more than 16 weights'
         )
            ->expect(
               $spent > 16 * 1_048_576 && $spent <= 16 * Buffers::weigh(1_044_449),
               Op::Identical,
               true
            )
            ->assert();

         // # (b) Inbound holds stop at half the budget; Transport keeps the rest
         TCP_Server_CLI::$maxWorkerPendingBytes = 8_388_608;
         $InboundA = $Tokens[] = new Buffers(Shares::Inbound);
         $InboundB = $Tokens[] = new Buffers(Shares::Inbound);
         $Transport = $Tokens[] = new Buffers;
         $inbound = [
            $InboundA->reserve(4_194_304),
            $InboundB->reserve(1),
            $Transport->reserve(4_194_304),
            $Transport->reserve(4_194_305),
            TCP_Server_CLI::$pendingBytes,
         ];

         yield new Assertion(
            description: '(b) Inbound stops at L/2 while a Transport token still takes the other half'
         )
            ->expect($inbound, Op::Identical, [true, false, true, false, 8_388_608])
            ->assert();

         foreach ($Tokens as $Token) {
            $Token->release();
         }

         // # (c) Resident holds stop at a quarter of the budget
         $ResidentA = $Tokens[] = new Buffers(Shares::Resident);
         $ResidentB = $Tokens[] = new Buffers(Shares::Resident);
         $Other = $Tokens[] = new Buffers;
         $resident = [
            $ResidentA->reserve(2_097_152),
            $ResidentB->reserve(1),
            $Other->reserve(6_291_456),
            TCP_Server_CLI::$pendingBytes,
         ];

         yield new Assertion(
            description: '(c) Resident stops at L/4 while Transport takes the remaining three quarters'
         )
            ->expect($resident, Op::Identical, [true, false, true, 8_388_608])
            ->assert();

         foreach ($Tokens as $Token) {
            $Token->release();
         }

         // # (d) With Inbound and Resident full, Transport keeps exactly L/4
         $FullInbound = $Tokens[] = new Buffers(Shares::Inbound);
         $FullResident = $Tokens[] = new Buffers(Shares::Resident);
         $Output = $Tokens[] = new Buffers(Shares::Transport);
         $floor = [
            $FullInbound->reserve(4_194_304),
            $FullResident->reserve(2_097_152),
            $Output->reserve(2_097_153),
            $Output->reserve(2_097_152),
            $Output->Share,
         ];

         yield new Assertion(
            description: '(d) Transport keeps exactly L/4 when both other shares are full'
         )
            ->expect($floor, Op::Identical, [true, true, false, true, Shares::Transport])
            ->assert();

         // # available: the reservation plus the growth the budget and the share admit
         foreach ($Tokens as $Token) {
            $Token->release();
         }
         $Holder = $Tokens[] = new Buffers(Shares::Inbound);
         $Filler = $Tokens[] = new Buffers;
         $Cache = $Tokens[] = new Buffers(Shares::Resident);
         $Holder->reserve(1_048_576);
         $Filler->reserve(6_291_456);
         $available = [$Holder->available, $Cache->available, $Filler->available];
         $Filler->release();
         $available[] = $Holder->available;
         $available[] = $Cache->available;

         yield new Assertion(
            description: '(d) available is the reservation plus what the budget and the share still admit'
         )
            ->expect($available, Op::Identical, [2_097_152, 1_048_576, 7_340_032, 4_194_304, 2_097_152])
            ->assert();

         foreach ($Tokens as $Token) {
            $Token->release();
         }
         $FullInbound->reserve(4_194_304);

         // # Shrinking always fits, even below a lowered budget
         TCP_Server_CLI::$maxWorkerPendingBytes = 2_097_152;
         $shrink = [
            $FullInbound->reserve(3_145_728),
            $FullInbound->reserve(3_145_729),
         ];

         yield new Assertion(
            description: '(d) a share above a lowered budget may shrink but not grow'
         )
            ->expect($shrink, Op::Identical, [true, false])
            ->assert();

         foreach ($Tokens as $Token) {
            $Token->release();
         }
         TCP_Server_CLI::$maxWorkerPendingBytes = 8_388_608;

         // # (e) reset() opens a new generation: an older token holds nothing
         $Survivor = $Tokens[] = new Buffers(Shares::Inbound);
         $Survivor->reserve(1_000);
         Buffers::reset();
         $afterReset = TCP_Server_CLI::$pendingBytes;
         $recharged = $Survivor->reserve(1_000);
         $afterRecharge = TCP_Server_CLI::$pendingBytes;
         $Survivor->release();
         $afterRelease = TCP_Server_CLI::$pendingBytes;
         $Survivor->release();
         $afterDuplicate = TCP_Server_CLI::$pendingBytes;

         yield new Assertion(
            description: '(e) a token from before reset() charges in full again and releases exactly once'
         )
            ->expect(
               [$afterReset, $recharged, $afterRecharge, $afterRelease, $afterDuplicate],
               Op::Identical,
               [0, true, 1_000, 0, 0]
            )
            ->assert();

         // # (f) fit(): at most half of memory_limit; no limit keeps the budget
         $fits = [
            Buffers::fit(67_108_864, 134_217_728),
            Buffers::fit(67_108_864, 67_108_864),
            Buffers::fit(67_108_864, -1),
            Buffers::fit(1_073_741_824, 1_073_741_824),
            Buffers::fit(0, 134_217_728),
            Buffers::fit(-5, 134_217_728),
            Buffers::fit(-5, -1),
         ];

         yield new Assertion(
            description: '(f) fit() lowers the budget to half of memory_limit and never below zero'
         )
            ->expect($fits, Op::Identical, [
               67_108_864, 33_554_432, 67_108_864, 536_870_912, 0, 0, 0,
            ])
            ->assert();

         // # (g) start() fits the budget after loading() and before booting()
         $source = (string) file_get_contents(
            (string) (new ReflectionClass(TCP_Server_CLI::class))->getFileName()
         );
         $lexemes = token_get_all($source);
         $order = [];
         $inside = false;
         $depth = 0;
         $count = count($lexemes);
         for ($i = 0; $i < $count; $i++) {
            $lexeme = $lexemes[$i];
            // ? A method of the class body: its name follows the keyword
            if (is_array($lexeme) && $lexeme[0] === T_FUNCTION && $depth === 1) {
               for ($j = $i + 1; $j < $count; $j++) {
                  if (is_array($lexemes[$j]) && $lexemes[$j][0] === T_STRING) {
                     $inside = $lexemes[$j][1] === 'start';
                     break;
                  }
               }
            }
            if (
               $lexeme === '{'
               || (is_array($lexeme) && in_array($lexeme[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            ) {
               $depth++;
            }
            else if ($lexeme === '}') {
               $depth--;
               if ($inside && $depth === 1) {
                  break;
               }
            }
            if ($inside === false || is_array($lexeme) === false || $lexeme[0] !== T_STRING) {
               continue;
            }
            if (($lexemes[$i + 1] ?? null) !== '(') {
               continue;
            }

            $previous = $lexemes[$i - 1] ?? null;
            if ($lexeme[1] === 'fit' && is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
               $name = $lexemes[$i - 2] ?? null;
               if (is_array($name) && str_ends_with($name[1], 'Buffers')) {
                  $order[] = 'fit';
               }
            }
            else if (
               in_array($lexeme[1], ['loading', 'booting'], true)
               && is_array($previous) && $previous[0] === T_OBJECT_OPERATOR
            ) {
               $order[] = $lexeme[1];
            }
         }

         yield new Assertion(
            description: '(g) start() calls Buffers::fit() once, after loading() and before booting()'
         )
            ->expect($order, Op::Identical, ['loading', 'fit', 'booting'])
            ->assert();
      }
      finally {
         foreach ($Tokens as $Token) {
            $Token->release();
         }
         [$total, $held, $epoch, $pending, $budget] = $saved;
         $Total->setValue(null, $total);
         $Held->setValue(null, $held);
         $Epoch->setValue(null, $epoch);
         TCP_Server_CLI::$pendingBytes = $pending;
         TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
      }
   }),
);
