<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use const DIRECTORY_SEPARATOR;
use const SIGKILL;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function assert;
use function count;
use function fclose;
use function fread;
use function function_exists;
use function fwrite;
use function hrtime;
use function is_file;
use function mkdir;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_kill;
use function source;
use function stream_get_contents;
use function stream_socket_pair;
use function wipe;
use RuntimeException;

use Bootgly\ABI\Resources\Storage\Drivers\Local;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';


/**
 * Workers that write into the same new directory at the same instant all race `mkdir()`;
 * the losers get EEXIST for a directory that now exists. Jailing the directory before it
 * is created must not turn that lost race into a failure: every write lands. A gate plus a
 * shared deadline releases the workers together; each one ends with SIGKILL so no shutdown
 * handler of the runner it was forked from runs twice. The race only exists where the
 * workers run in parallel: on a single free CPU this spec passes without pinning the
 * EEXIST re-check.
 */
return new Test(
   description: 'Storage(Local): workers racing to create the same new directory all succeed',
   skip: DIRECTORY_SEPARATOR === '\\'
      || function_exists('pcntl_fork') === false
      || function_exists('posix_kill') === false,
   test: function () {
      $base = Temporaries::reserve('storage-concurrent-mkdir');

      try {
         $root = "{$base}/root";
         mkdir($root, 0775, true);
         $workers = 8;
         $rounds = 5;
         $failures = [];
         $missing = 0;

         // @@
         for ($round = 0; $round < $rounds; $round++) {
            $PIDs = [];
            $Results = [];
            $Gates = [];
            // ! The workers spin until one shared instant after the gate opens
            $deadline = hrtime(true) + 250_000_000;

            try {
               for ($worker = 0; $worker < $workers; $worker++) {
                  $Result = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                  $Gate = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                  // ? Setup failures abort the round before anything is forked for them
                  if ($Result === false || $Gate === false) {
                     throw new RuntimeException('stream_socket_pair() failed');
                  }
                  $PID = pcntl_fork();
                  if ($PID === -1) {
                     throw new RuntimeException('pcntl_fork() failed');
                  }
                  if ($PID === 0) {
                     try {
                        fclose($Result[0]);
                        fclose($Gate[0]);
                        $Disk = new Local($root);
                        // ? Only an explicit go writes: a parent that died closes the gate (EOF)
                        if (fread($Gate[1], 1) === 'g') {
                           while (hrtime(true) < $deadline) {}
                           $written = $Disk->write("day{$round}/deep/w{$worker}.txt", source('x'));
                           fwrite($Result[1], $written === true ? 'ok' : $Disk->error);
                        }
                        fclose($Result[1]);
                     }
                     finally {
                        // ! Hard exit — no shutdown handlers, no inherited output flush
                        posix_kill(posix_getpid(), SIGKILL);
                     }
                  }
                  fclose($Result[1]);
                  fclose($Gate[1]);
                  $PIDs[] = $PID;
                  $Results[] = $Result[0];
                  $Gates[] = $Gate[0];
               }
               foreach ($Gates as $Gate) {
                  fwrite($Gate, 'g');
               }
               foreach ($Results as $index => $Result) {
                  $reply = stream_get_contents($Result);
                  if ($reply !== 'ok') {
                     $failures[] = "day{$round}/w{$index}: {$reply}";
                  }
                  if (is_file("{$root}/day{$round}/deep/w{$index}.txt") === false) {
                     $missing++;
                  }
               }
            }
            finally {
               // ! Every worker of the round is released (EOF = no write) and reaped before
               //   the round ends — and before the outer cleanup wipes the tree
               foreach ($Gates as $Gate) {
                  fclose($Gate);
               }
               foreach ($Results as $Result) {
                  fclose($Result);
               }
               foreach ($PIDs as $PID) {
                  pcntl_waitpid($PID, $status);
               }
            }
         }

         yield assert(
            assertion: $failures === [] && $missing === 0,
            description: 'every one of ' . ($workers * $rounds) . ' concurrent writes into new directories succeeded'
               . ($failures === [] ? '' : ' — failed: ' . count($failures) . ", first: {$failures[0]}")
         );
      }
      finally {
         // ! Cleanup
         wipe($base);
      }
   }
);
