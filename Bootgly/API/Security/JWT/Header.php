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


use function is_string;


/**
 * Verified JWT protected header.
 *
 * Instances must only be exposed after the token signature and registered
 * claim checks succeed.
 */
class Header
{
   // * Config
   // ...

   // * Data
   public private(set) string $algorithm;
   public private(set) string $type;
   public private(set) null|string $id;
   public private(set) null|string $content;
   /**
    * Raw verified protected header fields.
    *
    * @var array<string,mixed>
    */
   public private(set) array $fields;

   // * Metadata
   // ...


   /**
    * Create a verified header projection.
    *
    * @param array<string,mixed> $fields
    */
   public function __construct (array $fields)
   {
      // * Data
      $this->fields = $fields;
      $this->algorithm = is_string($fields['alg'] ?? null) ? $fields['alg'] : '';
      $this->type = is_string($fields['typ'] ?? null) ? $fields['typ'] : '';
      $this->id = is_string($fields['kid'] ?? null) ? $fields['kid'] : null;
      $this->content = is_string($fields['cty'] ?? null) ? $fields['cty'] : null;
   }
}
