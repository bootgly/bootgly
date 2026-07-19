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


/**
 * One fully validated certificate generation.
 *
 * The generation and content digests bind the manifest selection to the exact
 * bytes workers later verify before applying their stream context.
 */
final readonly class CertificateSnapshot
{
   /** @param array<int,string> $domains Parsed lowercase DNS SANs. */
   public function __construct (
      public string $generation,
      public string $certificate,
      public null|string $key,
      public string $certificateHash,
      public null|string $keyHash,
      public int $validFrom,
      public int $expires,
      public bool $bootstrap,
      public array $domains
   ) {}

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
