<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


return new Test(
   description: 'TCP-14: stats reset restores the declared error shape (inherited by HTTP)',
   test: new Assertions(Case: function (): Generator {
      $segments = Display::$segments;
      $Errors = Connections::$errors;
      $counters = [Connections::$reads, Connections::$writes, Connections::$read, Connections::$written];
      Display::show(Display::NONE);

      try {
         $Server = new TCP_Server_CLI(Modes::Test);

         // @ The console path: Connections->{'@stats reset'} routes commands/stats.php
         Connections::$errors = ['connection' => 5, 'read' => 5, 'write' => 5];
         Connections::$reads = 9;
         $Server->Connections->{'@stats reset'};

         yield new Assertion(description: 'stats reset zeroes every declared error kind and adds none')
            ->expect([Connections::$errors, Connections::$reads], Op::Identical, [
               ['connection' => 0, 'read' => 0, 'write' => 0],
               0,
            ])
            ->assert();
      }
      finally {
         Connections::$errors = $Errors;
         [Connections::$reads, Connections::$writes, Connections::$read, Connections::$written] = $counters;
         Display::show($segments);
      }
   }),
);
