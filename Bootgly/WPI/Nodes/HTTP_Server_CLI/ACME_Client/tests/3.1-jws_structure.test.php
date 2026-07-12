<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Account;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\JWS;

return new Specification(
   description: 'ACME JWS: flattened JSON serialization, jwk/kid modes and signature validity',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-acme-jws-' . getmypid() . '/';

      $unpack = static function (string $segment): string {
         $base64 = strtr($segment, '-_', '+/');
         $remainder = strlen($base64) % 4;
         if ($remainder !== 0) {
            $base64 .= str_repeat('=', 4 - $remainder);
         }
         return (string) base64_decode($base64, true);
      };

      try {
         $Account = new Account($path, 2048);
         $JWS = new JWS($Account);

         // @ jwk-mode (newAccount)
         $body = $JWS->sign(
            URL: 'https://ca.example.test/acme/new-acct',
            nonce: 'nonce-1',
            payload: ['termsOfServiceAgreed' => true, 'contact' => ['mailto:admin@example.com']]
         );
         $decoded = json_decode($body, true);

         yield assert(
            assertion: is_array($decoded)
               && array_keys($decoded) === ['protected', 'payload', 'signature'],
            description: 'the body is flattened JSON with exactly protected/payload/signature'
         );

         $header = json_decode($unpack($decoded['protected']), true);

         yield assert(
            assertion: $header['alg'] === 'RS256'
               && $header['nonce'] === 'nonce-1'
               && $header['url'] === 'https://ca.example.test/acme/new-acct',
            description: 'the protected header carries alg, nonce and url'
         );
         yield assert(
            assertion: isset($header['jwk']) && isset($header['kid']) === false,
            description: 'jwk-mode embeds the public JWK and omits kid'
         );
         yield assert(
            assertion: $header['jwk'] === $Account->JWK,
            description: 'the embedded JWK matches the account JWK'
         );

         // @ Signature verifies over "protected.payload" with the account public key
         $public = $Account->Key->derive();
         $verified = openssl_verify(
            "{$decoded['protected']}.{$decoded['payload']}",
            $unpack($decoded['signature']),
            $public,
            OPENSSL_ALGO_SHA256
         );

         yield assert(
            assertion: $verified === 1,
            description: 'the RS256 signature verifies with the account public key'
         );

         // @ kid-mode (post-registration)
         $body = $JWS->sign(
            URL: 'https://ca.example.test/acme/new-order',
            nonce: 'nonce-2',
            payload: ['identifiers' => [['type' => 'dns', 'value' => 'example.com']]],
            kid: 'https://ca.example.test/acct/42'
         );
         $decoded = json_decode($body, true);
         $header = json_decode($unpack($decoded['protected']), true);

         yield assert(
            assertion: $header['kid'] === 'https://ca.example.test/acct/42'
               && isset($header['jwk']) === false,
            description: 'kid-mode carries the account URL and omits jwk'
         );

         // @ POST-as-GET: null payload → empty string
         $body = $JWS->sign(
            URL: 'https://ca.example.test/acme/order/1',
            nonce: 'nonce-3',
            payload: null,
            kid: 'https://ca.example.test/acct/42'
         );
         $decoded = json_decode($body, true);

         yield assert(
            assertion: $decoded['payload'] === '',
            description: 'POST-as-GET serializes the payload as the empty string'
         );

         // @ Challenge trigger: empty array → "{}" (JSON object, not array)
         $body = $JWS->sign(
            URL: 'https://ca.example.test/acme/chall/1',
            nonce: 'nonce-4',
            payload: [],
            kid: 'https://ca.example.test/acct/42'
         );
         $decoded = json_decode($body, true);

         yield assert(
            assertion: $unpack($decoded['payload']) === '{}',
            description: 'an empty payload array serializes as the empty JSON object'
         );
      }
      finally {
         @unlink("{$path}key.pem");
         @unlink("{$path}url");
         @rmdir($path);
      }
   }
);
