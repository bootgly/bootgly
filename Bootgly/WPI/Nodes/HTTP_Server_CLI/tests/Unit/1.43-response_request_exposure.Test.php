<?php

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Plaintext;


/**
 * BG-15: the Request a Response answers is part of the Response's public
 * surface — deferred work reads its captured snapshot through it — while
 * writes stay the server's alone, and no response resource may take the
 * name of a publicly readable instance property (the property would win
 * every read) — while the framework's own built-ins still register on a
 * subclass that shadows one.
 */
return new Test(
   description: 'Response::$Request should be readable by the app, never writable by it, and never shadowed by a resource',
   test: new Assertions(Case: function (): Generator {
      $Response = new Response;
      $Request = new Request;
      (new ReflectionProperty(Response::class, 'Request'))->setValue($Response, $Request);

      // @@ A) Readable from outside the class
      $Seen = null;
      $error = '';
      try {
         $Seen = $Response->Request;
      }
      catch (Throwable $Throwable) {
         $error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      yield assert(
         assertion: $Seen === $Request,
         description: 'the app reads the Request through the Response — ' . ($error === '' ? 'ok' : $error)
      );

      // @@ B) Writes from outside the class stay refused
      $refused = '';
      try {
         $Response->Request = new Request;
      }
      catch (Error $Error) {
         $refused = $Error->getMessage();
      }

      yield assert(
         assertion: preg_match('/Cannot (modify|access) private/', $refused) === 1,
         description: 'a write from outside the class is refused — ' . var_export($refused, true)
      );

      // @@ C) A resource named after a publicly readable property is refused
      $factory = static fn (object $Context): never => throw new LogicException('never built');
      $guarded = '';
      try {
         $Response->Resources->define('Request', $factory);
      }
      catch (InvalidArgumentException $Exception) {
         $guarded = $Exception->getMessage();
      }

      yield assert(
         assertion: $guarded !== '',
         description: 'a resource named `Request` is refused at definition time — ' . var_export($guarded, true)
      );

      // @@ D) A private property never shadowed __get: its name stays free
      $allowed = '';
      try {
         $Response->Resources->define('Route', $factory);
      }
      catch (Throwable $Throwable) {
         $allowed = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      yield assert(
         assertion: $allowed === '',
         description: 'a resource named after a private property is still accepted — ' . ($allowed === '' ? 'ok' : $allowed)
      );

      // @@ E) An ordinary custom name keeps working
      $custom = '';
      try {
         $Response->Resources->define('Upstream', $factory);
      }
      catch (Throwable $Throwable) {
         $custom = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      yield assert(
         assertion: $custom === '',
         description: 'a custom resource name is accepted — ' . ($custom === '' ? 'ok' : $custom)
      );

      // @@ F) The mount path is guarded too
      $mounted = '';
      try {
         $Response->mount(new Plaintext($Response), 'Request');
      }
      catch (InvalidArgumentException $Exception) {
         $mounted = $Exception->getMessage();
      }

      yield assert(
         assertion: $mounted !== '',
         description: 'mounting a resource under `Request` is refused — ' . var_export($mounted, true)
      );

      // @@ G) A public STATIC property never answers instance access: free
      $static = '';
      try {
         $Response->Resources->define('deferredTimeout', $factory);
      }
      catch (Throwable $Throwable) {
         $static = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      yield assert(
         assertion: $static === '',
         description: 'a resource named after a public static property is accepted — ' . ($static === '' ? 'ok' : $static)
      );

      // @@ H) A protected property never shadowed __get either: free
      $protected = '';
      try {
         $Response->Resources->define('files', $factory);
      }
      catch (Throwable $Throwable) {
         $protected = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      yield assert(
         assertion: $protected === '',
         description: 'a resource named after a protected property is accepted — ' . ($protected === '' ? 'ok' : $protected)
      );

      // @@ I) The built-ins bypass the guard: a subclass shadowing one still constructs
      $shadowed = '';
      try {
         $Shadowing = new class extends Response {
            public string $JSON = 'shadowed';
         };
         $shadowed = $Shadowing->JSON;
      }
      catch (Throwable $Throwable) {
         $shadowed = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      yield assert(
         assertion: $shadowed === 'shadowed',
         description: 'a Response subclass declaring a public property named after a built-in resource constructs — ' . var_export($shadowed, true)
      );
   })
);
