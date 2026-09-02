<?php

namespace Bootgly\commands;


use function array_keys;
use function assert;
use function preg_match_all;
use function rewind;
use function str_contains;
use function stream_get_contents;
use ReflectionMethod;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: '`projects list`: one table row per registered project, with interfaces and description; clipping is a terminal concern',
   test: function () {
      // ! What the registry resolves to a signature — the rows the table owes
      $CLI = Projects::discover('CLI');
      $WPI = Projects::discover('WPI');
      $expected = 0;
      $sample = null;
      foreach (array_keys(Projects::read()) as $folder) {
         $meta = $CLI[$folder] ?? $WPI[$folder] ?? null;
         if ($meta === null) {
            continue;
         }
         $expected++;
         $sample ??= [$folder, $meta['description']];
      }

      yield assert(
         assertion: $expected > 0 && $sample !== null,
         description: 'probe precondition: the registry resolves at least one project signature'
      );

      // @ Render into memory
      $Host = new Output('php://memory');
      $Terminal = CLI->Terminal;
      $Restore = $Terminal->Output;
      $Terminal->Output = $Host;
      try {
         $result = new ProjectsCommand()->run(['list']);
      }
      finally {
         $Terminal->Output = $Restore;
      }
      rewind($Host->stream);
      $output = (string) stream_get_contents($Host->stream);
      $rows = preg_match_all('/^║ \d+ /m', $output);

      yield assert(
         assertion: $result === true
            && str_contains($output, '│ Project ') && str_contains($output, '│ Interface ')
            && str_contains($output, '│ Description ') && $rows === $expected,
         description: "one row per project ({$expected}) under the # / Project / Interface / Description header — got {$rows} rows"
      );

      // # A row carries the folder, its registry interfaces and the full description (no terminal → no clip)
      [$folder, $description] = $sample;
      $interfaces = Projects::read()[$folder]['interfaces'] ?? [];

      yield assert(
         assertion: str_contains($output, "│ {$folder} ")
            && ($interfaces === [] || str_contains($output, "│ {$interfaces[0]}"))
            && ($description === '' || str_contains($output, $description)),
         description: 'the row shows the folder, the interfaces and the unclipped description'
      );

      // # The clip marks the cut and leaves short cells alone
      $Clip = new ReflectionMethod(ProjectsCommand::class, 'clip');
      $Command = new ProjectsCommand;

      yield assert(
         assertion: $Clip->invoke($Command, 'abcdef', 4) === 'abc…'
            && $Clip->invoke($Command, 'abcd', 4) === 'abcd'
            && $Clip->invoke($Command, 'ação longa', 6) === 'ação …',
         description: 'clip() cuts to the width with an ellipsis, multibyte-aware, and never touches a cell that fits'
      );
   }
);
