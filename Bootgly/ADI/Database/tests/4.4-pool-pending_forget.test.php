<?php

use function fopen;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'Database: pool assignment forgets the pending entry (no double assign)',
   test: function () {
      $Database = new SQL([
         'driver' => 'mysql',
         'secure' => ['mode' => 'disable'],
         'pool' => ['min' => 0, 'max' => 0],
      ]);

      // ! Zero capacity — the operation must park in the pending queue
      $Operation = $Database->query('SELECT 1');
      $Database->Pool->advance($Operation);

      yield assert(
         assertion: $Database->Pool->pending !== [] && $Operation->finished === false,
         description: 'An operation without capacity parks in the pending queue'
      );

      // @ Capacity appears: attach one idle ready connection and raise the cap
      $Database->Pool->max = 1;
      $Connection = new Connection($Database->Config);
      $Connection->attach(fopen('php://memory', 'r+'));
      $Database->Pool->attach($Connection);

      // @ Pool::advance() assigns the pending operation directly — BEFORE any
      //   release() promotes it. The assignment must forget the pending entry,
      //   otherwise a later promote() re-assigns and re-sends the wire command.
      $Database->Pool->advance($Operation);

      yield assert(
         assertion: $Database->Pool->pending === []
            && $Operation->Connection === $Connection
            && $Operation->write !== '',
         description: 'A successful assignment removes the operation from the pending queue'
      );
   }
);
