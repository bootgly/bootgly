<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return new Specification(
   description: 'It should refuse to stash cookie-setting responses (any source, any casing) and ACME-reserved paths',
   test: new Assertions(Case: function (): Generator {
      $URIProperty = new ReflectionProperty(Request::class, 'URI');
      $RequestProperty = new ReflectionProperty(Response::class, 'Request');

      // ! One cacheable GET/HTTP/1.1 exchange per scenario; stash() decides
      $stash = static function (string $URI, callable $emit) use ($URIProperty, $RequestProperty): bool {
         $Response = new Response;
         $Request = new Request;
         $Request->method = 'GET';
         $Request->protocol = 'HTTP/1.1';
         $URIProperty->setValue($Request, $URI);
         $RequestProperty->setValue($Response, $Request);
         $Response->cache = 60;

         $emit($Response);

         Cache::flush();
         $Response->stash("HTTP/1.1 200 OK\r\n\r\nok");

         $stored = Cache::$entries !== [];
         Cache::flush();

         return $stored;
      };

      yield new Assertion(
         description: 'a plain response is stored',
      )
         ->expect($stash('/plain', static function (Response $Response): void {}))
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'a queue()-emitted cookie (Cookies::append — Session/Remember path) blocks storage',
      )
         ->expect($stash('/login', static function (Response $Response): void {
            $Response->Header->Cookies->append(new Cookie('session', 'SECRET'));
         }))
         ->to->be(false)
         ->assert();

      yield new Assertion(
         description: 'a set() Set-Cookie under different casing blocks storage',
      )
         ->expect($stash('/casing', static function (Response $Response): void {
            $Response->Header->set('SET-COOKIE', 'a=1');
         }))
         ->to->be(false)
         ->assert();

      yield new Assertion(
         description: 'a prepare()d Set-Cookie blocks storage',
      )
         ->expect($stash('/prepared', static function (Response $Response): void {
            $Response->Header->prepare(['Set-Cookie' => 'a=1']);
         }))
         ->to->be(false)
         ->assert();

      yield new Assertion(
         description: 'the reserved ACME HTTP-01 namespace is never stored',
      )
         ->expect($stash('/.well-known/acme-challenge/some-token', static function (Response $Response): void {}))
         ->to->be(false)
         ->assert();

      // @ Worker-persistent presets serialize on every response — they must
      //   block storage too
      yield new Assertion(
         description: 'a preset Set-Cookie blocks storage',
      )
         ->expect($stash('/preset', static function (Response $Response): void {
            $Response->Header->preset('Set-Cookie', 'session=SECRET');
         }))
         ->to->be(false)
         ->assert();

      // @ A preset masked by remove() is NOT serialized for this response,
      //   so it must not block an otherwise cookie-free cacheable response
      yield new Assertion(
         description: 'a masked (removed) preset Set-Cookie does not block storage',
      )
         ->expect($stash('/preset-masked', static function (Response $Response): void {
            $Response->Header->preset('Set-Cookie', 'session=SECRET');
            $Response->Header->remove('Set-Cookie');
         }))
         ->to->be(true)
         ->assert();
   })
);
