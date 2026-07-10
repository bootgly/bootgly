<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security;


use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function is_string;
use function rtrim;
use function str_repeat;
use function strlen;
use function strtr;

use Bootgly\API\Security\Encrypter\Key;
use Bootgly\API\Security\Encrypter\Keyring;


/**
 * Authenticated symmetric encryption (AES-256-GCM) with key rotation.
 *
 * Payloads are sealed into portable `v1.<kid>.<blob>` envelopes whose
 * version and key id segments are authenticated together with the
 * caller-provided Additional Authenticated Data. Envelopes are canonical:
 * a given sealed payload has exactly one accepted textual form. The GCM
 * invariants (fresh 12-byte IV, full 16-byte tag) are owned by `Key`.
 */
class Encrypter
{
   // * Config
   /**
    * Encryption keys: the primary encrypts, every registered key decrypts.
    */
   public private(set) Keyring $Keyring;

   // * Data
   // ...

   // * Metadata
   /**
    * Envelope format version.
    */
   private const string VERSION = 'v1';


   /**
    * Create an encrypter from raw 32-byte key material, a Key or a Keyring.
    *
    * @throws \InvalidArgumentException When raw key material is invalid.
    * @throws \RuntimeException When OpenSSL symmetric encryption is unavailable.
    */
   public function __construct (#[\SensitiveParameter] string|Key|Keyring $key)
   {
      // * Config
      $this->Keyring = match (true) {
         is_string($key) => new Keyring(new Key($key)),
         $key instanceof Key => new Keyring($key),
         default => $key
      };
   }

   /**
    * Encrypt a payload into a portable envelope.
    *
    * @throws \Random\RandomException When the randomness source fails.
    * @throws \RuntimeException When the OpenSSL encryption fails.
    */
   public function encrypt (#[\SensitiveParameter] string $plaintext, string $AAD = ''): string
   {
      // ! Primary key and authenticated envelope prefix.
      $Key = $this->Keyring->Primary;
      $id = $Key->id ?? '';
      $prefix = self::VERSION . ".{$id}.";

      // @ Seal — the envelope prefix is authenticated alongside the caller AAD.
      $sealed = $Key->seal($plaintext, "{$prefix}{$AAD}");

      // : Versioned envelope: v1.<kid>.<base64url(IV ∥ ciphertext ∥ tag)>.
      return $prefix . $this->pack($sealed);
   }

   /**
    * Decrypt an envelope. Returns null on any failure — no reason is disclosed.
    */
   public function decrypt (string $ciphertext, string $AAD = ''): null|string
   {
      // ? Envelope must have exactly 3 segments and a supported version
      $segments = explode('.', $ciphertext);
      if (count($segments) !== 3 || $segments[0] !== self::VERSION) {
         return null;
      }

      // ? Key id must resolve to a registered key
      $id = $segments[1] === '' ? null : $segments[1];
      $Key = $this->Keyring->resolve($id);
      if ($Key === null) {
         return null;
      }

      // ? Blob must decode canonically
      $sealed = $this->unpack($segments[2]);
      if ($sealed === null) {
         return null;
      }

      // @ Open — the envelope prefix is authenticated alongside the caller AAD.
      $prefix = self::VERSION . ".{$segments[1]}.";

      // ?: Framing and authentication failures yield null
      return $Key->open($sealed, "{$prefix}{$AAD}");
   }

   /**
    * Encode binary data with base64url without padding.
    */
   private function pack (string $value): string
   {
      return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
   }

   /**
    * Decode base64url data, accepting only the canonical unpadded form.
    */
   private function unpack (string $value): null|string
   {
      $base64 = strtr($value, '-_', '+/');
      $remainder = strlen($base64) % 4;
      if ($remainder !== 0) {
         $padding = str_repeat('=', 4 - $remainder);
         $base64 = "{$base64}{$padding}";
      }

      $decoded = base64_decode($base64, true);
      if (is_string($decoded) === false) {
         return null;
      }

      // ? Canonical form only — alternate encodings of the same bytes are rejected
      if ($this->pack($decoded) !== $value) {
         return null;
      }

      return $decoded;
   }
}
