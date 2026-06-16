<?php

use function ini_set;

use ReflectionMethod;
use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;


/**
 * PoC — `Session::init()` downgrades the framework's secure cookie defaults to
 * `php.ini` (audit F-9).
 *
 * The class declares the safe posture (`$secure = true`, `$httpOnly = true`),
 *   but `init()` overwrote both from `session_get_cookie_params()`:
 *
 *     static::$secure   = $sessionCookieParams['secure'];   // php.ini cookie_secure (off by default)
 *     static::$httpOnly = $sessionCookieParams['httponly']; // php.ini cookie_httponly (off by default)
 *
 *   On a stock PHP install both ini flags are OFF, so the session cookie is
 *   sent over plain HTTP (interceptable) and is readable by JavaScript
 *   (XSS theft) — silently, despite the safer declared default.
 *
 * Fixed behaviour: `init()` no longer reads `secure`/`httponly` from ini. The
 *   framework owns them (default `true`); HTTP-only dev relaxes explicitly via
 *   `Session::$secure = false`. `SameSite` was already framework-owned (`Lax`).
 *
 * The probe forces both ini flags OFF, re-runs `init()`, and asserts the
 *   defaults are not downgraded.
 */

return new Specification(
   description: 'Session::init() must not downgrade $secure/$httpOnly to stock php.ini',
   test: function () {
      // ! Force the stock-insecure ini posture.
      ini_set('session.cookie_secure', '0');
      ini_set('session.cookie_httponly', '0');

      // ! Start from the safe framework defaults, then un-initialize so init()
      //   runs its body again.
      Session::$secure = true;
      Session::$httpOnly = true;

      $Initialized = new ReflectionProperty(Session::class, 'initialized');
      $Initialized->setAccessible(true);
      $Initialized->setValue(null, false);

      // @ Re-run the protected static initializer.
      $Init = new ReflectionMethod(Session::class, 'init');
      $Init->setAccessible(true);
      $Init->invoke(null);

      // ? Secure must survive a stock `session.cookie_secure = 0`.
      yield assert(
         assertion: Session::$secure === true,
         description: 'init() must not downgrade $secure to php.ini session.cookie_secure (stock off)'
      );
      // ? HttpOnly must survive a stock `session.cookie_httponly = 0`.
      yield assert(
         assertion: Session::$httpOnly === true,
         description: 'init() must not downgrade $httpOnly to php.ini session.cookie_httponly (stock off)'
      );
      // ? SameSite stays the framework default (never sourced from ini).
      yield assert(
         assertion: Session::$sameSite === 'Lax',
         description: 'SameSite stays the framework default Lax (never read from ini)'
      );
   }
);
