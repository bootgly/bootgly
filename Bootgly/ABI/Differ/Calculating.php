<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ;


/**
 * Contract for the Longest Common Subsequence calculation strategy
 * used by `Differ` to find the common tokens between two sequences.
 */
interface Calculating
{
   /**
    * Calculate the longest common subsequence of two sequences.
    *
    * @param  array<int, string> $from
    * @param  array<int, string> $to
    * @return array<int, string>
    */
   public function calculate (array $from, array $to): array;
}
