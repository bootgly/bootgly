<?php

use Bootgly\ABI\Data\Language;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should translate with zero catalogs (identity, substitutions, plurals)',
   test: new Assertions(Case: function (): Generator {
      Language::reset();

      // @ Identity — the natural-source key is the terminal fallback
      yield new Assertion(description: 'Message should return verbatim with zero catalogs')
         ->expect(Language::translate('Sign in'))
         ->to->be('Sign in')
         ->assert();

      yield new Assertion(description: 'Empty message should stay empty')
         ->expect(Language::translate(''))
         ->to->be('')
         ->assert();

      // @ Substitutions
      yield new Assertion(description: 'Placeholders should interpolate')
         ->expect(Language::translate('Welcome, {name}!', ['name' => 'Bootgly']))
         ->to->be('Welcome, Bootgly!')
         ->assert();

      yield new Assertion(description: 'Numeric values should cast to string')
         ->expect(Language::translate('{field} must be at least {limit}.', ['field' => 'age', 'limit' => 18]))
         ->to->be('age must be at least 18.')
         ->assert();

      yield new Assertion(description: 'Unknown tokens should pass through literally')
         ->expect(Language::translate('Hello, {name}!', ['other' => 'x']))
         ->to->be('Hello, {name}!')
         ->assert();

      // @ Plurals — `|`-separated forms, selected only when a count is given
      yield new Assertion(description: 'Count of one should pick the first form')
         ->expect(Language::translate('{count} result|{count} results', count: 1))
         ->to->be('1 result')
         ->assert();

      yield new Assertion(description: 'Count of many should pick the last form')
         ->expect(Language::translate('{count} result|{count} results', count: 7))
         ->to->be('7 results')
         ->assert();

      yield new Assertion(description: 'Count of zero should pick the last form')
         ->expect(Language::translate('{count} result|{count} results', count: 0))
         ->to->be('0 results')
         ->assert();

      yield new Assertion(description: 'Float count equal to one should pick the first form')
         ->expect(Language::translate('{count} result|{count} results', count: 1.0))
         ->to->be('1 result')
         ->assert();

      yield new Assertion(description: 'Explicit count substitution should win over the auto-fill')
         ->expect(Language::translate('{count} results', ['count' => 'seven'], count: 7))
         ->to->be('seven results')
         ->assert();

      yield new Assertion(description: 'Pipes should be inert without a count')
         ->expect(Language::translate('a|b'))
         ->to->be('a|b')
         ->assert();

      // @ Never-throws — a throwing Stringable leaves its token untouched
      $Throwing = new class implements Stringable {
         public function __toString (): string
         {
            throw new RuntimeException('cast failed');
         }
      };

      yield new Assertion(description: 'Throwing Stringable should leave the token untouched')
         ->expect(Language::translate('Hello, {name}!', ['name' => $Throwing]))
         ->to->be('Hello, {name}!')
         ->assert();

      yield new Assertion(description: 'Other substitutions should survive a throwing Stringable')
         ->expect(Language::translate('{a} and {b}', ['a' => $Throwing, 'b' => 'ok']))
         ->to->be('{a} and ok')
         ->assert();

      // @ Per-call locale override — never mutates the active locale
      yield new Assertion(description: 'Locale override should not change the message with zero catalogs')
         ->expect(Language::translate('Sign in', locale: 'pt-BR'))
         ->to->be('Sign in')
         ->assert();

      yield new Assertion(description: 'Locale override should not mutate the active locale')
         ->expect(Language::$locale)
         ->to->be('en')
         ->assert();

      Language::reset();
   })
);
