<?php

namespace Bootgly\API\Security\Tests\JWTRemoteCachePolicy;


use const JSON_THROW_ON_ERROR;
use function assert;
use function bin2hex;
use function function_exists;
use function hrtime;
use function is_dir;
use function json_encode;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function time;
use function unlink;
use function usleep;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\JWT\KeySet;
use Bootgly\API\Security\JWT\Remote;
use Bootgly\API\Security\JWT\Remote\Response;
use Bootgly\API\Security\JWT\Vault;


return new Test(
   description: 'JWT: constrain remote JWKS cache policy to the final response',
   skip: function_exists('openssl_pkey_get_details') === false
      || function_exists('openssl_pkey_get_private') === false
      || function_exists('openssl_pkey_get_public') === false,
   test: function () {
      /** @var array{first:array{body:string,jwk:array{kid:string},private:string,public:string},second:array{body:string,jwk:array{kid:string},private:string,public:string}} $fixtures */
      $fixtures = require __DIR__ . '/fixtures/jwt_rs256.php';
      $first = $fixtures['first'];
      $second = $fixtures['second'];
      $outcomes = [];
      $Record = static function (
         bool $passed,
         string $description,
         string $diagnostic = ''
      ) use (&$outcomes): void {
         $outcomes[] = [
            'passed' => $passed,
            'description' => $passed
               ? $description
               : "JWT-3 CONFIRMED: {$description}. {$diagnostic}",
         ];
      };

      // @ Header policy is final-response scoped. Both native raw header lines
      //   and associative custom-fetcher snapshots must have identical rules.
      $policies = [
         [
            'description' => 'JWT-3: an HSTS max-age cannot override the final Cache-Control policy',
            'headers' => [
               'Strict-Transport-Security: max-age=63072000; includeSubDomains',
               'Cache-Control: public, max-age=9',
            ],
            'ttl' => 30,
            'expected' => 9,
         ],
         [
            'description' => 'JWT-3: a shorter foreign max-age cannot reduce the final Cache-Control lifetime',
            'headers' => [
               'Strict-Transport-Security: max-age=1',
               'Cache-Control: public, max-age=9',
            ],
            'ttl' => 30,
            'expected' => 9,
         ],
         [
            'description' => 'raw Cache-Control fields are scanned past unrelated response headers',
            'headers' => [
               'Set-Cookie: session=x; Max-Age=31536000',
               'Cache-Control: public',
               'cache-control: must-revalidate, max-age=12',
            ],
            'ttl' => 30,
            'expected' => 12,
         ],
         [
            'description' => 'associative Cache-Control and Age fields compute remaining freshness',
            'headers' => [
               'Cache-Control' => 'public, max-age=20',
               'Age' => '7',
            ],
            'ttl' => 30,
            'expected' => 13,
         ],
         [
            'description' => 'no-store makes the JWKS response immediately stale',
            'headers' => [
               'Cache-Control' => 'public, max-age=30, no-store',
            ],
            'ttl' => 60,
            'expected' => 0,
         ],
         [
            'description' => 'no-cache wins over max-age tokens in foreign headers',
            'headers' => [
               'Strict-Transport-Security: max-age=63072000',
               'Cache-Control: no-cache',
            ],
            'ttl' => 60,
            'expected' => 0,
         ],
         [
            'description' => 'max-age zero disables JWKS freshness',
            'headers' => [
               'Cache-Control: public, max-age=0',
            ],
            'ttl' => 60,
            'expected' => 0,
         ],
         [
            'description' => 'an oversized origin max-age is clamped to the operator ttl',
            'headers' => [
               'Cache-Control: public, max-age=9223372036854775807',
            ],
            'ttl' => 11,
            'expected' => 11,
         ],
         [
            'description' => 'Age greater than max-age makes a JWKS response immediately stale',
            'headers' => [
               'cache-control' => 'max-age=5',
               'age' => '9',
            ],
            'ttl' => 60,
            'expected' => 0,
         ],
         [
            'description' => 'foreign max-age fields do not replace the configured fallback ttl',
            'headers' => [
               'Set-Cookie: id=x; Max-Age=31536000',
            ],
            'ttl' => 17,
            'expected' => 17,
         ],
         [
            'description' => 'conflicting Cache-Control max-age fields choose the shortest lifetime',
            'headers' => [
               'Cache-Control: public, max-age=25',
               'cache-control: must-revalidate, max-age=7',
            ],
            'ttl' => 60,
            'expected' => 7,
         ],
      ];

      foreach ($policies as $index => $policy) {
         try {
            $Remote = new Remote(
               "https://issuer.example/cache-policy/{$index}",
               static fn (): Response => new Response(200, $first['body'], $policy['headers']),
               ttl: $policy['ttl']
            );
            $Keys = $Remote->fetch();
            $actualTTL = $Remote->expires === 0
               ? 0
               : $Remote->expires - $Remote->fetched;
            $passed = $Keys instanceof KeySet
               && $Keys->get($first['jwk']['kid']) !== null
               && $actualTTL === $policy['expected'];
            $Record(
               $passed,
               $policy['description'],
               "expected ttl={$policy['expected']}; observed ttl={$actualTTL}"
            );
         }
         catch (Throwable $Exception) {
            $Record(
               false,
               $policy['description'],
               'threw ' . $Exception::class . ': ' . $Exception->getMessage()
            );
         }
      }

      $path = sys_get_temp_dir() . '/bootgly-jwt-remote-policy-' . bin2hex(random_bytes(8));
      $Clean = static function (string $path): void {
         if (is_dir($path) === false) {
            return;
         }

         $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
         );
         foreach ($Iterator as $Info) {
            $Info->isDir() ? rmdir($Info->getPathname()) : unlink($Info->getPathname());
         }
         rmdir($path);
      };

      try {
         // @ Zero-freshness directives must also prevent a shared Vault write.
         try {
            $uncachedURI = 'https://issuer.example/no-store-cache-policy';
            $uncachedCalls = 0;
            $UncachedA = new Remote(
               $uncachedURI,
               static function () use (&$uncachedCalls, $first): Response {
                  $uncachedCalls++;

                  return new Response(200, $first['body'], [
                     'Cache-Control: public, max-age=30, no-store',
                  ]);
               },
               ttl: 60
            );
            $UncachedA->cache(new Vault($path));
            $UncachedA->fetch();
            $UncachedB = new Remote(
               $uncachedURI,
               static function () use (&$uncachedCalls, $second): Response {
                  $uncachedCalls++;

                  return new Response(200, $second['body'], [
                     'Cache-Control: no-store',
                  ]);
               },
               ttl: 60
            );
            $UncachedB->cache(new Vault($path));
            $UncachedKeys = $UncachedB->fetch();
            $passed = $UncachedKeys instanceof KeySet
               && $UncachedKeys->get($second['jwk']['kid']) !== null
               && $UncachedKeys->get($first['jwk']['kid']) === null
               && $uncachedCalls === 2;
            $Record(
               $passed,
               'no-store prevents a JWKS response from entering the shared Vault',
               "origin calls={$uncachedCalls}; expected 2"
            );
         }
         catch (Throwable $Exception) {
            $Record(
               false,
               'no-store prevents a JWKS response from entering the shared Vault',
               'threw ' . $Exception::class . ': ' . $Exception->getMessage()
            );
         }

         // @ A shared cache reader inherits the writer's absolute deadline. It
         //   must not turn the remaining origin freshness into a new full ttl.
         try {
            $sharedURI = 'https://issuer.example/shared-cache-policy';
            $sharedCalls = 0;
            $SharedA = new Remote(
               $sharedURI,
               static function () use (&$sharedCalls, $first): Response {
                  $sharedCalls++;

                  return new Response(200, $first['body'], [
                     'Cache-Control: public, max-age=3',
                  ]);
               },
               ttl: 60
            );
            $SharedA->cache(new Vault($path));
            $Initial = $SharedA->fetch();

            $SharedB = new Remote(
               $sharedURI,
               static function () use (&$sharedCalls, $second): Response {
                  $sharedCalls++;

                  return new Response(200, $second['body'], [
                     'Cache-Control: public, max-age=3',
                  ]);
               },
               ttl: 60
            );
            $SharedB->cache(new Vault($path));
            $Shared = $SharedB->fetch();
            $deadline = $SharedA->expires;
            $readerDeadline = $SharedB->expires;
            $callsBefore = $sharedCalls;
            $sharedPassed = $Initial instanceof KeySet
               && $Shared instanceof KeySet
               && $Shared->get($first['jwk']['kid']) !== null
               && $readerDeadline === $deadline
               && $callsBefore === 1;

            $waitStarted = hrtime(true);
            while (time() < $deadline && hrtime(true) - $waitStarted < 5_000_000_000) {
               usleep(100000);
            }

            $Refetched = $SharedB->fetch();
            $refetchedPassed = $Refetched instanceof KeySet
               && $Refetched->get($second['jwk']['kid']) !== null
               && $Refetched->get($first['jwk']['kid']) === null
               && $sharedCalls === 2;
            $Record(
               $sharedPassed,
               'shared JWKS readers preserve the writer absolute expiry',
               "writer expiry={$deadline}; reader expiry={$readerDeadline}; origin calls before deadline={$callsBefore}; expected 1"
            );
            $Record(
               $refetchedPassed,
               'an expired shared JWKS deadline forces an origin refetch',
               "origin calls after deadline={$sharedCalls}; expected 2"
            );
         }
         catch (Throwable $Exception) {
            $diagnostic = 'threw ' . $Exception::class . ': ' . $Exception->getMessage();
            $Record(
               false,
               'shared JWKS readers preserve the writer absolute expiry',
               $diagnostic
            );
            $Record(
               false,
               'an expired shared JWKS deadline forces an origin refetch',
               $diagnostic
            );
         }

         // @ The corrected cache namespace/record format must not adopt an
         //   unversioned raw body left by a vulnerable worker or old release.
         try {
            $legacyURI = 'https://issuer.example/legacy-cache-policy';
            $legacyKey = "jwt:jwks:body:RS256:{$legacyURI}";
            $LegacyVault = new Vault($path);
            $LegacyVault->write($legacyKey, $first['body'], 60);
            $legacyCalls = 0;
            $Legacy = new Remote(
               $legacyURI,
               static function () use (&$legacyCalls, $second): Response {
                  $legacyCalls++;

                  return new Response(200, $second['body'], [
                     'Cache-Control: public, max-age=10',
                  ]);
               },
               ttl: 60
            );
            $Legacy->cache($LegacyVault);
            $LegacyKeys = $Legacy->fetch();
            $passed = $LegacyKeys instanceof KeySet
               && $LegacyKeys->get($second['jwk']['kid']) !== null
               && $LegacyKeys->get($first['jwk']['kid']) === null
               && $legacyCalls === 1;
            $Record(
               $passed,
               'legacy raw-body JWKS cache entries are ignored and replaced from origin',
               "origin calls={$legacyCalls}; expected 1"
            );
         }
         catch (Throwable $Exception) {
            $Record(
               false,
               'legacy raw-body JWKS cache entries are ignored and replaced from origin',
               'threw ' . $Exception::class . ': ' . $Exception->getMessage()
            );
         }

         // @ A v2 key is not sufficient by itself: malformed and expired
         //   envelopes are cache misses. Use no-store on the recovery response
         //   so an absent key proves the stale value was deleted, not replaced.
         $invalidRecords = [
            [
               'description' => 'a malformed v2 JWKS envelope is deleted and refetched',
               'scope' => 'malformed',
               'value' => '{"expires":',
            ],
            [
               'description' => 'an expired v2 JWKS envelope is deleted and refetched',
               'scope' => 'expired',
               'value' => json_encode([
                  'expires' => time() - 1,
                  'body' => $first['body'],
               ], JSON_THROW_ON_ERROR),
            ],
         ];

         foreach ($invalidRecords as $invalidRecord) {
            try {
               $URI = "https://issuer.example/{$invalidRecord['scope']}-v2-cache-policy";
               $key = "jwt:jwks:body:v2:RS256:{$URI}";
               $Vault = new Vault($path);
               $seeded = $Vault->write($key, $invalidRecord['value'], 60);
               $calls = 0;
               $Remote = new Remote(
                  $URI,
                  static function () use (&$calls, $second): Response {
                     $calls++;

                     return new Response(200, $second['body'], [
                        'Cache-Control: no-store',
                     ]);
                  },
                  ttl: 60
               );
               $Remote->cache($Vault);
               $Keys = $Remote->fetch();
               $deleted = $Vault->read($key) === null;
               $passed = $seeded
                  && $Keys instanceof KeySet
                  && $Keys->get($second['jwk']['kid']) !== null
                  && $Keys->get($first['jwk']['kid']) === null
                  && $calls === 1
                  && $deleted;
               $Record(
                  $passed,
                  $invalidRecord['description'],
                  'seeded=' . ($seeded ? 'yes' : 'no')
                     . "; origin calls={$calls}; deleted=" . ($deleted ? 'yes' : 'no')
               );
            }
            catch (Throwable $Exception) {
               $Record(
                  false,
                  $invalidRecord['description'],
                  'threw ' . $Exception::class . ': ' . $Exception->getMessage()
               );
            }
         }
      }
      finally {
         $Clean($path);
      }

      // @ Do not suspend the generator before every probe has finished and
      //   the real Vault fixture has been removed. This preserves all pre-fix
      //   diagnostics even when the suite stops after the failing case.
      foreach ($outcomes as $outcome) {
         yield assert(
            assertion: $outcome['passed'],
            description: $outcome['description']
         );
      }
   }
);
