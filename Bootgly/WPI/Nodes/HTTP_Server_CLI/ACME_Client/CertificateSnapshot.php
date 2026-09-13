<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;


/**
 * One fully validated certificate generation.
 *
 * The generation and content digests bind the manifest selection to the exact
 * bytes workers later verify before applying their stream context.
 */
final readonly class CertificateSnapshot
{
   // * Config
   /** The manifest generation this snapshot was selected from. */
   public string $generation;
   /** The certificate chain PEM file path. */
   public string $certificate;
   /** The private key PEM file path; null when the key lives in the certificate file. */
   public null|string $key;
   /** SHA-256 digest of the certificate file bytes. */
   public string $certificateHash;
   /** SHA-256 digest of the key file bytes; null when the key lives in the certificate file. */
   public null|string $keyHash;
   /** The leaf certificate `notBefore` as a Unix timestamp. */
   public int $validFrom;
   /** The leaf certificate `notAfter` as a Unix timestamp. */
   public int $expires;
   /** Whether this is the temporary self-signed bootstrap certificate. */
   public bool $bootstrap;
   /** @var array<int,string> Parsed lowercase DNS SANs. */
   public array $domains;


   /** @param array<int,string> $domains Parsed lowercase DNS SANs. */
   public function __construct (
      string $generation,
      string $certificate,
      null|string $key,
      string $certificateHash,
      null|string $keyHash,
      int $validFrom,
      int $expires,
      bool $bootstrap,
      array $domains
   )
   {
      // * Config
      $this->generation = $generation;
      $this->certificate = $certificate;
      $this->key = $key;
      $this->certificateHash = $certificateHash;
      $this->keyHash = $keyHash;
      $this->validFrom = $validFrom;
      $this->expires = $expires;
      $this->bootstrap = $bootstrap;
      $this->domains = $domains;
   }

   /** @return array<string,bool|string> */
   public function secure (): array
   {
      // ? PHP's SSL context inherits verify_peer=true — on a server socket
      //   that requests a CLIENT certificate (accidental mTLS: browsers
      //   prompt for one). Explicit AutoTLS `options` override these.
      $context = [
         'local_cert'       => $this->certificate,
         'verify_peer'      => false,
         'verify_peer_name' => false,
      ];
      if ($this->key !== null) {
         $context['local_pk'] = $this->key;
      }

      return $context;
   }

   /** @return array{certificate:string,key:null|string} */
   public function hash (): array
   {
      return [
         'certificate' => $this->certificateHash,
         'key' => $this->keyHash
      ];
   }
}
