<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use function ltrim;
use function preg_match;
use function str_contains;
use function strcspn;
use function strtoupper;
use function substr;
use function trim;
use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;


/**
 * Normalized SQL text and parameters.
 */
class Normalized
{
   // * Config
   public private(set) string $SQL;
   /** @var array<int|string,mixed> */
   public private(set) array $parameters;
   public private(set) bool $reading;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Normalize raw SQL, a builder, or a compiled query.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function __construct (string|Builder|Query $query, array $parameters = [])
   {
      $reading = false;

      if ($query instanceof Builder) {
         $Query = $query->compile();
         $reading = $Query->reading;
         $query = $Query->SQL;
         $parameters = $Query->parameters;
      }
      else if ($query instanceof Query) {
         $reading = $query->reading;
         $parameters = $query->parameters;
         $query = $query->SQL;
      }
      else {
         $reading = $this->check($query);
      }

      if (trim($query) === '') {
         throw new InvalidArgumentException('SQL cannot be empty.');
      }

      // * Config
      $this->SQL = $query;
      $this->parameters = $parameters;
      $this->reading = $reading;
   }

   /**
    * Check whether a raw SQL string is safe to route to a replica.
    */
   private function check (string $sql): bool
   {
      $sql = ltrim($sql);
      $upper = strtoupper($sql);
      $token = substr($upper, 0, strcspn($upper, " \t\r\n("));

      return match ($token) {
         'SELECT', 'SHOW' => true,
         'EXPLAIN' => str_contains($upper, 'ANALYZE') === false,
         'WITH' => $this->write($upper) === false,
         default => false,
      };
   }

   /**
    * Check whether SQL text contains write tokens.
    */
   private function write (string $sql): bool
   {
      return preg_match('/\b(ALTER|CREATE|DELETE|DROP|INSERT|MERGE|TRUNCATE|UPDATE)\b/', $sql) === 1;
   }
}
