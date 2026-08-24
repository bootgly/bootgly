<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;


return new Test(
   description: 'It should keep an embedded client out of the host process infrastructure',
   test: new Assertions(Case: function (): Generator {
      // ! Save the host process state
      $debug = Vars::$debug;
      $print = Vars::$print;
      $exit = Vars::$exit;
      $pids = BOOTGLY_STORAGE_DIR . 'pids/';
      $entries = is_dir($pids) ? (array) scandir($pids) : [];

      try {
         // ! Sentinels: the opposite of what the constructor would write
         Vars::$debug = false;
         Vars::$print = false;
         Vars::$exit = true;

         // @ Embedded construction
         new TCP_Client_CLI(TCP_Client_CLI::MODE_EMBEDDED);

         yield new Assertion(
            description: 'MODE_EMBEDDED leaves Vars::$debug untouched',
            fallback: 'An embedded client overwrote the host Vars::$debug!'
         )
            ->expect(Vars::$debug)
            ->to->be(false)
            ->assert();

         yield new Assertion(
            description: 'MODE_EMBEDDED leaves Vars::$print untouched',
            fallback: 'An embedded client overwrote the host Vars::$print!'
         )
            ->expect(Vars::$print)
            ->to->be(false)
            ->assert();

         yield new Assertion(
            description: 'MODE_EMBEDDED leaves Vars::$exit untouched',
            fallback: 'An embedded client overwrote the host Vars::$exit!'
         )
            ->expect(Vars::$exit)
            ->to->be(true)
            ->assert();

         yield new Assertion(
            description: 'MODE_EMBEDDED creates no process state files',
            fallback: 'An embedded client wrote into storage/pids/!'
         )
            ->expect(is_dir($pids) ? (array) scandir($pids) : [])
            ->to->be($entries)
            ->assert();

         // @ Control: MODE_TEST keeps overwriting the debugging Vars
         new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);

         yield new Assertion(
            description: 'MODE_TEST still configures the debugging Vars',
            fallback: 'The MODE_TEST control no longer overwrites Vars - the gate is too wide!'
         )
            ->expect(Vars::$debug === true && Vars::$print === true && Vars::$exit === false)
            ->to->be(true)
            ->assert();
      }
      finally {
         // ! Restore the host process state
         Vars::$debug = $debug;
         Vars::$print = $print;
         Vars::$exit = $exit;
      }
   })
);
