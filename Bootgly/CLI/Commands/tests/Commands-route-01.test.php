<?php
namespace Bootgly\CLI;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Tested methods: route, find (exit-status and exact-scope contracts)',
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

      // ! Same-class scopes must resolve their own instance-bound command.
      //   Long-lived test/worker processes construct several server instances
      //   of one class; selecting the first class match executes stale context.
      $SourceA = new class {};
      $SourceB = clone $SourceA;
      $SourceC = clone $SourceA;
      $ScopedA = new class extends Command
      {
         public string $name = 'scoped';
         public string $description = 'First scoped command...';


         public function run (array $arguments = [], array $options = []) : bool
         {
            return true;
         }
      };
      $ScopedB = new class extends Command
      {
         public string $name = 'scoped';
         public string $description = 'Second scoped command...';


         public function run (array $arguments = [], array $options = []) : bool
         {
            return true;
         }
      };
      $Commands->register($ScopedA, $SourceA);
      $Commands->register($ScopedB, $SourceB);

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

      yield assert(
         assertion: $Commands->find('scoped', $SourceA) === $ScopedA,
         description: 'A command resolves the exact first same-class scope instance'
      );

      yield assert(
         assertion: $Commands->find('scoped', $SourceB) === $ScopedB,
         description: 'A command resolves the exact second same-class scope instance'
      );

      yield assert(
         assertion: $Commands->find('scoped', $SourceC) === $ScopedA,
         description: 'An unknown same-class scope retains the historical first-match fallback'
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
