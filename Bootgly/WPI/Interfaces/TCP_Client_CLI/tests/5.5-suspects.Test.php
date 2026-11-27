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
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Suspects;


return new Test(
   description: 'It should remember failed addresses for a while, per address and port, bounded and per process',
   test: new Assertions(Case: function (): Generator {
      $Expirations = new ReflectionProperty(Suspects::class, 'expirations');
      $now = (int) hrtime(true);
      // ! Start from an empty memory (earlier cases dialed in this process)
      Suspects::clear('192.0.2.1', 81);
      $Expirations->setValue(null, []);

      // @ Mark, by address and port
      Suspects::mark('192.0.2.1', 81, $now);
      Suspects::mark('2001:db8::1', 81, $now);

      yield new Assertion(
         description: 'a marked address goes after the others, in their order; another port is unaffected',
         fallback: 'The split did not demote the suspects!'
      )
         ->expect([
            Suspects::split(['192.0.2.1', '192.0.2.2', '2001:db8::1', '2001:db8::2'], 81, $now),
            Suspects::split(['192.0.2.1'], 82, $now),
         ])
         ->to->be([
            [['192.0.2.2', '2001:db8::2'], ['192.0.2.1', '2001:db8::1']],
            [['192.0.2.1'], []],
         ])
         ->assert();

      // @ The window
      yield new Assertion(
         description: 'a suspect expires after the window',
         fallback: 'The suspect window is not 10 seconds!'
      )
         ->expect([
            Suspects::split(['192.0.2.1'], 81, $now + Suspects::WINDOW - 1)[1],
            Suspects::split(['192.0.2.1'], 81, $now + Suspects::WINDOW)[1],
         ])
         ->to->be([['192.0.2.1'], []])
         ->assert();

      // @ Clear
      Suspects::clear('2001:db8::1', 81);

      yield new Assertion(
         description: 'a cleared address is fresh again',
         fallback: 'clear() kept the suspect!'
      )
         ->expect(Suspects::split(['2001:db8::1'], 81, $now)[1])
         ->to->be([])
         ->assert();

      // @ Capacity: the expired entries go first, then the oldest
      $Expirations->setValue(null, []);
      Suspects::mark('192.0.2.1', 9, $now - Suspects::WINDOW);
      for ($index = 0; $index < Suspects::CAPACITY; $index++) {
         Suspects::mark("198.51.100.{$index}", 10_000 + $index, $now);
      }
      $Full = [
         count($Expirations->getValue()),
         Suspects::split(['192.0.2.1'], 9, $now - Suspects::WINDOW - 1)[1],
         Suspects::split(['198.51.100.0'], 10_000, $now)[1],
      ];

      yield new Assertion(
         description: 'a full memory evicts an expired entry before any live one',
         fallback: 'The memory grew past its capacity or evicted a live entry: ' . json_encode($Full)
      )
         ->expect($Full)
         ->to->be([Suspects::CAPACITY, [], ['198.51.100.0']])
         ->assert();

      Suspects::mark('203.0.113.1', 1, $now);
      $Oldest = [
         count($Expirations->getValue()),
         Suspects::split(['198.51.100.0'], 10_000, $now)[1],
         Suspects::split(['203.0.113.1'], 1, $now)[1],
      ];

      yield new Assertion(
         description: 'with no expired entry, the oldest one goes',
         fallback: 'The oldest live entry was not the one evicted: ' . json_encode($Oldest)
      )
         ->expect($Oldest)
         ->to->be([Suspects::CAPACITY, [], ['203.0.113.1']])
         ->assert();

      // @ Re-marking makes an entry the newest: the next eviction skips it
      Suspects::mark('198.51.100.1', 10_001, $now);
      Suspects::mark('203.0.113.2', 2, $now);

      yield new Assertion(
         description: 'a renewed suspect outlives the entries marked after it the first time',
         fallback: 'A renewed suspect was evicted as if it were old!'
      )
         ->expect([
            Suspects::split(['198.51.100.1'], 10_001, $now)[1],
            Suspects::split(['198.51.100.2'], 10_002, $now)[1],
         ])
         ->to->be([['198.51.100.1'], []])
         ->assert();

      // @ A forked process starts empty
      if (function_exists('pcntl_fork') === false) {
         yield new Assertion(description: 'pcntl is unavailable: the fork reset is not measured')->skip();
      }
      else {
         $PID = pcntl_fork();
         if ($PID === 0) {
            $fresh = Suspects::split(['198.51.100.1'], 10_001, $now)[1] === [];
            Suspects::mark('192.0.2.9', 9, $now);
            exit($fresh ? 0 : 1);
         }
         pcntl_waitpid($PID, $status);

         yield new Assertion(
            description: 'a forked process starts with no suspects, and its marks stay its own',
            fallback: 'The child inherited the parent\'s suspects!'
         )
            ->expect([pcntl_wexitstatus($status), Suspects::split(['198.51.100.1'], 10_001, $now)[1], Suspects::split(['192.0.2.9'], 9, $now)[1]])
            ->to->be([0, ['198.51.100.1'], []])
            ->assert();
      }

      // ! Leave no entry behind for the next cases
      $Expirations->setValue(null, []);
   })
);
