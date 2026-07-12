<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Account;

return new Specification(
   description: 'ACME Account: RFC 7638 §3.1 thumbprint test vector',
   test: function () {
      // ! The RFC 7638 §3.1 example JWK (RFC 7515 A.2 RSA key)
      $n = '0vx7agoebGcQSuuPiLJXZptN9nndrQmbXEps2aiAFbWhM78LhWx4cbbfAAtVT8'
         . '6zwu1RK7aPFFxuhDR1L6tSoc_BJECPebWKRXjBZCiFV4n3oknjhMstn64tZ_2W'
         . '-5JsGY4Hc5n9yBXArwl93lqt7_RN5w6Cf0h4QyQ5v-65YGjQR0_FDW2QvzqY36'
         . '8QQMicAtaSqzs8KJZgnYb9c7d0zgdAZHzu6qMQvRL5hajrn1n91CbOpbISD08q'
         . 'NLyrdkt-bFTWhAI4vMQFh6WeZu0fM4lFd2NcRwr3XPksINHaQ-G_xBniIqbw0L'
         . 's1jF44-csFCur-kEgU8awapJzKnqDKgw';

      $digest = Account::digest([
         'kty' => 'RSA',
         'n' => $n,
         'e' => 'AQAB'
      ]);

      yield assert(
         assertion: $digest === 'NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs',
         description: 'digest() reproduces the RFC 7638 §3.1 expected thumbprint'
      );

      // @ Optional members never join the digest (§3: required members only)
      $noisy = Account::digest([
         'kty' => 'RSA',
         'n' => $n,
         'e' => 'AQAB',
         'alg' => 'RS256',
         'kid' => '2011-04-29'
      ]);

      yield assert(
         assertion: $noisy === 'NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs',
         description: 'optional JWK members (alg, kid) are excluded from the digest'
      );
   }
);
