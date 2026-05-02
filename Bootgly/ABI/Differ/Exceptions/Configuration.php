<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ\Exceptions;


use function gettype;
use function is_object;
use function sprintf;
use InvalidArgumentException;
use Throwable;

use Bootgly\ABI\Differ\Exceptioning;


final class Configuration extends InvalidArgumentException implements Exceptioning
{
   public function __construct (
      string $option,
      string $expected,
      mixed $value,
      int $code = 0,
      ?Throwable $previous = null
   ) {
      parent::__construct(
         sprintf(
            'Option "%s" must be %s, got "%s".',
            $option,
            $expected,
            is_object($value)
               ? $value::class
               : ($value === null ? '<null>' : gettype($value)),
         ),
         $code,
         $previous,
      );
   }
}
