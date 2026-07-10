<?php

use Bootgly\ABI\Data\Language;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should treat malformed catalogs as misses — translate() never throws',
   test: new Assertions(Case: function (): Generator {
      Language::reset();
      Language::load(__DIR__ . '/catalogs.malformed');
      Language::$locale = 'pt-BR';

      // @ Parse failure (invalid PHP) behaves like a miss
      yield new Assertion(description: 'Catalog with a syntax error should fall back to the source')
         ->expect(Language::translate('Anything', domain: 'broken'))
         ->to->be('Anything')
         ->assert();

      // @ Throwing catalog behaves like a miss
      yield new Assertion(description: 'Catalog that throws should fall back to the source')
         ->expect(Language::translate('Anything', domain: 'throwing'))
         ->to->be('Anything')
         ->assert();

      // @ Non-array return behaves like a miss
      yield new Assertion(description: 'Catalog returning a non-array should fall back to the source')
         ->expect(Language::translate('Anything', domain: 'scalar'))
         ->to->be('Anything')
         ->assert();

      // @ Non-string values are dropped, string values survive
      yield new Assertion(description: 'Array value should be dropped and fall back to the source')
         ->expect(Language::translate('Key A', domain: 'mixed'))
         ->to->be('Key A')
         ->assert();

      yield new Assertion(description: 'String value should keep translating')
         ->expect(Language::translate('Key B', domain: 'mixed'))
         ->to->be('Valor B')
         ->assert();

      yield new Assertion(description: 'Integer value should be dropped and fall back to the source')
         ->expect(Language::translate('Key C', domain: 'mixed'))
         ->to->be('Key C')
         ->assert();

      // @ Failures cache as misses — a second call stays consistent
      yield new Assertion(description: 'Broken catalog should stay a miss on repeated calls')
         ->expect(Language::translate('Anything', domain: 'broken'))
         ->to->be('Anything')
         ->assert();

      Language::reset();
   })
);
