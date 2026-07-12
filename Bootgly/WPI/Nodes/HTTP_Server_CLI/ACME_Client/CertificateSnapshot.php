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

   /** @return array<string,string> */
   public function secure (): array
   {
      $context = ['local_cert' => $this->certificate];
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
