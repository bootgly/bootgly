<?php

use Bootgly\ABI\Data\Language;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should isolate the locale per Fiber (deferred work does not leak)',
   test: new Assertions(Case: function (): Generator {
      Language::reset();
      Language::load(__DIR__ . '/catalogs');
      Language::$locale = 'en';

      // ! A "deferred" Fiber bound to pt-BR — the global locale changes
      //   while it is suspended (simulating an interleaved request)
      $Fiber = new Fiber(function (): string {
         Language::bind('pt-BR');

         Fiber::suspend(Language::translate('Welcome, {name}!', ['name' => 'A']));

         $bound = Language::translate('Welcome, {name}!', ['name' => 'A']);

         Language::unbind();
         $unbound = Language::translate('Welcome, {name}!', ['name' => 'A']);

         return "{$bound}|{$unbound}";
      });

      $first = $Fiber->start();

      yield new Assertion(description: 'Bound Fiber should translate under its own locale')
         ->expect($first)
         ->to->be('Bem-vindo, A!')
         ->assert();

      yield new Assertion(description: 'The binding should not leak outside the Fiber')
         ->expect(Language::resolve())
         ->to->be('en')
         ->assert();

      // @ Interleaved request negotiates another locale while A is suspended
      Language::$locale = 'en';

      $Fiber->resume();
      $second = $Fiber->getReturn();

      yield new Assertion(description: 'Resumed Fiber should keep its binding; unbind should fall back to the global locale')
         ->expect($second)
         ->to->be('Bem-vindo, A!|Welcome, A!')
         ->assert();

      // @ Outside any Fiber, resolve() is the global locale
      Language::$locale = 'pt-BR';

      yield new Assertion(description: 'resolve() should return the global locale outside a Fiber')
         ->expect(Language::resolve())
         ->to->be('pt-BR')
         ->assert();

      Language::reset();
   })
);
