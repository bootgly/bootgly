<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository;


use function array_is_list;
use function array_values;
use function base64_decode;
use function base64_encode;
use function count;
use function is_array;
use function is_scalar;
use function json_decode;
use function json_encode;
use function rtrim;
use function strtr;
use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Repository\Pagination\Modes;


/**
 * ORM pagination request and outcome.
 *
 * Page mode slices with LIMIT/OFFSET and always carries a total count;
 * cursor mode slices with a keyset predicate derived from an opaque token
 * and never counts (`more` comes from a limit+1 probe).
 */
class Pagination
{
   // * Config
   public private(set) Modes $Mode;
   public private(set) int $limit;
   public private(set) null|int $page;
   public private(set) null|string $cursor;

   // * Data
   public private(set) null|int $total = null;
   public private(set) null|int $pages = null;
   public private(set) bool $more = false;
   public private(set) null|string $next = null;
   public private(set) null|string $previous = null;

   // * Metadata
   // ...


   public function __construct (int $limit = 10, null|int $page = null, null|string $cursor = null, null|Modes $Mode = null)
   {
      // ?
      if ($limit < 1) {
         throw new InvalidArgumentException('ORM pagination limit must be greater than zero.');
      }
      if ($page !== null && $page < 1) {
         throw new InvalidArgumentException('ORM pagination page must be greater than zero.');
      }
      if ($page !== null && $cursor !== null) {
         throw new InvalidArgumentException('ORM pagination accepts either a page or a cursor.');
      }

      // ! Mode inferred from the given slice input.
      $Inferred = match (true) {
         $cursor !== null => Modes::Cursor,
         $page !== null => Modes::Page,
         default => $Mode ?? Modes::Page
      };

      if ($Mode !== null && $Mode !== $Inferred) {
         throw new InvalidArgumentException('ORM pagination mode contradicts the given page or cursor.');
      }

      // * Config
      $this->Mode = $Inferred;
      $this->limit = $limit;
      $this->page = $page;
      $this->cursor = $cursor;
   }

   /**
    * Encode ordered cursor values to one opaque base64url token.
    *
    * @param array<int,mixed> $values Order column values with the key tiebreak last.
    */
   public static function encode (array $values): string
   {
      // ? Keyset comparisons require comparable, non-null scalars.
      foreach ($values as $value) {
         if (is_scalar($value) === false) {
            throw new InvalidArgumentException('ORM pagination cursor requires scalar, non-null order values.');
         }
      }

      $JSON = json_encode(array_values($values));

      if ($JSON === false) {
         throw new InvalidArgumentException('ORM pagination cursor values are not encodable.');
      }

      // : Opaque base64url token.
      return rtrim(strtr(base64_encode($JSON), '+/', '-_'), '=');
   }

   /**
    * Decode this pagination cursor to ordered keyset values.
    *
    * The cursor is client-controlled input: any structural violation throws.
    *
    * @param int $arity Expected value count (order columns + key tiebreak).
    * @return array<int,bool|float|int|string>
    */
   public function decode (int $arity): array
   {
      // ?
      $cursor = $this->cursor;

      if ($cursor === null || $cursor === '') {
         throw new InvalidArgumentException('ORM pagination cursor is invalid.');
      }

      $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

      if ($decoded === false) {
         throw new InvalidArgumentException('ORM pagination cursor is invalid.');
      }

      $values = json_decode($decoded, true, 2);

      if (is_array($values) === false || array_is_list($values) === false || count($values) !== $arity) {
         throw new InvalidArgumentException('ORM pagination cursor is invalid.');
      }

      foreach ($values as $value) {
         if (is_scalar($value) === false) {
            throw new InvalidArgumentException('ORM pagination cursor is invalid.');
         }
      }

      // : Ordered keyset values with the key tiebreak last.
      return $values;
   }

   /**
    * Resolve this pagination outcome after hydration.
    */
   public function resolve (null|int $total = null, null|int $pages = null, bool $more = false, null|string $next = null, null|string $previous = null): static
   {
      // * Data
      $this->total = $total;
      $this->pages = $pages;
      $this->more = $more;
      $this->next = $next;
      $this->previous = $previous;

      // : Resolved pagination.
      return $this;
   }
}
