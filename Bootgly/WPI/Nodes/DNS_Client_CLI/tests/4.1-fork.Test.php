<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Fork;


use const SIGKILL;
use const WNOHANG;
use function array_column;
use function assert;
use function count;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getmypid;
use function json_decode;
use function json_encode;
use function microtime;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_kill;
use function usleep;

use Bootgly\ABI\Data\DNS;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer\Outcomes;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;


return new Test(
   description: 'DNS_Client_CLI after fork(): the child starts with its own state, never the parent\'s',
   test: function () {
      // ? No fork here
      if (function_exists('pcntl_fork') === false) {
         yield assert(assertion: true, description: 'pcntl is not loaded: skipped');
         return;
      }

      $Harness = Harness::start('dns-fork');
      $NS = $Harness->Nameserver;
      $Configs = $Harness->configure();

      try {
         // ! The parent caches an answer
         $NS->script('forked.test', DNS::TYPE_A, ['answers' => [['forked.test', DNS::TYPE_A, 300, '192.0.2.41']]]);
         $Parent = new DNS_Client_CLI()->configure($Configs)->query('forked.test', DNS::TYPE_A);
         $Cached = new DNS_Client_CLI()->configure($Configs)->query('forked.test', DNS::TYPE_A);
         $before = count($NS->log());

         // @ The child asks the same name with the same Configs
         $report = $Harness->write('child.json', '');
         $PID = pcntl_fork();
         if ($PID === 0) {
            $Answer = new DNS_Client_CLI()->configure($Configs)->query('forked.test', DNS::TYPE_A);
            file_put_contents($report, (string) json_encode([
               'outcome' => $Answer->Outcome->name, 'TTL' => $Answer->TTL,
            ]));
            posix_kill((int) getmypid(), SIGKILL);
         }

         // ! Reap by PID, bounded
         $status = 0;
         $deadline = microtime(true) + 5.0;
         while (pcntl_waitpid($PID, $status, WNOHANG) === 0 && microtime(true) < $deadline) {
            usleep(10_000);
         }
         if (microtime(true) >= $deadline) {
            posix_kill($PID, SIGKILL);
            pcntl_waitpid($PID, $status);
         }

         $Child = json_decode((string) file_get_contents($report), true);
         $Log = $NS->log();
         $ids = array_column($Log, 'id');
         yield assert(
            assertion: $Parent->Outcome === Outcomes::Answered && $Cached->TTL <= 300 && $before === 1,
            description: 'the parent answers the second lookup from its cache: ' . json_encode([$before])
         );
         yield assert(
            assertion: ($Child['outcome'] ?? null) === 'Answered' && count($Log) === 2 && $ids[0] !== $ids[1],
            description: 'the child sends its own query, with its own id, instead of reading the parent\'s cache: ' . json_encode([$Child, $ids])
         );
      }
      finally {
         $Harness->stop();
      }
   }
);
