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


use function array_pop;
use function preg_match;


/**
 * ACME replay-nonce pool (RFC 8555 §6.5).
 *
 * Every response from the ACME server carries a fresh `Replay-Nonce` that is
 * harvested into this pool; each signed request consumes one. A dry pool
 * makes the client HEAD the `newNonce` endpoint; a `badNonce` rejection
 * clears the pool — after it, every pooled nonce is suspect.
 */
class Nonces
{
   // * Data
   /**
    * @var array<int,string>
    */
   private array $nonces = [];


   /**
    * Harvest a replay nonce into the pool — values outside the base64url
    * grammar are ignored (RFC 8555 §6.5.1).
    */
   public function store (string $nonce): void
   {
      if (preg_match('/^[A-Za-z0-9_\-]+$/', $nonce) === 1) {
         $this->nonces[] = $nonce;
      }
   }

   /**
    * Consume a nonce — null when the pool is dry.
    */
   public function take (): null|string
   {
      return array_pop($this->nonces);
   }

   /**
    * Drop every pooled nonce.
    */
   public function clear (): void
   {
      $this->nonces = [];
   }
}
