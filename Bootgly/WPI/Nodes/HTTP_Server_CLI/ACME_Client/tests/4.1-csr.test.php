<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\CSR;

return new Specification(
   description: 'ACME CSR: SAN request, fresh key and base64url DER export',
   test: function () {
      $CSR = new CSR(['a.test', 'b.test'], 2048);

      // @ Subject
      $subject = openssl_csr_get_subject($CSR->Request);

      yield assert(
         assertion: ($subject['CN'] ?? null) === 'a.test',
         description: 'the CSR Common Name is the first domain'
      );

      // @ SAN extension present with every domain
      $exported = '';
      openssl_csr_export($CSR->Request, $exported, false);

      yield assert(
         assertion: str_contains($exported, 'DNS:a.test')
            && str_contains($exported, 'DNS:b.test'),
         description: 'the readable export lists every SAN domain'
      );

      // @ Fresh valid key, decoupled from any account key
      $Key = openssl_pkey_get_private($CSR->key);

      yield assert(
         assertion: $Key !== false
            && (openssl_pkey_get_details($Key)['bits'] ?? 0) >= 2048,
         description: 'the certificate key is a fresh private key >= 2048 bits'
      );

      // @ DER round-trip
      $base64 = strtr($CSR->DER, '-_', '+/');
      $remainder = strlen($base64) % 4;
      if ($remainder !== 0) {
         $base64 .= str_repeat('=', 4 - $remainder);
      }
      $DER = base64_decode($base64, true);
      $PEM = "-----BEGIN CERTIFICATE REQUEST-----\n"
         . chunk_split(base64_encode((string) $DER), 64)
         . "-----END CERTIFICATE REQUEST-----\n";
      $reparsed = openssl_csr_get_subject($PEM);

      yield assert(
         assertion: $DER !== false && ($reparsed['CN'] ?? null) === 'a.test',
         description: 'the DER export is base64url and re-parses to the same request'
      );

      // @ Low-level validation — the domains are interpolated into an
      //   OpenSSL configuration, so this public helper validates on its own
      $rejected = false;
      try {
         new CSR(["evil.test\nsubjectAltName = DNS:injected.test"]);
      }
      catch (RuntimeException) {
         $rejected = true;
      }

      yield assert(
         assertion: $rejected,
         description: 'a domain carrying configuration-injection bytes is rejected'
      );

      $rejected = false;
      try {
         new CSR(['weak.test'], 1024);
      }
      catch (RuntimeException) {
         $rejected = true;
      }
      yield assert(
         assertion: $rejected,
         description: 'the public CSR helper rejects RSA keys below 2048 bits'
      );
   }
);
