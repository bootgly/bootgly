<?php
namespace Bootgly\CLI;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Tested methods: route (exit-status contract)',
   test: function () {
      $Commands = new Commands;

      $TestCommand = new class extends Command
      {
         public string $name = 'testing';
         public string $description = 'Testing command route...';


         public function run (array $arguments = [], array $options = []) : bool
         {
            return true;
         }
      };
      // ! A silent help double: route() falls back to it on unknown or
      //   empty commands — the real help renders framework UI output
      $HelpCommand = new class extends Command
      {
         public string $name = 'help';
         public string $description = 'Help fallback...';


         public function run (array $arguments = [], array $options = []) : bool
         {
            return true;
         }
      };

      // ! Registered without an explicit Script, both commands key under
      //   the registry itself — the registry can double as `From` (the
      //   runtime pairing in `CLI.php`), while the default `From: null`
      //   searches every registered namespace
      $Commands->register($TestCommand);
      $Commands->register($HelpCommand);

      // @ A registered command routes successfully with the default scope
      //   (`From: null` = all namespaces)
      yield assert(
         assertion: $Commands->route(['bootgly', 'testing']) === true,
         description: 'A known command routes with success on the default scope'
      );

      // @ ... and with an explicit scope object
      yield assert(
         assertion: $Commands->route(['bootgly', 'testing'], $Commands) === true,
         description: 'A known command routes with success on an explicit scope'
      );

      // @ An unknown command renders help but the route itself FAILS —
      //   the binary translates this into exit code 1 (tooling that
      //   shells out relies on the status, not on parsing the output)
      yield assert(
         assertion: $Commands->route(['bootgly', 'unknown-command']) === false,
         description: 'An unknown command routes with failure after the help fallback'
      );

      // @ No command at all is an intentional help route — success
      yield assert(
         assertion: $Commands->route(['bootgly']) === true,
         description: 'An empty command shows help with success'
      );

      // @ Explicit help is also success
      yield assert(
         assertion: $Commands->route(['bootgly', 'help']) === true,
         description: 'The explicit help command routes with success'
      );
   }
);
