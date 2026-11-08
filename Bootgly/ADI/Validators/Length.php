<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Validators;



use function is_string;
use function preg_match_all;
use InvalidArgumentException;

use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Descriptor;


/**
 * A string whose length, counted in characters (Unicode code points), is
 * within the bounds. The value must be valid UTF-8; anything that is not a
 * string fails. `Minimum`/`Maximum` count bytes — this rule counts what a
 * reader sees.
 */
class Length extends Condition implements Descriptor
{
   // * Config
   /**
    * The fewest characters allowed (`null` = no lower bound).
    */
   public private(set) null|int $minimum;
   /**
    * The most characters allowed (`null` = no upper bound).
    */
   public private(set) null|int $maximum;

   // * Metadata
   protected string $template = '{field} must be between {minimum} and {maximum} characters.';


   /**
    * @param null|int $minimum The fewest characters allowed.
    * @param null|int $maximum The most characters allowed.
    *
    * @throws InvalidArgumentException When no bound is given, a bound is negative or the minimum exceeds the maximum.
    */
   public function __construct (null|int $minimum = null, null|int $maximum = null, string $message = '')
   {
      // ?
      if ($minimum === null && $maximum === null) {
         throw new InvalidArgumentException('Length needs a minimum or a maximum.');
      }
      if (($minimum !== null && $minimum < 0) || ($maximum !== null && $maximum < 0)) {
         throw new InvalidArgumentException('Length bounds must not be negative.');
      }
      if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
         throw new InvalidArgumentException('Length minimum must not exceed its maximum.');
      }

      parent::__construct($message);

      // * Config
      $this->minimum = $minimum;
      $this->maximum = $maximum;

      // * Metadata
      if ($maximum === null) {
         $this->template = '{field} must be at least {minimum} characters.';
         $this->substitutions = ['minimum' => (string) $minimum];
      }
      else if ($minimum === null) {
         $this->template = '{field} must be at most {maximum} characters.';
         $this->substitutions = ['maximum' => (string) $maximum];
      }
      else {
         $this->substitutions = ['minimum' => (string) $minimum, 'maximum' => (string) $maximum];
      }
   }

   /**
    * Pass when the value is a UTF-8 string with a length within the bounds.
    *
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      if (is_string($value) === false) {
         return false;
      }

      // ! Code points; invalid UTF-8 fails (`false`)
      $length = preg_match_all('/./su', $value);
      if ($length === false) {
         return false;
      }

      // :
      return ($this->minimum === null || $length >= $this->minimum)
         && ($this->maximum === null || $length <= $this->maximum);
   }

   /**
    * JSON Schema: `type: string` with `minLength` / `maxLength` (both count
    * code points, exactly like this rule).
    *
    * @return null|array<string,mixed>
    */
   public function describe (): null|array
   {
      $keywords = ['type' => 'string'];

      if ($this->minimum !== null) {
         $keywords['minLength'] = $this->minimum;
      }
      if ($this->maximum !== null) {
         $keywords['maxLength'] = $this->maximum;
      }

      // :
      return $keywords;
   }
}
