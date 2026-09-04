<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'Shipped server projects register the launch banner on ServerAdvertised, never on ServerStarted',
   test: function () {
      // ! Registrations are read from PHP tokens, not from raw text. A brace
      //   counter closes on the first `}` inside a string, a comment or a
      //   heredoc; and matching on `Events::` would lose any project that
      //   aliases the import. An enum CASE name survives every alias, so the
      //   case token is the anchor and the `on(` call bounds the span.
      $registrations = static function (string $source, string $case): array {
         $Tokens = token_get_all($source);
         $total = count($Tokens);
         $found = [];

         // @@
         for ($i = 1; $i < $total; $i++) {
            $Token = $Tokens[$i];

            // ? Not a `::<Case>` reference
            if (is_array($Token) === false || $Token[0] !== T_STRING || $Token[1] !== $case) {
               continue;
            }
            $Previous = $Tokens[$i - 1];
            if (is_array($Previous) === false || $Previous[0] !== T_DOUBLE_COLON) {
               continue;
            }

            // ! The case is the first argument of `on(`, so one parenthesis is
            //   already open; the registration ends when it closes.
            $parentheses = 1;
            $registration = '';

            // @ Collect the whole registration — closure body or arrow function
            for ($j = $i; $j < $total; $j++) {
               $text = is_array($Tokens[$j]) ? $Tokens[$j][1] : $Tokens[$j];
               $registration .= $text;

               if ($text === '(') {
                  $parentheses++;
               }
               else if ($text === ')') {
                  $parentheses--;

                  if ($parentheses === 0) {
                     break;
                  }
               }
            }

            $found[] = $registration;
         }

         // :
         return $found;
      };

      // ! Shipped examples live in the author tree
      $directory = Projects::AUTHOR_DIR;

      // ? No author tree — nothing shipped to pin
      if (is_dir($directory) === false) {
         yield assert(
            assertion: true,
            description: 'Skipped: the author projects directory is absent'
         );

         return;
      }

      // @ Every shipped project that registers a server launch hook
      $sources = [];
      foreach (glob("{$directory}*/*/*.Project.php") ?: [] as $file) {
         $source = file_get_contents($file);

         if ($source === false) {
            continue;
         }

         $advertised = $registrations($source, 'ServerAdvertised');
         $started = $registrations($source, 'ServerStarted');

         if ($advertised === [] && $started === []) {
            continue;
         }

         $sources[substr($file, strlen($directory))] = [$source, $advertised, $started];
      }

      // ? An empty scan would make every assertion below vacuous
      yield assert(
         assertion: $sources !== [],
         description: 'The author tree exposes at least one shipped server project'
      );

      // @@
      foreach ($sources as $name => [$source, $advertised, $started]) {
         // ! Anything that reaches the terminal from a hook callback
         $writes = static function (array $registrations): bool {
            foreach ($registrations as $registration) {
               foreach (['->render(', '->advertise(', 'echo', 'print', 'fwrite', 'printf'] as $write) {
                  if (str_contains($registration, $write)) {
                     return true;
                  }
               }
            }

            return false;
         };

         // ! In Daemon mode — the default — the master detaches before
         //   ServerStarted fires, and the terminal Output still holds the
         //   STDOUT resource that detach() closed. Only ServerAdvertised
         //   runs on the process that still owns the terminal.
         yield assert(
            assertion: $writes($advertised),
            description: "{$name} composes its launch banner in a ServerAdvertised callback"
         );
         yield assert(
            assertion: $writes($started) === false,
            description: "{$name} writes nothing to the terminal from a ServerStarted callback"
         );

         // ? An exportable project is one a user can copy and `project startup`,
         //   which writes a `start -f` unit under Type=simple — without the arm
         //   that ExecStart daemonizes, exits 0 and orphans the server
         if (str_contains($source, 'exportable: true')) {
            yield assert(
               assertion: str_contains($source, 'Modes::Foreground'),
               description: "{$name} offers a Foreground arm alongside its other modes"
            );
         }
      }
   }
);
