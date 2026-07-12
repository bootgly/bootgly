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


use function is_string;
use InvalidArgumentException;


/**
 * ACME directory — the validated endpoint map (RFC 8555 §7.1.1).
 *
 * Pure validation of an already-decoded directory JSON object; fetching it
 * is the `ACME_Client` orchestrator's job.
 */
class Directory
{
   // * Config
   /**
    * `newAccount` endpoint URL.
    */
   public private(set) string $newAccount;
   /**
    * `newNonce` endpoint URL.
    */
   public private(set) string $newNonce;
   /**
    * `newOrder` endpoint URL.
    */
   public private(set) string $newOrder;
   /**
    * `revokeCert` endpoint URL — optional in some test CAs.
    */
   public private(set) null|string $revokeCert;


   /**
    * @param array<string,mixed> $endpoints Decoded directory JSON.
    *
    * @throws InvalidArgumentException When a required endpoint is missing.
    */
   public function __construct (array $endpoints)
   {
      $newAccount = $endpoints['newAccount'] ?? null;
      $newNonce = $endpoints['newNonce'] ?? null;
      $newOrder = $endpoints['newOrder'] ?? null;
      if (is_string($newAccount) === false) {
         throw new InvalidArgumentException(
            'Invalid ACME directory: missing the `newAccount` endpoint.'
         );
      }
      if (is_string($newNonce) === false) {
         throw new InvalidArgumentException(
            'Invalid ACME directory: missing the `newNonce` endpoint.'
         );
      }
      if (is_string($newOrder) === false) {
         throw new InvalidArgumentException(
            'Invalid ACME directory: missing the `newOrder` endpoint.'
         );
      }

      // * Config
      $this->newAccount = $newAccount;
      $this->newNonce = $newNonce;
      $this->newOrder = $newOrder;
      $revokeCert = $endpoints['revokeCert'] ?? null;
      $this->revokeCert = is_string($revokeCert) ? $revokeCert : null;
   }
}
