<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header\Cookies;


/**
 * A copy of a response Header builds its own Cookies on first use, and every
 * reader sees them exactly as eagerly as before.
 *
 * Built in `__clone()`, the copy's Cookies referred back to it — a cycle
 * every cloned response left to the collector, even one that never touched a
 * cookie. Pinned:
 *
 * - an exact-class copy carries no Cookies until one is used — yet `isset()`
 *   reports one, the first read builds it bound to the copy, empty of the
 *   source's cookies and distinct from them, and every later read returns
 *   that same one;
 * - a write to a copy whose Cookies were never built lands like a write to
 *   built ones — the written Cookies is the one read back — and anything but
 *   a Cookies is refused with a TypeError;
 * - `clean()` on a copy whose source queued a cookie resets the copy's own
 *   (lazily built) accumulator and leaves the source's alone;
 * - a subclass that hooks the property — which `unset()` refuses — builds
 *   its copy's Cookies in `__clone()`, bound to the copy.
 */
if (! class_exists('U172Header', false)) {
   /**
    * A Header subclass that hooks its Cookies.
    */
   class U172Header extends Header
   {
      public Cookies $Cookies {
         set (Cookies $value) {
            $this->Cookies = $value;
         }
      }
   }
}


return new Test(
   description: 'A copy of a response Header should build its own Cookies on first use, every reader seeing them exactly as before',
   test: new Assertions(Case: function (): Generator {
      $Built = new ReflectionProperty(Header::class, 'Cookies');
      $Source = new Header;
      $Source->Cookies->append(new Cookie('sid', 'SOURCE-SECRET'));

      // @ An exact-class copy
      $Copy = clone $Source;
      $before = $Built->isInitialized($Copy);
      $reported = isset($Copy->Cookies);
      $Cookies = $Copy->Cookies;
      $copied = [
         'lazy' => $before === false,
         'isset' => $reported,
         'built' => $Cookies instanceof Cookies,
         'bound' => $Cookies->Header === $Copy,
         'fresh' => $Cookies->cookies === [],
         'distinct' => $Cookies !== $Source->Cookies,
         'stable' => $Copy->Cookies === $Cookies,
         'source' => count($Source->Cookies->cookies) === 1 && $Source->Cookies->Header === $Source,
      ];

      // @ A write to a copy whose Cookies were never built
      $Written = clone $Source;
      $Mine = new Cookies($Written);
      $Written->Cookies = $Mine;
      $written = [
         'lands' => $Written->Cookies === $Mine,
      ];
      $Refused = clone $Source;
      try {
         $Refused->Cookies = 'not a Cookies'; // @phpstan-ignore assign.propertyType
         $written['typed'] = 'accepted';
      }
      catch (TypeError) {
         $written['typed'] = 'refused';
      }

      // @ clean() on a copy whose source queued a cookie
      $Cleaned = clone $Source;
      $Cleaned->clean();
      $cleaned = [
         'own' => $Cleaned->Cookies->cookies === [] && $Cleaned->Cookies->Header === $Cleaned,
         'source' => count($Source->Cookies->cookies) === 1,
      ];

      // @ A subclass that hooks the property
      $Hooked = new U172Header;
      $Hooked->Cookies->append(new Cookie('sid', 'SOURCE-SECRET'));
      try {
         $HookedCopy = clone $Hooked;
         $subclassed = [
            'built' => new ReflectionProperty($HookedCopy, 'Cookies')->isInitialized($HookedCopy),
            'bound' => $HookedCopy->Cookies->Header === $HookedCopy,
            'fresh' => $HookedCopy->Cookies->cookies === [],
            'distinct' => $HookedCopy->Cookies !== $Hooked->Cookies,
         ];
      }
      catch (Throwable $Throwable) {
         $subclassed = $Throwable::class . ": {$Throwable->getMessage()}";
      }

      $observed = json_encode(['copied' => $copied, 'written' => $written, 'cleaned' => $cleaned, 'subclassed' => $subclassed]);

      yield assert(
         assertion: $copied === array_fill_keys(array_keys($copied), true)
            && $written === ['lands' => true, 'typed' => 'refused']
            && $cleaned === ['own' => true, 'source' => true]
            && $subclassed === ['built' => true, 'bound' => true, 'fresh' => true, 'distinct' => true],
         description: "A copy builds its own Cookies on first use — reported by isset(), bound to it, fresh, stable — a write to it lands or is refused by type, clean() resets its own, and a hooking subclass builds them in __clone(): {$observed}",
      );
   })
);
