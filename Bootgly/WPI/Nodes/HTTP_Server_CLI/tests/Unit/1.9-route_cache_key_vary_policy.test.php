<?php


use Bootgly\ABI\Data\Language;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


return new Specification(
   description: 'It should isolate route-cache keys by authority and fail closed for unsupported Vary dimensions',
   test: new Assertions(Case: function (): Generator {
      $URIProperty = new ReflectionProperty(Request::class, 'URI');
      $RequestProperty = new ReflectionProperty(Response::class, 'Request');

      /** @param array<string,string|array<int,string>> $forwarded */
      $RequestFactory = static function (
         string $host,
         string $URI = '/resource',
         null|string|array $language = null,
         bool $includeLanguage = false,
         array $forwarded = []
      ) use ($URIProperty): Request {
         $Request = new Request;
         $Request->method = 'GET';
         $Request->protocol = 'HTTP/1.1';
         $URIProperty->setValue($Request, $URI);

         /** @var array<string,string|array<int,string>> $headers */
         $headers = ['host' => $host];
         if ($includeLanguage) {
            $headers['accept-language'] = $language ?? '';
         }
         foreach ($forwarded as $name => $value) {
            $headers[$name] = $value;
         }
         $Request->Header->adopt($headers);

         return $Request;
      };

      // # Primary-key isolation
      $TenantA = $RequestFactory('tenant-a.example.test:8443');
      $TenantAEquivalent = $RequestFactory('tenant-a.example.test:8443');
      $TenantB = $RequestFactory('tenant-b.example.test:8443');
      $TenantACase = $RequestFactory('TENANT-A.example.test:8443');
      $TenantAPort = $RequestFactory('tenant-a.example.test:9443');

      yield new Assertion(
         description: 'identical requests compose an identical cache key',
      )
         ->expect(Cache::compose($TenantA) === Cache::compose($TenantAEquivalent))
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'authority bytes, including case and port, isolate cache entries',
      )
         ->expect(
            Cache::compose($TenantA) !== Cache::compose($TenantB)
            && Cache::compose($TenantA) !== Cache::compose($TenantACase)
            && Cache::compose($TenantA) !== Cache::compose($TenantAPort)
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'raw forwarded selectors isolate pre-middleware cache entries',
      )
         ->expect(
            Cache::compose($RequestFactory('tenant-a.example.test'))
            !== Cache::compose($RequestFactory('tenant-a.example.test', forwarded: ['x-forwarded-proto' => 'http']))
            && Cache::compose($RequestFactory('tenant-a.example.test', forwarded: ['x-forwarded-proto' => 'http']))
            !== Cache::compose($RequestFactory('tenant-a.example.test', forwarded: ['x-forwarded-proto' => 'https']))
            && Cache::compose($RequestFactory('tenant-a.example.test', forwarded: ['x-forwarded-for' => '203.0.113.1']))
            !== Cache::compose($RequestFactory('tenant-a.example.test', forwarded: ['x-real-ip' => '203.0.113.1']))
         )
         ->to->be(true)
         ->assert();

      // # Length framing prevents boundary ambiguity between components
      $BoundaryA = $RequestFactory('a', '/bc');
      $BoundaryB = $RequestFactory('ab', '/c');
      yield new Assertion(
         description: 'component boundaries cannot collide through concatenation',
      )
         ->expect(Cache::compose($BoundaryA) !== Cache::compose($BoundaryB))
         ->to->be(true)
         ->assert();

      // # Supported request variance: exact Accept-Language field value
      $Missing = $RequestFactory('tenant.example.test');
      $Empty = $RequestFactory('tenant.example.test', language: '', includeLanguage: true);
      $English = $RequestFactory('tenant.example.test', language: 'en', includeLanguage: true);
      $Repeated = $RequestFactory('tenant.example.test', language: ['en', 'pt-BR'], includeLanguage: true);

      yield new Assertion(
         description: 'language-disabled keys do not fragment on Accept-Language',
      )
         ->expect(Cache::compose($Missing) === Cache::compose($English))
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'language-enabled keys distinguish absent, empty, scalar and repeated fields',
      )
         ->expect(count(array_unique([
            Cache::compose($Missing, true),
            Cache::compose($Empty, true),
            Cache::compose($English, true),
            Cache::compose($Repeated, true),
         ])) === 4)
         ->to->be(true)
         ->assert();

      // # Storage policy over the final serialized response header block
      /** @param array<string,string|array<int,string>> $forwarded */
      $Stash = static function (
         callable $Emit,
         bool $languages = false,
         string $language = '',
         array $forwarded = []
      ) use ($RequestFactory, $RequestProperty): bool {
         $roots = Language::$roots;
         Language::$roots = $languages ? ['/route-cache-policy-test'] : [];

         try {
            $Request = $RequestFactory(
               'tenant.example.test',
               language: $language,
               includeLanguage: $languages,
               forwarded: $forwarded
            );
            $Response = new Response;
            $RequestProperty->setValue($Response, $Request);
            $Response->cache = 60;
            $Emit($Response);

            Cache::flush();
            $Response->stash("HTTP/1.1 200 OK\r\n\r\nok");

            return Cache::$entries !== [];
         }
         finally {
            Cache::flush();
            Language::$roots = $roots;
         }
      };

      yield new Assertion(
         description: 'a response without Vary remains cacheable',
      )
         ->expect($Stash(static function (Response $Response): void {}))
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'custom Vary fields fail closed across set, prepare, queue and preset sources',
      )
         ->expect(
            $Stash(static function (Response $Response): void {
               $Response->Header->set('Vary', 'X-Tenant');
            }) === false
            && $Stash(static function (Response $Response): void {
               $Response->Header->prepare(['vArY' => 'X-Tenant']);
            }) === false
            && $Stash(static function (Response $Response): void {
               $Response->Header->queue('VARY', 'X-Tenant');
            }) === false
            && $Stash(static function (Response $Response): void {
               $Response->Header->preset('Vary', 'X-Tenant');
            }) === false
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Vary wildcard and mixed supported/unsupported tokens fail closed',
      )
         ->expect(
            $Stash(static function (Response $Response): void {
               $Response->Header->set('Vary', '*');
            }) === false
            && $Stash(static function (Response $Response): void {
               $Response->Header->set('Vary', 'Accept-Language, X-Tenant');
            }, languages: true, language: 'en') === false
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'manual Accept-Language variance without an active selector fails closed',
      )
         ->expect($Stash(static function (Response $Response): void {
            $Response->Header->vary('Accept-Language');
         }))
         ->to->be(false)
         ->assert();

      yield new Assertion(
         description: 'Accept-Language variance stores when the exact selector is active',
      )
         ->expect($Stash(static function (Response $Response): void {
            $Response->Header->vary('Accept-Language');
         }, languages: true, language: 'en'))
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'a masked preset Vary field does not block a representation that omits it',
      )
         ->expect($Stash(static function (Response $Response): void {
            $Response->Header->preset('Vary', 'X-Tenant');
            $Response->Header->remove('Vary');
         }))
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'the final-wire guard rejects bare CR/LF even below the validated preset API',
      )
         ->expect($Stash(static function (Response $Response): void {
            // ! Defense in depth: emulate legacy/corrupted internal state
            //   below preset(), whose public boundary now rejects this value.
            $Preset = new ReflectionProperty(Header::class, 'preset');
            $preset = $Response->Header->preset;
            $preset['X-Probe'] = "safe\nVary: X-Tenant";
            $Preset->setRawValue($Response->Header, $preset);
         }))
         ->to->be(false)
         ->assert();

      yield new Assertion(
         description: 'requests carrying any TrustedProxy input never seed the early route cache',
      )
         ->expect(
            $Stash(static function (Response $Response): void {}, forwarded: ['x-forwarded-proto' => 'https']) === false
            && $Stash(static function (Response $Response): void {}, forwarded: ['x-forwarded-for' => '203.0.113.1']) === false
            && $Stash(static function (Response $Response): void {}, forwarded: ['x-real-ip' => '203.0.113.1']) === false
         )
         ->to->be(true)
         ->assert();
   })
);
