<?php


use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit\Algorithms;


/**
 * Regression for audit M2: independent policies must not inherit another
 * policy's counters, expiry metadata, or aggregate ceiling. Automatic scopes
 * must remain stable when worker-local route sets construct the same policy at
 * the same source location, while explicit scopes provide deliberate sharing.
 */
return new Test(
   description: 'RateLimit isolates policy counters and shares only explicit stable scopes',
   test: new Assertions(Case: function (): Generator {
      $CreateMocks = require __DIR__ . '/0.mock.php';
      $Passthrough = static function (object $Request, object $Response): object {
         return $Response;
      };
      $Clock = new Clock(10_000);
      $Now = static fn (): float => $Clock->now;

      $MakeCache = static function (string $label) use ($Clock): Cache {
         $dir = sys_get_temp_dir()
            . "/bootgly-ratelimit-m2-{$label}-"
            . uniqid('', true);
         $Cache = new Cache([
            'driver' => 'file',
            'path' => $dir,
            'prefix' => 'ratelimit:',
            'clock' => static fn (): int => (int) $Clock->now,
         ]);
         $Cache->clear();

         return $Cache;
      };

      $Send = static function (
         RateLimit $RateLimit,
         string $principal,
      ) use ($CreateMocks, $Passthrough): int {
         [$Request, $Response] = $CreateMocks(requestProps: ['peer' => $principal]);
         $Result = $RateLimit->process($Request, $Response, $Passthrough);

         return $Result->code;
      };

      // # Automatic identity: the same new-expression callsite is stable.
      $Cache = $MakeCache('automatic-same-callsite');
      try {
         $Construct = static function (Cache $Cache) use ($Now): RateLimit {
            return new RateLimit(
               limit: 1,
               window: 60,
               algorithm: Algorithms::Fixed,
               clock: $Now,
               Cache: $Cache,
            );
         };

         $AutomaticA = $Construct($Cache);
         $AutomaticB = $Construct($Cache);

         yield new Assertion(
            description: 'Repeated construction at one source location derives one stable automatic scope',
         )
            ->expect([
               $AutomaticA->scope === $AutomaticB->scope,
               str_starts_with($AutomaticA->scope, 'auto:'),
            ])
            ->to->be([true, true])
            ->assert();

         $codes = [
            $Send($AutomaticA, 'automatic-same-principal'),
            $Send($AutomaticB, 'automatic-same-principal'),
         ];
         yield new Assertion(
            description: 'Automatic scopes from one callsite share the same principal counter',
         )
            ->expect($codes)
            ->to->be([200, 429])
            ->assert();

         $Reflection = new ReflectionClass(RateLimit::class);
         $Reflected = $Reflection->newInstance();
         yield new Assertion(
            description: 'Reflective or DI construction finds the first usable external callsite',
         )
            ->expect(str_starts_with($Reflected->scope, 'auto:'))
            ->to->be(true)
            ->assert();
      }
      finally {
         $Cache->clear();
      }

      // # Automatic identity: distinct new-expression callsites are isolated.
      $Cache = $MakeCache('automatic-distinct-callsites');
      try {
         $AutomaticA = new RateLimit(
            limit: 1,
            window: 60,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
         );
         $AutomaticB = new RateLimit(
            limit: 1,
            window: 60,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
         );

         yield new Assertion(
            description: 'Different source locations derive distinct automatic scopes',
         )
            ->expect($AutomaticA->scope !== $AutomaticB->scope)
            ->to->be(true)
            ->assert();

         $codes = [
            $Send($AutomaticA, 'automatic-distinct-principal'),
            $Send($AutomaticB, 'automatic-distinct-principal'),
            $Send($AutomaticA, 'automatic-distinct-principal'),
            $Send($AutomaticB, 'automatic-distinct-principal'),
         ];
         yield new Assertion(
            description: 'Different automatic scopes enforce independent principal quotas',
         )
            ->expect($codes)
            ->to->be([200, 200, 429, 429])
            ->assert();
      }
      finally {
         $Cache->clear();
      }

      // # Explicit scope validation and documented 256-byte boundary.
      $Cache = $MakeCache('validation');
      try {
         $boundary = str_repeat('s', 256);
         $Bounded = new RateLimit(
            Cache: $Cache,
            scope: $boundary,
            globalScope: $boundary,
         );
         yield new Assertion(
            description: 'Explicit principal and global scopes accept and preserve 256 non-whitespace bytes',
         )
            ->expect([$Bounded->scope, $Bounded->globalScope])
            ->to->be([$boundary, $boundary])
            ->assert();

         $DefaultGlobal = new RateLimit(
            Cache: $Cache,
            scope: 'm2-explicit-default-global',
         );
         yield new Assertion(
            description: 'An omitted globalScope deliberately inherits the validated principal scope',
         )
            ->expect($DefaultGlobal->globalScope)
            ->to->be('m2-explicit-default-global')
            ->assert();

         $Reject = static function (
            Cache $Cache,
            null|string $scope,
            null|string $globalScope = null,
         ): string {
            try {
               $RateLimit = new RateLimit(
                  Cache: $Cache,
                  scope: $scope,
                  globalScope: $globalScope,
               );

               return "accepted:{$RateLimit->scope}:{$RateLimit->globalScope}";
            }
            catch (InvalidArgumentException $Exception) {
               return $Exception->getMessage();
            }
         };

         $scopeError = 'RateLimit scope must contain between 1 and 256 non-whitespace bytes.';
         $scopeErrors = [
            $Reject($Cache, ''),
            $Reject($Cache, " \t\n"),
            $Reject($Cache, str_repeat('s', 257)),
         ];
         yield new Assertion(
            description: 'Explicit principal scopes reject empty, whitespace-only, and oversized values',
         )
            ->expect($scopeErrors)
            ->to->be([$scopeError, $scopeError, $scopeError])
            ->assert();

         $globalError = 'RateLimit globalScope must contain between 1 and 256 non-whitespace bytes.';
         $globalErrors = [
            $Reject($Cache, 'm2-valid-principal', ''),
            $Reject($Cache, 'm2-valid-principal', " \t\n"),
            $Reject($Cache, 'm2-valid-principal', str_repeat('g', 257)),
         ];
         yield new Assertion(
            description: 'Explicit global scopes reject empty, whitespace-only, and oversized values',
         )
            ->expect($globalErrors)
            ->to->be([$globalError, $globalError, $globalError])
            ->assert();
      }
      finally {
         $Cache->clear();
      }

      // # Explicit principal scopes isolate by default and share by opt-in.
      $Cache = $MakeCache('principal-sharing');
      try {
         $DistinctA = new RateLimit(
            limit: 1,
            window: 60,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-principal-distinct-a',
         );
         $DistinctB = new RateLimit(
            limit: 1,
            window: 60,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-principal-distinct-b',
         );
         $distinctCodes = [
            $Send($DistinctA, 'explicit-distinct-principal'),
            $Send($DistinctB, 'explicit-distinct-principal'),
            $Send($DistinctA, 'explicit-distinct-principal'),
            $Send($DistinctB, 'explicit-distinct-principal'),
         ];
         yield new Assertion(
            description: 'Distinct explicit principal scopes retain independent counters',
         )
            ->expect($distinctCodes)
            ->to->be([200, 200, 429, 429])
            ->assert();

         $SharedA = new RateLimit(
            limit: 2,
            window: 60,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-principal-shared',
         );
         $SharedB = new RateLimit(
            limit: 2,
            window: 60,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-principal-shared',
         );
         $sharedCodes = [
            $Send($SharedA, 'explicit-shared-principal'),
            $Send($SharedB, 'explicit-shared-principal'),
            $Send($SharedA, 'explicit-shared-principal'),
            $Send($SharedB, 'explicit-shared-principal'),
         ];
         yield new Assertion(
            description: 'A common explicit principal scope shares one counter across instances',
         )
            ->expect($sharedCodes)
            ->to->be([200, 200, 429, 429])
            ->assert();
      }
      finally {
         $Cache->clear();
      }

      // # A shared label never mixes incompatible fixed-window semantics.
      $Cache = $MakeCache('principal-window-semantics');
      try {
         $Short = new RateLimit(
            limit: 100,
            window: 1,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-principal-window-shared',
         );
         $Strict = new RateLimit(
            limit: 3,
            window: 60,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-principal-window-shared',
         );
         $semanticCodes = [
            $Send($Short, 'semantic-principal'),
            $Send($Strict, 'semantic-principal'),
            $Send($Strict, 'semantic-principal'),
            $Send($Strict, 'semantic-principal'),
         ];
         $Clock->advance(2);
         $semanticCodes[] = $Send($Short, 'semantic-principal');
         $semanticCodes[] = $Send($Strict, 'semantic-principal');

         yield new Assertion(
            description: 'Different principal windows cannot transfer TTL ownership through a shared scope',
         )
            ->expect($semanticCodes)
            ->to->be([200, 200, 200, 200, 200, 429])
            ->assert();
      }
      finally {
         $Cache->clear();
      }

      // # Global counters also partition incompatible windows.
      $Cache = $MakeCache('global-window-semantics');
      try {
         $GlobalShort = new RateLimit(
            limit: 100,
            window: 1,
            globalLimit: 100,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-global-window-short-principal',
            globalScope: 'm2-global-window-shared',
         );
         $GlobalStrict = new RateLimit(
            limit: 100,
            window: 60,
            globalLimit: 3,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-global-window-strict-principal',
            globalScope: 'm2-global-window-shared',
         );
         $semanticCodes = [
            $Send($GlobalShort, 'semantic-global-prime-1'),
            $Send($GlobalStrict, 'semantic-global-strict-1'),
            $Send($GlobalStrict, 'semantic-global-strict-2'),
            $Send($GlobalStrict, 'semantic-global-strict-3'),
         ];
         $Clock->advance(2);
         $semanticCodes[] = $Send($GlobalShort, 'semantic-global-prime-2');
         $semanticCodes[] = $Send($GlobalStrict, 'semantic-global-strict-4');

         yield new Assertion(
            description: 'Different global windows cannot transfer TTL ownership through a shared globalScope',
         )
            ->expect($semanticCodes)
            ->to->be([200, 200, 200, 200, 200, 429])
            ->assert();
      }
      finally {
         $Cache->clear();
      }

      // # Aggregate scopes are independent unless explicitly shared.
      $Cache = $MakeCache('global-sharing');
      try {
         $DefaultGlobalA = new RateLimit(
            limit: 100,
            window: 60,
            globalLimit: 1,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-default-global-principal-a',
         );
         $DefaultGlobalB = new RateLimit(
            limit: 100,
            window: 60,
            globalLimit: 1,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-default-global-principal-b',
         );
         $defaultGlobalCodes = [
            $Send($DefaultGlobalA, 'default-global-a-1'),
            $Send($DefaultGlobalB, 'default-global-b-1'),
            $Send($DefaultGlobalA, 'default-global-a-2'),
            $Send($DefaultGlobalB, 'default-global-b-2'),
         ];
         yield new Assertion(
            description: 'Omitted globalScope inherits each distinct principal scope behaviorally',
         )
            ->expect($defaultGlobalCodes)
            ->to->be([200, 200, 429, 429])
            ->assert();

         $DistinctGlobalA = new RateLimit(
            limit: 100,
            window: 60,
            globalLimit: 1,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-distinct-global-principal-a',
            globalScope: 'm2-global-distinct-a',
         );
         $DistinctGlobalB = new RateLimit(
            limit: 100,
            window: 60,
            globalLimit: 1,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-distinct-global-principal-b',
            globalScope: 'm2-global-distinct-b',
         );
         $distinctGlobalCodes = [
            $Send($DistinctGlobalA, 'distinct-global-a-1'),
            $Send($DistinctGlobalB, 'distinct-global-b-1'),
            $Send($DistinctGlobalA, 'distinct-global-a-2'),
            $Send($DistinctGlobalB, 'distinct-global-b-2'),
         ];
         yield new Assertion(
            description: 'Distinct global scopes enforce independent aggregate ceilings',
         )
            ->expect($distinctGlobalCodes)
            ->to->be([200, 200, 429, 429])
            ->assert();

         $SharedGlobalA = new RateLimit(
            limit: 100,
            window: 60,
            globalLimit: 2,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-shared-global-principal-a',
            globalScope: 'm2-global-shared',
         );
         $SharedGlobalB = new RateLimit(
            limit: 100,
            window: 60,
            globalLimit: 2,
            algorithm: Algorithms::Fixed,
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-shared-global-principal-b',
            globalScope: 'm2-global-shared',
         );
         $sharedGlobalCodes = [
            $Send($SharedGlobalA, 'shared-global-a-1'),
            $Send($SharedGlobalB, 'shared-global-b-1'),
            $Send($SharedGlobalA, 'shared-global-a-2'),
            $Send($SharedGlobalB, 'shared-global-b-2'),
         ];
         yield new Assertion(
            description: 'A common globalScope shares one aggregate ceiling across distinct principal scopes',
         )
            ->expect([
               $SharedGlobalA->scope !== $SharedGlobalB->scope,
               $SharedGlobalA->globalScope === $SharedGlobalB->globalScope,
               $sharedGlobalCodes,
            ])
            ->to->be([true, true, [200, 200, 429, 429]])
            ->assert();

         $DomainSeparated = new RateLimit(
            limit: 100,
            window: 60,
            globalLimit: 2,
            algorithm: Algorithms::Fixed,
            key: static fn (object $Request): string => '__global__',
            clock: $Now,
            Cache: $Cache,
            scope: 'm2-domain-separated',
            globalScope: 'm2-domain-separated',
         );
         $domainCodes = [
            $Send($DomainSeparated, 'domain-separation-1'),
            $Send($DomainSeparated, 'domain-separation-2'),
            $Send($DomainSeparated, 'domain-separation-3'),
         ];
         yield new Assertion(
            description: 'A custom __global__ principal cannot alias the aggregate counter domain',
         )
            ->expect($domainCodes)
            ->to->be([200, 200, 429])
            ->assert();
      }
      finally {
         $Cache->clear();
      }
   })
);
