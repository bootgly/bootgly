<?php

namespace Bootgly\commands;


use function array_keys;
use function assert;
use ReflectionClassConstant;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: '`projects show`: a narrow terminal gives up the secondary columns first; the core ones never go',
   test: function () {
      $Fit = new ReflectionMethod(ProjectsCommand::class, 'fit');
      $Command = new ProjectsCommand;
      /** @var array<string,string> $columns */
      $columns = new ReflectionClassConstant(ProjectsCommand::class, 'COLUMNS')->getValue();

      // ! One row as `show` shapes it — every column at a known width
      $cells = [[
         'project'  => 'Demo/HTTP_Server_CLI', // 20
         'instance' => '8080',                 // 8 (header)
         'interface' => 'WPI',                 // 9 (header)
         'status'   => 'running',              // 7
         'master'   => '41230',                // 6 (header)
         'workers'  => '4',                    // 7 (header)
         'uptime'   => '2h 15m',               // 6 (header)
         'address'  => '0.0.0.0:8080',         // 12
         'tap'      => 'yes'                   // 3
      ]];
      // ! Every column: 78 of content + 9 × 3 + 1 of borders and padding = 106
      $full = 106;

      // # Wide enough: every column, in display order
      yield assert(
         assertion: array_keys($Fit->invoke($Command, $columns, $cells, $full)) === array_keys($columns)
            && array_keys($Fit->invoke($Command, $columns, $cells, 200)) === array_keys($columns),
         description: 'a terminal at least as wide as the table keeps every column'
      );

      // # One column short: the most expendable one goes — Tap (3 + 3 = 6 columns narrower)
      yield assert(
         assertion: array_keys($Fit->invoke($Command, $columns, $cells, $full - 1))
            === ['project', 'instance', 'interface', 'status', 'master', 'workers', 'uptime', 'address'],
         description: 'one column short drops Tap first'
      );

      // # Narrow: Tap, Workers, Master and Interface go before Address and Uptime
      yield assert(
         assertion: array_keys($Fit->invoke($Command, $columns, $cells, 70))
            === ['project', 'instance', 'status', 'uptime', 'address'],
         description: 'a 70-column terminal keeps Project, Instance, Status, Uptime and Address'
      );

      // # Tiny: the core columns stay even when they do not fit
      yield assert(
         assertion: array_keys($Fit->invoke($Command, $columns, $cells, 10))
            === ['project', 'instance', 'status'],
         description: 'Project, Instance and Status are never given up'
      );
   }
);
