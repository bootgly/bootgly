<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\Microbenchmark\Arrays;


/**
 * The array shapes a microbenchmark can ask for as input.
 *
 * Shape decides which PHP internals a measurement exercises — packed vs hashed
 * storage, scalar vs nested values — so two benchmarks comparing "the same"
 * operation are only comparable when they ask for the same shape.
 */
enum Shapes
{
   /** Packed list of ints: `[1, 2, 3, ...]`. */
   case Sequence;
   /** Hashed map: `['key0' => 'value0', ...]`. */
   case Map;
   /** Packed list of strings: `['value0', 'value1', ...]`. */
   case Strings;
   /** Packed list whose ONLY nested entry sits last — worst case for a short-circuit. */
   case Nested;
}
