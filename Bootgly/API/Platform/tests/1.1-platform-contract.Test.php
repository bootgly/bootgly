<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Platform;


use function array_map;
use function assert;
use Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Platform;


/**
 * `Bootgly\API\Platform` is what the Console and Web platform roots share:
 * one `autoboot (): void`, booted once per process. The contract is pinned
 * member by member, so a member added to it — which every platform root would
 * have to implement — is a deliberate change of this spec.
 */
return new Test(
   description: 'Platform: the contract every platform root implements — autoboot(), once',
   test: function () {
      // !
      $Contract = new ReflectionClass(Platform::class);

      // @ An interface, with exactly one member
      yield assert(
         assertion: $Contract->isInterface(),
         description: '`Bootgly\API\Platform` is an interface'
      );
      yield assert(
         assertion: array_map(
            static fn (ReflectionMethod $Method): string => $Method->name,
            $Contract->getMethods()
         ) === ['autoboot'],
         description: 'its only method is `autoboot()`'
      );
      yield assert(
         assertion: $Contract->getProperties() === [] && $Contract->getConstants() === [],
         description: 'it declares no property and no constant'
      );

      // @ `autoboot (): void`, no parameter
      $Method = $Contract->getMethod('autoboot');
      $Type = $Method->getReturnType();

      yield assert(
         assertion: $Method->getNumberOfParameters() === 0
            && $Type instanceof ReflectionNamedType
            && $Type->getName() === 'void',
         description: '`autoboot()` takes no parameter and returns void'
      );

      // @ A platform root implementing it boots once, then refuses
      $Root = new class implements Platform {
         // * Metadata
         private bool $booted = false;

         public function autoboot (): void
         {
            // ?
            if ($this->booted) {
               throw new Exception('Platform has already been booted.');
            }

            // * Metadata
            $this->booted = true;
         }
      };
      $Root->autoboot();
      $refused = false;
      try {
         $Root->autoboot();
      }
      catch (Exception) {
         $refused = true;
      }

      yield assert(
         assertion: $Root instanceof Platform && $refused,
         description: 'an implementing root is a Platform and refuses a second boot'
      );
   }
);
