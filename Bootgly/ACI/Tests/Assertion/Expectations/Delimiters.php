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
