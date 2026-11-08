<?php

use Bootgly\ABI\Syntax\Analyzers\Issue;
use Bootgly\ABI\Syntax\Analyzers\Rules;
use Bootgly\ABI\Syntax\Lint\Exemption;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Exemption: a reason is required, the symbol is name, Class::name or Class::$name; cover() matches the symbol (classes and methods case-insensitively, $ names exactly)',
   test: function () {
      $Issue = static fn (string $symbol): Issue => new Issue(type: 't', symbol: $symbol, kind: 'k', line: 1, message: 'm');

      // @ No symbol — every issue of the matched file
      $Whole = new Exemption(rule: Rules::Methods, path: 'a.php', reason: 'Legacy');
      yield assert(
         assertion: $Whole->cover($Issue('anything'), '') && $Whole->cover($Issue('other'), 'Foo')
            && $Whole->rule === Rules::Methods && $Whole->path === 'a.php' && $Whole->reason === 'Legacy' && $Whole->symbol === null,
         description: 'An exemption without a symbol must cover every issue and expose its inputs'
      );

      // @ name — any class
      $Name = new Exemption(rule: Rules::Methods, path: 'a.php', reason: 'r', symbol: 'assertTrue');
      yield assert(
         assertion: $Name->cover($Issue('assertTrue'), 'Assertion') && $Name->cover($Issue('ASSERTTRUE'), '')
            && $Name->cover($Issue('assertFalse'), 'Assertion') === false,
         description: 'A bare name must match the symbol case-insensitively, in any class'
      );

      // @ Class::name — that class only
      $Qualified = new Exemption(rule: Rules::Methods, path: 'a.php', reason: 'r', symbol: 'Assertion::assertTrue');
      yield assert(
         assertion: $Qualified->cover($Issue('assertTrue'), 'Assertion') && $Qualified->cover($Issue('assertTrue'), 'assertion')
            && $Qualified->cover($Issue('assertTrue'), 'Other') === false && $Qualified->cover($Issue('assertTrue'), '') === false,
         description: 'Class::name must match only inside that class (case-insensitive class name)'
      );

      // @ Class::$name and $name — exact
      $Property = new Exemption(rule: Rules::Casing, path: 'a.php', reason: 'r', symbol: 'Server::$url');
      $Variable = new Exemption(rule: Rules::Casing, path: 'a.php', reason: 'r', symbol: '$url');
      yield assert(
         assertion: $Property->cover($Issue('$url'), 'Server') && $Property->cover($Issue('url'), 'Server')
            && $Property->cover($Issue('$URL'), 'Server') === false && $Property->cover($Issue('$url'), 'Client') === false
            && $Variable->cover($Issue('$url'), 'Anything') && $Variable->cover($Issue('$Url'), '') === false,
         description: '$ names must match exactly, with or without the class'
      );

      // @@ Refused inputs
      $accepted = [];
      $inputs = [
         'blank reason' => ['reason' => '  ', 'symbol' => null],
         'empty reason' => ['reason' => '', 'symbol' => null],
         'two separators' => ['reason' => 'r', 'symbol' => 'A::b::c'],
         'parentheses' => ['reason' => 'r', 'symbol' => 'A::b()'],
         'namespaced class' => ['reason' => 'r', 'symbol' => 'Foo\\Bar::baz'],
         'empty symbol' => ['reason' => 'r', 'symbol' => ''],
         'leading digit' => ['reason' => 'r', 'symbol' => '1abc'],
      ];
      foreach ($inputs as $label => $input) {
         try {
            new Exemption(rule: Rules::Methods, path: 'a.php', reason: $input['reason'], symbol: $input['symbol']);
            $accepted[] = $label;
         }
         catch (InvalidArgumentException) {
         }
      }
      yield assert(
         assertion: $accepted === [],
         description: 'A blank reason and a malformed symbol must throw, accepted: ' . json_encode($accepted)
      );
   }
);
