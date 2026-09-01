<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;


/**
 * PoC — `Session::init()` replaced a policy declared at boot with `php.ini`.
 *
 * `init()` runs once, at the first Session of the process, and copied
 *   `session.gc_maxlifetime`, `session.gc_probability`/`gc_divisor` and
 *   `session_get_cookie_params()` over the statics unconditionally:
 *
 *     self::$lifetime = (int) ini_get('session.gc_maxlifetime');
 *     static::$cookieLifetime = $sessionCookieParams['lifetime'];
 *
 *   So `Session::$lifetime = 604800;` written in the project boot was silently
 *   reset to 1440 (24 minutes) at the first request, while the statics init()
 *   never touched (`$name`, `$autoUpdateTimestamp`) obeyed the assignment —
 *   half of the configuration surface honoured the user, half discarded it.
 *
 * Fixed behaviour: `php.ini` fills only the statics still holding their
 *   declared default. A static assigned before the first Session keeps its
 *   value; an untouched one still takes the ini value, as before.
 *
 * The probe points every ini source at values that differ from both the
 *   declared defaults and the boot-time policy, then re-runs `init()` twice:
 *   once with the policy assigned (it must survive), once from the declared
 *   defaults (ini must still fill them).
 */

return new Test(
   description: 'Session::init() keeps statics assigned at boot; php.ini fills only untouched ones',
   test: function () {
      $Class = new ReflectionClass(Session::class);
      $Initialized = $Class->getProperty('initialized');
      $Init = $Class->getMethod('init');

      // ! Snapshot everything the probe mutates — the suite shares the process.
      $statics = ['lifetime', 'cookieLifetime', 'cookiePath', 'domain', 'gcProbability'];
      $savedStatics = [];
      foreach ($statics as $static) {
         $savedStatics[$static] = $Class->getStaticPropertyValue($static);
      }
      $savedInitialized = $Initialized->getValue();
      $inis = ['session.gc_maxlifetime', 'session.gc_probability', 'session.gc_divisor'];
      $savedInis = [];
      foreach ($inis as $ini) {
         $savedInis[$ini] = (string) ini_get($ini);
      }
      $savedCookie = array_intersect_key(
         session_get_cookie_params(),
         array_flip(['lifetime', 'path', 'domain', 'secure', 'httponly', 'samesite'])
      );

      try {
         // ! php.ini sources distinct from both the declared defaults and the policy.
         ini_set('session.gc_maxlifetime', '777');
         ini_set('session.gc_probability', '3');
         ini_set('session.gc_divisor', '50');
         $applied = session_set_cookie_params([
            'lifetime' => 99,
            'path' => '/ini',
            'domain' => 'ini.example',
         ]);
         yield assert(
            assertion: $applied === true,
            description: 'probe precondition: the cookie params reach php.ini (headers not sent)'
         );

         // @ A policy assigned before the first Session — the project boot.
         Session::$lifetime = 604800;
         Session::$cookieLifetime = 604800;
         Session::$cookiePath = '/app';
         Session::$domain = 'app.example';
         Session::$gcProbability = [1, 500];

         $Initialized->setValue(null, false);
         $Init->invoke(null);

         yield assert(
            assertion: Session::$lifetime === 604800,
            description: '$lifetime assigned at boot survives init() (php.ini says 777)'
         );
         yield assert(
            assertion: Session::$cookieLifetime === 604800,
            description: '$cookieLifetime assigned at boot survives init() (php.ini says 99)'
         );
         yield assert(
            assertion: Session::$cookiePath === '/app',
            description: '$cookiePath assigned at boot survives init() (php.ini says /ini)'
         );
         yield assert(
            assertion: Session::$domain === 'app.example',
            description: '$domain assigned at boot survives init() (php.ini says ini.example)'
         );
         yield assert(
            assertion: Session::$gcProbability === [1, 500],
            description: '$gcProbability assigned at boot survives init() (php.ini says 3/50)'
         );
         yield assert(
            assertion: $Initialized->getValue() === true,
            description: 'init() still marks the class initialized'
         );

         // @ Untouched statics — back at their declared defaults — still take php.ini.
         foreach ($statics as $static) {
            $Class->setStaticPropertyValue(
               $static,
               $Class->getProperty($static)->getDefaultValue()
            );
         }
         $Initialized->setValue(null, false);
         $Init->invoke(null);

         yield assert(
            assertion: Session::$lifetime === 777,
            description: 'untouched $lifetime still takes session.gc_maxlifetime'
         );
         yield assert(
            assertion: Session::$cookieLifetime === 99,
            description: 'untouched $cookieLifetime still takes the ini cookie lifetime'
         );
         yield assert(
            assertion: Session::$cookiePath === '/ini',
            description: 'untouched $cookiePath still takes the ini cookie path'
         );
         yield assert(
            assertion: Session::$domain === 'ini.example',
            description: 'untouched $domain still takes the ini cookie domain'
         );
         yield assert(
            assertion: Session::$gcProbability === [3, 50],
            description: 'untouched $gcProbability still takes gc_probability/gc_divisor'
         );
      }
      finally {
         // ! Restore the shared process state for the tests that follow.
         foreach ($savedStatics as $static => $value) {
            $Class->setStaticPropertyValue($static, $value);
         }
         $Initialized->setValue(null, $savedInitialized);
         foreach ($savedInis as $ini => $value) {
            ini_set($ini, $value);
         }
         session_set_cookie_params($savedCookie);
      }
   }
);
