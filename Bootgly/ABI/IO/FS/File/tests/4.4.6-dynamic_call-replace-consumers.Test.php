<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suite\Test;


/**
 * Which consumer gives up durability is a decision, pinned here on the tokens of each
 * `->replace(` call: regenerable records (the file cache, the file queue) skip the syncs,
 * everything a user would lose (environment, compiled templates, project registry,
 * observability dumps, lint rewrites) keeps the durable default.
 */
return new Test(
   description: 'replace() consumers: only the file cache and the file queue give up durability',
   test: function () {
      // ! The `durable` argument of every `->replace(` call in a source file, as written:
      //   `default` when absent, else its tokens (named `durable:` or the third positional)
      $durabilities = static function (string $file): array {
         $Tokens = token_get_all((string) file_get_contents($file));
         $count = count($Tokens);
         $found = [];

         // @@ Every `->replace(` / `?->replace(`
         for ($i = 0; $i < $count; $i++) {
            $Token = $Tokens[$i];
            if (is_array($Token) === false || ($Token[0] !== T_OBJECT_OPERATOR && $Token[0] !== T_NULLSAFE_OBJECT_OPERATOR)) {
               continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($Tokens[$j]) && $Tokens[$j][0] === T_WHITESPACE) {
               $j++;
            }
            if ($j >= $count || is_array($Tokens[$j]) === false || $Tokens[$j][0] !== T_STRING || $Tokens[$j][1] !== 'replace') {
               continue;
            }
            $j++;
            while ($j < $count && is_array($Tokens[$j]) && $Tokens[$j][0] === T_WHITESPACE) {
               $j++;
            }
            if ($j >= $count || $Tokens[$j] !== '(') {
               continue;
            }

            // @@ The arguments at depth 1, split on their commas
            $arguments = [[]];
            $depth = 0;
            for ($k = $j; $k < $count; $k++) {
               $Part = $Tokens[$k];
               $text = is_array($Part) ? $Part[1] : $Part;
               if (in_array($text, ['(', '[', '{'], true) || (is_array($Part) && in_array($Part[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                  $depth++;
                  if ($depth === 1) {
                     continue;
                  }
               }
               else if (in_array($text, [')', ']', '}'], true)) {
                  $depth--;
                  if ($depth === 0) {
                     break;
                  }
               }
               else if ($depth === 1 && $text === ',') {
                  $arguments[] = [];
                  continue;
               }
               if (is_array($Part) && in_array($Part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                  continue;
               }
               $arguments[count($arguments) - 1][] = $text;
            }

            // ! Named `durable:` wins; else the third positional; else the default
            $durable = 'default';
            $positional = 0;
            foreach ($arguments as $Argument) {
               if ($Argument === []) {
                  continue;
               }
               if (count($Argument) > 2 && $Argument[1] === ':' && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $Argument[0]) === 1) {
                  if ($Argument[0] === 'durable') {
                     $durable = implode('', array_slice($Argument, 2));
                  }
                  continue;
               }
               if ($positional === 2) {
                  $durable = implode('', $Argument);
               }
               $positional++;
            }
            $found[] = $durable;
         }

         return $found;
      };

      $base = BOOTGLY_ROOT_BASE;
      $expected = [
         // * Regenerable records — atomic, never synced
         'Bootgly/ABI/Resources/Cache/Drivers/File.php' => ['false'],
         'Bootgly/ACI/Queues/Drivers/File.php'          => ['false'],
         // * What a user would lose — durable
         'Bootgly/API/Environment.php'                  => ['default', 'true'],
         'Bootgly/ABI/Templates/Template.php'           => ['default', 'true'],
         'Bootgly/API/Projects.php'                     => ['default', 'true'],
         'Bootgly/ACI/Observability.php'                => ['default', 'true'],
         'Bootgly/commands/LintCommand.php'             => ['default', 'true'],
      ];
      foreach ($expected as $file => $allowed) {
         $found = $durabilities("{$base}/{$file}");
         $foreign = array_values(array_diff($found, $allowed));
         yield assert(
            assertion: $found !== [] && $foreign === [],
            description: "Invalid replace() consumer {$file}: durability " . json_encode($found)
               . ' — expected only ' . json_encode($allowed) . ' (and at least one call)!'
         );
      }
   }
);
