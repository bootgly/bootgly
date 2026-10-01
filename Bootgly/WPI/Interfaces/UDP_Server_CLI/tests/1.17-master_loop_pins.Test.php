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
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'UDP-19/UDP-21: only revive() forks a worker and every master loop reaps only through the PID 1 guard of reap()',
   test: new Assertions(Case: function (): Generator {
      // ! Token-based: comments and strings never satisfy or break the pin.
      $Tokens = token_get_all((string) file_get_contents(BOOTGLY_ROOT_DIR . 'Bootgly/WPI/Interfaces/UDP_Server_CLI.php'));
      $count = count($Tokens);
      $Ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
      /** The index of the significant token before (-1) or after (+1) $index. */
      $Step = static function (int $index, int $direction) use ($Tokens, $Ignored, $count): int {
         do {
            $index += $direction;
         } while ($index >= 0 && $index < $count && is_array($Tokens[$index]) && in_array($Tokens[$index][0], $Ignored, true));

         return $index;
      };
      /** Whether the token at $index is of the PHP token $kind. */
      $Is = static fn (int $index, int $kind): bool => is_array($Tokens[$index] ?? null) && $Tokens[$index][0] === $kind;

      /** @var array<string,array<int,string>> $Calls method name => what it calls (and reads), in order */
      $Calls = [];
      $method = null;
      $depth = 0;
      $entered = 0;
      for ($index = 0; $index < $count; $index++) {
         $Token = $Tokens[$index];
         // ? `use function x;` imports are not declarations
         if ($Is($index, T_FUNCTION) && $method === null && $Is($Step($index, -1), T_USE) === false) {
            $next = $Step($index, 1);
            if (($Tokens[$next] ?? null) === '&') {
               $next = $Step($next, 1);
            }
            if ($Is($next, T_STRING)) {
               $method = $Tokens[$next][1];
               $entered = $depth;
            }
         }
         if ($Token === '{' || (is_array($Token) && in_array($Token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
         }
         if ($Token === '}') {
            $depth--;
            if ($method !== null && $depth === $entered) {
               $method = null;
            }
         }
         if ($method === null || is_array($Token) === false || in_array($Token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) === false) {
            continue;
         }

         $previous = $Step($index, -1);
         // # A call — `name (`, `\name(`, `->name(`, `::name(` — never the declaration itself
         if (($Tokens[$Step($index, 1)] ?? null) === '(' && $Is($previous, T_FUNCTION) === false) {
            $Calls[$method][] = strtolower(ltrim($Token[1], '\\'));
         }
         // # A `Status::Paused` read
         if ($Token[1] === 'Paused' && $Is($previous, T_DOUBLE_COLON)) {
            $Calls[$method][] = 'Status::Paused';
         }
      }

      /** The methods calling any of $Names, in file order. */
      $Callers = static fn (array $Names): array => array_keys(array_filter(
         $Calls,
         static fn (array $Called): bool => array_intersect($Names, $Called) !== []
      ));
      /** Whether, inside $method, $first happens before $then. */
      $Before = static function (string $method, string $first, string $then) use ($Calls): bool {
         $Called = $Calls[$method] ?? [];
         $one = array_search($first, $Called, true);
         $other = array_search($then, $Called, true);

         return $one !== false && $other !== false && $one < $other;
      };

      /** The first $count significant tokens of $method's body, as text. */
      $Opening = static function (string $method, int $count) use ($Tokens, $Step, $Is): array {
         foreach ($Tokens as $index => $Token) {
            if ($Is($index, T_FUNCTION) === false) {
               continue;
            }
            $name = $Step($index, 1);
            if ($Is($name, T_STRING) === false || $Tokens[$name][1] !== $method) {
               continue;
            }
            // @ Skip the signature up to the body's opening brace
            $cursor = $name;
            while (isSet($Tokens[$cursor]) && $Tokens[$cursor] !== '{') {
               $cursor++;
            }
            $Texts = [];
            for ($cursor = $Step($cursor, 1); count($Texts) < $count && isSet($Tokens[$cursor]); $cursor = $Step($cursor, 1)) {
               $Texts[] = is_array($Tokens[$cursor]) ? $Tokens[$cursor][1] : $Tokens[$cursor];
            }

            return $Texts;
         }

         return [];
      };

      // @ Forking and reaping
      yield new Assertion(description: 'workers are forked by revive() (the daemon by detach()); the loops reap only through reap(), guarded to PID 1')
         ->expect(
            [
               'forks' => $Callers(['pcntl_fork']),
               'reapers' => $Callers(['pcntl_wait', 'pcntl_waitpid']),
               'reap called by' => $Callers(['reap']),
               // ! The guard's exact shape: anything else lets a master that is
               //   not PID 1 steal an application's proc_open() exit status
               'reap guarded by PID 1' => $Opening('reap', 12) === ['if', '(', 'posix_getpid', '(', ')', '!==', '1', ')', '{', 'return', ';', '}'],
               'revive called by' => $Callers(['revive']),
            ],
            Op::Identical,
            [
               'forks' => ['revive', 'detach'],
               'reapers' => ['reap', 'detach'],
               'reap called by' => ['daemonize', 'serve', 'interacting', 'monitoring'],
               'reap guarded by PID 1' => true,
               'revive called by' => ['daemonize', 'serve', 'interacting', 'monitoring'],
            ],
         )
         ->assert();

      // @ A worker revived while paused stays paused
      yield new Assertion(description: 'work() reads the paused state before instance() marks the process Running')
         ->expect($Before('work', 'Status::Paused', 'instance'), Op::Identical, true)
         ->assert();
   }),
);
