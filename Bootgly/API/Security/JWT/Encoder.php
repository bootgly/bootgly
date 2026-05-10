<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\JWT;


use const JSON_BIGINT_AS_STRING;
use const JSON_THROW_ON_ERROR;
use function base64_decode;
use function base64_encode;
use function is_array;
use function is_string;
use function json_decode;
use function rtrim;
use function str_repeat;
use function strlen;
use function strtr;
use JsonException;


/**
 * Compact JWT segment encoder and decoder.
 */
class Encoder
{
   /**
    * Encode binary data with base64url without padding.
    */
   public function pack (string $value): string
   {
      return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
   }

   /**
    * Decode base64url data with restored padding.
    */
   public function unpack (string $value): null|string
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

      return $decoded;
   }

   /**
    * Decode a JWT JSON segment into a strict associative array.
    *
    * @return array<string,mixed>|Failures
    */
   public function decode (string $segment, Failures $Failure): array|Failures
   {
      $json = $this->unpack($segment);
      if ($json === null) {
         return $Failure;
      }

      try {
         $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
      }
      catch (JsonException) {
         return Failures::JSON;
      }

      if (is_array($decoded) === false) {
         return $Failure;
      }

      /** @var array<string,mixed> $fields */
      $fields = [];
      foreach ($decoded as $name => $value) {
         if (is_string($name) === false) {
            return $Failure;
         }

         $fields[$name] = $value;
      }

      return $fields;
   }
}
