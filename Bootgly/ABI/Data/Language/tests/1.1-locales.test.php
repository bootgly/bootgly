<?php

use Bootgly\ABI\Data\Language\Locales;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should normalize, chain and choose locale tags',
   test: new Assertions(Case: function (): Generator {
      // @ normalize — BCP 47 tags
      yield new Assertion(description: 'Lowercase region should be uppercased')
         ->expect(Locales::normalize('pt-br'))
         ->to->be('pt-BR')
         ->assert();

      yield new Assertion(description: 'Mixed casing should be canonicalized')
         ->expect(Locales::normalize('PT-br'))
         ->to->be('pt-BR')
         ->assert();

      yield new Assertion(description: 'Script subtag should be titlecased')
         ->expect(Locales::normalize('zh-hant-tw'))
         ->to->be('zh-Hant-TW')
         ->assert();

      yield new Assertion(description: 'Plain language tag should pass through')
         ->expect(Locales::normalize('en'))
         ->to->be('en')
         ->assert();

      // @ normalize — POSIX environment values
      yield new Assertion(description: 'POSIX codeset should be stripped')
         ->expect(Locales::normalize('pt_BR.UTF-8'))
         ->to->be('pt-BR')
         ->assert();

      yield new Assertion(description: 'POSIX modifier should be stripped')
         ->expect(Locales::normalize('en_US@euro'))
         ->to->be('en-US')
         ->assert();

      yield new Assertion(description: 'The C pseudo-locale should carry no language')
         ->expect(Locales::normalize('C'))
         ->to->be('')
         ->assert();

      yield new Assertion(description: 'The POSIX pseudo-locale should carry no language')
         ->expect(Locales::normalize('POSIX'))
         ->to->be('')
         ->assert();

      // @ normalize — malformed input
      yield new Assertion(description: 'Empty input should normalize to empty')
         ->expect(Locales::normalize(''))
         ->to->be('')
         ->assert();

      yield new Assertion(description: 'Non-alphanumeric input should normalize to empty')
         ->expect(Locales::normalize('pt BR!'))
         ->to->be('')
         ->assert();

      yield new Assertion(description: 'Oversized subtags should normalize to empty')
         ->expect(Locales::normalize('portuguesebrazil'))
         ->to->be('')
         ->assert();

      yield new Assertion(description: 'Path characters should never survive')
         ->expect(Locales::normalize('../etc'))
         ->to->be('')
         ->assert();

      // @ chain — progressive truncation
      yield new Assertion(description: 'Regional tag should chain to its language')
         ->expect(Locales::chain('pt-BR'))
         ->to->be(['pt-BR', 'pt'])
         ->assert();

      yield new Assertion(description: 'Script tag should chain progressively')
         ->expect(Locales::chain('zh-Hant-TW'))
         ->to->be(['zh-Hant-TW', 'zh-Hant', 'zh'])
         ->assert();

      yield new Assertion(description: 'Plain language should chain to itself only')
         ->expect(Locales::chain('pt'))
         ->to->be(['pt'])
         ->assert();

      yield new Assertion(description: 'Empty locale should chain to nothing')
         ->expect(Locales::chain(''))
         ->to->be([])
         ->assert();

      // @ choose — matching passes
      yield new Assertion(description: 'Exact match should win')
         ->expect(Locales::choose(['pt-BR'], ['en', 'pt-BR']))
         ->to->be('pt-BR')
         ->assert();

      yield new Assertion(description: 'Truncation should match the parent language')
         ->expect(Locales::choose(['pt-BR'], ['en', 'pt']))
         ->to->be('pt')
         ->assert();

      yield new Assertion(description: 'Regional expansion should match the first regional offer')
         ->expect(Locales::choose(['pt'], ['en', 'pt-PT', 'pt-BR']))
         ->to->be('pt-PT')
         ->assert();

      yield new Assertion(description: 'Wildcard should take the first valid offer')
         ->expect(Locales::choose(['*'], ['en', 'pt-BR']))
         ->to->be('en')
         ->assert();

      yield new Assertion(description: 'Client order should win over server order')
         ->expect(Locales::choose(['fr', 'en'], ['en', 'fr']))
         ->to->be('fr')
         ->assert();

      yield new Assertion(description: 'Preferences should normalize before matching')
         ->expect(Locales::choose(['PT_br.UTF-8'], ['pt-BR']))
         ->to->be('pt-BR')
         ->assert();

      // @ choose — no match
      yield new Assertion(description: 'No offers should choose nothing')
         ->expect(Locales::choose(['pt-BR'], []) === null)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'Unsatisfiable preferences should choose nothing')
         ->expect(Locales::choose(['de'], ['en', 'pt-BR']) === null)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'Malformed preferences should be skipped')
         ->expect(Locales::choose(['!!', 'en'], ['en']))
         ->to->be('en')
         ->assert();
   })
);
