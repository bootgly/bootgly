<?php


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


return new Specification(
   description: 'It should match ordinary mixed literal/param routes without regex and keep regex semantics',
   test: new Assertions(Case: function (): Generator {
      $Router = new Router;
      $handler = function (Request $Request, Response $Response): Response {
         return $Response;
      };

      $Router->route('/users/:id', $handler, 'GET');            // simple: literal + param
      $Router->route('/users/:id/posts/:post', $handler, 'GET'); // simple: mixed
      $Router->route('/users/:id<int>/level', $handler, 'GET');  // complex: constraint
      $Router->route('/inline/:code(\d{3})', $handler, 'GET');   // complex: inline regex
      $Router->route('/dup/:a/:a', $handler, 'GET');             // complex: duplicate param
      $Router->route('/deep/:path*', $handler, 'GET');           // complex: catch-all param

      $RouterReflection = new ReflectionClass(Router::class);
      $RouterReflection->getMethod('flatten')->invoke($Router);
      $RouterReflection->getProperty('cached')->setValue($Router, true);

      // @ Classification: literals never force the PCRE path; only regex-
      //   bearing params (constraints, inline, catch-all) and duplicates do
      $types = [];
      foreach ($RouterReflection->getProperty('dynamicCache')->getValue($Router) as $bySegCount) {
         foreach ($bySegCount as $entries) {
            foreach ($entries as $entry) {
               $types[$entry['pattern']] = $entry['type'];
            }
         }
      }

      yield new Assertion(
         description: 'ordinary mixed routes are classified simple (segment matcher, no preg_match)',
      )
         ->expect(
            $types['/^\/users\/([^\/]+)$/'] === 'simple'
            && $types['/^\/users\/([^\/]+)\/posts\/([^\/]+)$/'] === 'simple'
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'constraint, inline-regex, duplicate and catch-all routes stay complex',
      )
         ->expect(
            $types['/^\/users\/([0-9]+)\/level$/'] === 'complex'
            && $types['/^\/inline\/(\d{3})$/'] === 'complex'
            && $types['/^\/dup\/([^\/]+)\/([^\/]+)$/'] === 'complex'
            && $types['/^\/deep\/(.+)$/'] === 'complex'
         )
         ->to->be(true)
         ->assert();

      // @ Matching behavior — resolve() reads the WPI facade
      $WPI = WPI;
      $Request = new Request;
      $Request->method = 'GET';
      $Request->protocol = 'HTTP/1.1';
      $URIProperty = new ReflectionProperty(Request::class, 'URI');
      $URLProperty = new ReflectionProperty(Request::class, '_URL');
      $WPI->Request = $Request;
      $WPI->Response = new Response;

      $resolve = static function (string $URI) use ($Router, $Request, $URIProperty, $URLProperty): array {
         $URIProperty->setValue($Request, $URI);
         $URLProperty->setValue($Request, null);
         $Result = $Router->resolve();

         $params = [];
         foreach ($Router->Route->Params as $name => $value) {
            $params[$name] = $value;
         }

         return [$Result instanceof Response, $params];
      };

      [$matched, $params] = $resolve('/users/123');
      yield new Assertion(
         description: 'a simple mixed route matches and extracts its param',
      )
         ->expect($matched === true && $params['id'] === '123')
         ->to->be(true)
         ->assert();

      [$matched, ] = $resolve('/users/999/level');
      yield new Assertion(
         description: 'a constrained route still matches through regex',
      )
         ->expect($matched)
         ->to->be(true)
         ->assert();

      [$matched, ] = $resolve('/users/abc/level');
      yield new Assertion(
         description: 'a constrained route still rejects non-matching values',
      )
         ->expect($matched)
         ->to->be(false)
         ->assert();

      [$matched, $params] = $resolve('/dup/x/y');
      yield new Assertion(
         description: 'duplicate params still accumulate into an array',
      )
         ->expect($matched === true && $params['a'] === ['x', 'y'])
         ->to->be(true)
         ->assert();

      [$matched, $params] = $resolve('/deep/a/b/c');
      yield new Assertion(
         description: 'catch-all params still capture the rest of the URL',
      )
         ->expect($matched === true && $params['path'] === 'a/b/c')
         ->to->be(true)
         ->assert();

      [$matched, ] = $resolve('/users//');
      yield new Assertion(
         description: 'a param never matches an empty segment (regex parity)',
      )
         ->expect($matched)
         ->to->be(false)
         ->assert();
   })
);
