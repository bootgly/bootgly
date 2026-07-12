<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;


use const OPENSSL_KEYTYPE_RSA;
use function base64_decode;
use function file_put_contents;
use function implode;
use function is_string;
use function openssl_csr_export;
use function openssl_csr_new;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function preg_match;
use function preg_replace;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

use Bootgly\API\Security\JWT\Segments;


/**
 * Certificate Signing Request with SAN — the ACME order finalize payload.
 *
 * A fresh RSA key pair is generated per request (the certificate key is
 * never the account key). The SAN extension is injected through a temporary
 * openssl.cnf (the only way PHP's openssl binding accepts extensions).
 */
class CSR
{
   // * Config
   /**
    * SAN domain set; `domains[0]` is the Common Name.
    * @var array<int,string>
    */
   public private(set) array $domains;
   /**
    * RSA key size in bits.
    */
   public private(set) int $bits;

   // * Metadata
   /**
    * The certificate private key PEM (fresh per CSR).
    */
   public private(set) string $key {
      get {
         if (isSet($this->key) === false) {
            $this->build();
         }

         return $this->key;
      }
   }
   /**
    * The OpenSSL CSR handle.
    */
   public private(set) OpenSSLCertificateSigningRequest $Request {
      get {
         if (isSet($this->Request) === false) {
            $this->build();
         }

         return $this->Request;
      }
   }
   /**
    * base64url-encoded DER — the RFC 8555 §7.4 `csr` finalize field.
    */
   public private(set) string $DER {
      get {
         if (isSet($this->DER) === false) {
            $this->DER = $this->export();
         }

         return $this->DER;
      }
   }


   /**
    * @param array<int,string> $domains
    */
   public function __construct (array $domains, int $bits = 2048)
   {
      if ($domains === []) {
         throw new RuntimeException('ACME CSR requires at least one domain.');
      }
      if ($bits < 2048) {
         throw new RuntimeException('ACME CSR RSA keys must be at least 2048 bits.');
      }
      // ? This public helper validates independently of the AutoTLS facade —
      //   the domains are interpolated into an OpenSSL configuration
      //   (the exact facade grammar: total length, label sizes and edges)
      foreach ($domains as $domain) {
         if (
            preg_match(
               '/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/i',
               $domain
            ) !== 1
         ) {
            throw new RuntimeException(
               "ACME CSR domain `{$domain}` is not a valid hostname."
            );
         }
      }

      // * Config
      $this->domains = $domains;
      $this->bits = $bits;
   }

   /**
    * Generate the key pair and the SAN request.
    */
   private function build (): void
   {
      // ! Fresh certificate key pair
      $Generated = openssl_pkey_new([
         'private_key_bits' => $this->bits,
         'private_key_type' => OPENSSL_KEYTYPE_RSA
      ]);
      $PEM = '';
      if ($Generated === false || openssl_pkey_export($Generated, $PEM) === false) {
         throw new RuntimeException('ACME certificate key generation failed.');
      }

      // ! Temporary openssl.cnf carrying the SAN extension
      $names = [];
      foreach ($this->domains as $domain) {
         $names[] = "DNS:{$domain}";
      }
      $SAN = implode(',', $names);

      $configuration = tempnam(sys_get_temp_dir(), 'bootgly-acme-csr-');
      if ($configuration === false) {
         throw new RuntimeException('ACME CSR temporary configuration could not be created.');
      }

      try {
         $written = file_put_contents(
            $configuration,
            <<<INI
            [req]
            distinguished_name = req_distinguished_name
            req_extensions = v3_req
            [req_distinguished_name]
            [v3_req]
            subjectAltName = {$SAN}
            INI
         );
         if ($written === false) {
            throw new RuntimeException('ACME CSR temporary configuration could not be written.');
         }

         // ! CN only when it fits OpenSSL's 64-byte limit — SAN carries the
         //   identity; a longer primary name must not fail the request
         $subject = strlen($this->domains[0]) <= 64
            ? ['commonName' => $this->domains[0]]
            : [];

         $Request = openssl_csr_new(
            $subject,
            $Generated,
            [
               'digest_alg' => 'sha256',
               'config' => $configuration,
               'req_extensions' => 'v3_req'
            ]
         );
         if ($Request instanceof OpenSSLCertificateSigningRequest === false) {
            throw new RuntimeException('ACME CSR generation failed.');
         }

         // * Metadata
         $this->key = $PEM;
         $this->Request = $Request;
      }
      finally {
         unlink($configuration);
      }
   }

   /**
    * Export the request as base64url DER.
    */
   private function export (): string
   {
      $exported = '';
      if (openssl_csr_export($this->Request, $exported) === false || is_string($exported) === false) {
         throw new RuntimeException('ACME CSR export failed.');
      }

      // @ PEM armor → raw DER
      $base64 = preg_replace('/-----[^-]+-----|\s/', '', $exported);
      $DER = $base64 !== null ? base64_decode($base64, true) : false;
      if ($DER === false) {
         throw new RuntimeException('ACME CSR DER decoding failed.');
      }

      // :
      return new Segments()->pack($DER);
   }
}
