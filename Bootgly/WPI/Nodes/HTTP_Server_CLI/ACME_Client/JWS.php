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


use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use function json_encode;
use JsonException;
use RuntimeException;

use Bootgly\API\Security\JWT\Segments;
use Bootgly\API\Security\JWT\Signer;


/**
 * ACME JWS signer — RFC 7515 §7.2.2 flattened JSON serialization with the
 * RFC 8555 §6.2 protected header (`alg` + `nonce` + `url` + `jwk`/`kid`).
 *
 * The compact `JWT` facade is deliberately not reused here: it forces a
 * `typ: JWT` header and compact serialization, both of which ACME forbids.
 * Only the raw RS256 engine (`Signer`) and the base64url codec (`Segments`)
 * are shared.
 */
class JWS
{
   // * Config
   /**
    * The ACME account whose key signs every request.
    */
   public private(set) Account $Account;

   // * Metadata
   private Segments $Segments;
   private Signer $Signer;


   public function __construct (Account $Account)
   {
      // * Config
      $this->Account = $Account;

      // * Metadata
      $this->Segments = new Segments();
      $this->Signer = new Signer();
   }

   /**
    * Sign an ACME request body.
    *
    * Header mode follows RFC 8555 §6.2: `kid` (the account URL) once the
    * account exists; the embedded public `jwk` otherwise (newAccount).
    * A `null` payload produces the empty POST-as-GET payload (§6.3); an
    * empty array produces the empty JSON object (`{}`, challenge trigger).
    *
    * @param array<string,mixed>|null $payload
    *
    * @return string Ready `application/jose+json` body:
    *                `{"protected","payload","signature"}`.
    */
   public function sign (
      string $URL,
      string $nonce,
      null|array $payload,
      null|string $kid = null
   ): string
   {
      // ! Protected header — jwk-mode pre-registration, kid-mode after
      $header = [
         'alg' => 'RS256',
         'nonce' => $nonce,
         'url' => $URL
      ];
      if ($kid !== null) {
         $header['kid'] = $kid;
      }
      else {
         $header['jwk'] = $this->Account->JWK;
      }

      try {
         $protected = $this->Segments->pack(
            json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
         );

         // ?: POST-as-GET (null) → ""; empty payload ([]) → "{}"
         $body = match (true) {
            $payload === null => '',
            $payload === [] => $this->Segments->pack('{}'),
            default => $this->Segments->pack(
               json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            )
         };

         $signature = $this->Segments->pack(
            $this->Signer->seal("{$protected}.{$body}", $this->Account->Key)
         );

         // :
         return json_encode(
            [
               'protected' => $protected,
               'payload' => $body,
               'signature' => $signature
            ],
            JSON_THROW_ON_ERROR
         );
      }
      catch (JsonException $Exception) {
         throw new RuntimeException(
            "ACME JWS serialization failed: {$Exception->getMessage()}"
         );
      }
   }
}
