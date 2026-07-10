<?php

use Bootgly\ABI\Data\Language;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should load catalogs, negotiate and fall back across locales',
   test: new Assertions(Case: function (): Generator {
      Language::reset();
      Language::load(__DIR__ . '/catalogs');
      Language::$locale = 'pt-BR';

      // @ Catalog hit
      yield new Assertion(description: 'Translation should come from the locale catalog')
         ->expect(Language::translate('Welcome, {name}!', ['name' => 'Bootgly']))
         ->to->be('Bem-vindo, Bootgly!')
         ->assert();

      yield new Assertion(description: 'Domain catalogs should be separated')
         ->expect(Language::translate('{field} is required.', ['field' => 'email'], domain: 'validation'))
         ->to->be('email é obrigatório.')
         ->assert();

      yield new Assertion(description: 'Missing key should fall back to the source message')
         ->expect(Language::translate('Untranslated message'))
         ->to->be('Untranslated message')
         ->assert();

      // @ Locale fallback chain (`pt-BR` → `pt`)
      yield new Assertion(description: 'Regional miss should fall back to the language catalog')
         ->expect(Language::translate('Not Found', domain: 'errors'))
         ->to->be('Não encontrado')
         ->assert();

      // @ Plural forms from the catalog
      yield new Assertion(description: 'Catalog plural forms should select by count')
         ->expect(Language::translate('{count} result|{count} results', count: 2))
         ->to->be('2 resultados')
         ->assert();

      // @ Per-call locale override
      yield new Assertion(description: 'Locale override should bypass the active locale')
         ->expect(Language::translate('Welcome, {name}!', ['name' => 'x'], locale: 'en'))
         ->to->be('Welcome, x!')
         ->assert();

      // @ Path-safety guards
      yield new Assertion(description: 'Malformed domain should return the key')
         ->expect(Language::translate('Welcome, {name}!', ['name' => 'x'], domain: '../app'))
         ->to->be('Welcome, x!')
         ->assert();

      yield new Assertion(description: 'Malformed active locale should return the key')
         ->expect(Language::translate('Sign in', locale: '../../etc'))
         ->to->be('Sign in')
         ->assert();

      // @ Root precedence — later-registered roots win
      Language::load(__DIR__ . '/catalogs.override');

      yield new Assertion(description: 'The most recently registered root should win')
         ->expect(Language::translate('Welcome, {name}!', ['name' => 'Bootgly']))
         ->to->be('Bem-vindo ao override, Bootgly!')
         ->assert();

      yield new Assertion(description: 'Keys absent from the override should keep resolving below')
         ->expect(Language::translate('{field} is required.', ['field' => 'email'], domain: 'validation'))
         ->to->be('email é obrigatório.')
         ->assert();

      // @ Negotiation against the available locale directories
      yield new Assertion(description: 'Exact preference should negotiate the catalog locale')
         ->expect(Language::negotiate(['pt-BR', 'en']))
         ->to->be('pt-BR')
         ->assert();

      yield new Assertion(description: 'Language preference should expand to a regional catalog')
         ->expect(Language::negotiate(['pt']))
         ->to->be('pt')
         ->assert();

      yield new Assertion(description: 'Unsatisfiable preferences should fall back to the source')
         ->expect(Language::negotiate(['de', 'ja']))
         ->to->be('en')
         ->assert();

      yield new Assertion(description: 'No preferences should fall back to the source')
         ->expect(Language::negotiate([]))
         ->to->be('en')
         ->assert();

      // @ reset() — back to boot state
      Language::reset();

      yield new Assertion(description: 'Reset should drop registered roots')
         ->expect(Language::$roots)
         ->to->be([])
         ->assert();

      yield new Assertion(description: 'Reset should restore the identity behavior')
         ->expect(Language::translate('Welcome, {name}!', ['name' => 'x'], locale: 'pt-BR'))
         ->to->be('Welcome, x!')
         ->assert();
   })
);
