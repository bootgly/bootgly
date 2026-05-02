<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ;


/**
 * Contract for output builders that turn the internal `Differ` diff array
 * (entries of `[string $content, int $code]`) into a string representation.
 */
interface Output
{
   /**
    * @param array<int, array{0: string, 1: int}> $diff
    */
   public function render (array $diff): string;
}
