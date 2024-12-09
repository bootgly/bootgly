<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations;


use AssertionError;
use DateTime;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Interval;


/**
 * @property mixed $expectation
 */
trait Delimiters
{
   /**
    * Delimit a range of values.
    * "expect that $actual delimit $from and $to".
    *
    * @param int|float|DateTime $from The start of the range.
    * @param int|float|DateTime $to The end of the range.
    * @param Interval $interval The interval type.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function delimit (
      int|float|DateTime $from,
      int|float|DateTime $to,
      Interval $interval = Interval::Closed
   ): self
   {
      $this->expectation = match ($interval) {
         Interval::Open =>
            new Delimiters\OpenInterval($from, $to),
         Interval::Closed =>
            new Delimiters\ClosedInterval($from, $to),
         Interval::LeftOpen =>
            new Delimiters\LeftOpenInterval($from, $to),
         Interval::RightOpen =>
            new Delimiters\RightOpenInterval($from, $to),
         default =>
            throw new AssertionError('Invalid interval delimiter.')
      };

      return $this;
   }
}
