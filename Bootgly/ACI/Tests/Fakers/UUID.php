<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Fakers;


use function bin2hex;
use function chr;
use function ord;

use Bootgly\ACI\Tests\Faker;


/**
 * RFC 4122 v4 UUID — deterministic when seeded.
 */
final class UUID extends Faker
{
   /**
    * Generate one RFC 4122 version 4 UUID string.
    */
   public function generate (): string
   {
      $bytes = $this->Randomizer->getBytes(16);

      // version (4) and variant (10xx) bits
      $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
      $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

      $hex = bin2hex($bytes);

      return $hex[0]  . $hex[1]  . $hex[2]  . $hex[3]
         . $hex[4]  . $hex[5]  . $hex[6]  . $hex[7]
         . '-'
         . $hex[8]  . $hex[9]  . $hex[10] . $hex[11]
         . '-'
         . $hex[12] . $hex[13] . $hex[14] . $hex[15]
         . '-'
         . $hex[16] . $hex[17] . $hex[18] . $hex[19]
         . '-'
         . $hex[20] . $hex[21] . $hex[22] . $hex[23]
         . $hex[24] . $hex[25] . $hex[26] . $hex[27]
         . $hex[28] . $hex[29] . $hex[30] . $hex[31];
   }
}
