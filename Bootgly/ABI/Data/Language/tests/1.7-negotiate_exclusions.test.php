<?php

use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Data\Language\Locales;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should honor q=0 exclusions through wildcard and expansion matching',
   test: new Assertions(Case: function (): Generator {
      // @ Locales::choose — exclusions filter the offers before matching
      yield new Assertion(description: 'A wildcard preference should skip an excluded offer (`*;q=0.5, en;q=0`)')
         ->expect(Locales::choose(['*'], ['en', 'pt-BR'], ['en']))
         ->to->be('pt-BR')
         ->assert();

      yield new Assertion(description: 'A wildcard preference should yield no match when every offer is excluded')
         ->expect(Locales::choose(['*'], ['en'], ['en']) === null)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'An excluded parent range should cover its regional offers (`pt;q=0` refuses `pt-BR`)')
         ->expect(Locales::choose(['*'], ['pt-BR', 'en'], ['pt']))
         ->to->be('en')
         ->assert();

      yield new Assertion(description: 'A more specific accepted range should re-include the offer (`pt;q=0, pt-BR;q=0.5`)')
         ->expect(Locales::choose(['pt-BR'], ['pt-BR', 'pt'], ['pt']))
         ->to->be('pt-BR')
         ->assert();

      yield new Assertion(description: 'Truncation should not reach an explicitly excluded parent offer')
         ->expect(Locales::choose(['pt-BR'], ['pt'], ['pt']) === null)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'A `*;q=0` refusal should spare explicitly preferred offers only')
         ->expect(Locales::choose(['en'], ['en', 'pt'], ['*']))
         ->to->be('en')
         ->assert();

      yield new Assertion(description: 'Excluded ranges should match case-insensitively (normalized)')
         ->expect(Locales::choose(['*'], ['en', 'pt-BR'], ['EN']))
         ->to->be('pt-BR')
         ->assert();

      // @ Language::negotiate — source fallback when everything is refused
      Language::reset();
      Language::load(__DIR__ . '/catalogs'); // pt-BR catalogs

      yield new Assertion(description: 'negotiate() should route a wildcard around the excluded source')
         ->expect(Language::negotiate(['*'], ['en']))
         ->to->be('pt') // first catalog offer after the refused source (scandir order)
         ->assert();

      yield new Assertion(description: 'negotiate() should disregard the header when every offer is refused (source served)')
         ->expect(Language::negotiate([], ['*']))
         ->to->be('en')
         ->assert();

      yield new Assertion(description: 'negotiate() with only an exclusion and no preference should serve the source')
         ->expect(Language::negotiate([], ['en']))
         ->to->be('en')
         ->assert();

      Language::reset();
   })
);
