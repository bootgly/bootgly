<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment;


use function assert;
use function is_string;
use function str_contains;
use ReflectionClass;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environment;


/**
 * I-29: `load()`, `save()` and `list()` are deprecated since 1.1.0 and removed
 * in 2.0.0 — `.env` files belong to `Environment\Configs`, `list()` is
 * `getenv()`. The deprecation is the PHPDoc tag only: a `#[\Deprecated]`
 * attribute or an `E_USER_DEPRECATED` notice becomes an `ErrorException` under
 * the framework's error handler, which would turn the deprecation into a
 * removal inside a minor. The rest of the facade stays undeprecated, and the
 * dead `$Configs` static is gone.
 */
return new Test(
   description: 'I-29: Environment::load(), save() and list() carry @deprecated 1.1.0 in PHPDoc only',
   test: function () {
      // !
      $Class = new ReflectionClass(Environment::class);

      // @@ The three deprecated methods: tag present, no runtime attribute
      foreach (['load', 'save', 'list'] as $method) {
         $Method = $Class->getMethod($method);
         $comment = $Method->getDocComment();

         yield assert(
            assertion: is_string($comment) && str_contains($comment, '@deprecated 1.1.0'),
            description: "`{$method}()` carries `@deprecated 1.1.0` in its PHPDoc"
         );
         yield assert(
            assertion: is_string($comment) && str_contains($comment, 'Removed in 2.0.0.'),
            description: "`{$method}()` names its removal release"
         );
         yield assert(
            assertion: $Method->isDeprecated() === false,
            description: "`{$method}()` has no `#[\\Deprecated]` attribute — no runtime notice"
         );
      }

      // @@ The rest of the facade is not deprecated
      foreach (['get', 'match', 'put', 'del'] as $method) {
         $comment = $Class->getMethod($method)->getDocComment();

         yield assert(
            assertion: $comment === false || str_contains($comment, '@deprecated') === false,
            description: "`{$method}()` is not deprecated"
         );
      }

      // @ The dead static is removed
      yield assert(
         assertion: $Class->hasProperty('Configs') === false,
         description: 'the unused `Environment::$Configs` static is gone'
      );
   }
);
