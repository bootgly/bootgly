<?php

use Bootgly\ABI\Data\Language;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should negotiate and load non-canonical locale directories',
   test: new Assertions(Case: function (): Generator {
      Language::reset();
      Language::load(__DIR__ . '/catalogs.posix');

      // @ Negotiation returns the normalized tag, not the directory name
      yield new Assertion(description: 'POSIX-style directory should negotiate as its normalized tag')
         ->expect(Language::negotiate(['pt-BR']))
         ->to->be('pt-BR')
         ->assert();

      // @ The normalized tag loads from the real (non-canonical) directory
      Language::$locale = 'pt-BR';

      yield new Assertion(description: 'Normalized locale should load from the pt_BR directory')
         ->expect(Language::translate('Hello'))
         ->to->be('Olá')
         ->assert();

      yield new Assertion(description: 'POSIX per-call override should load from the pt_BR directory')
         ->expect(Language::translate('Hello', locale: 'pt_BR.UTF-8'))
         ->to->be('Olá')
         ->assert();

      Language::reset();
   })
);
