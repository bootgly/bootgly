<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ\Inputs;


use function str_starts_with;
use function substr;

use Bootgly\ABI\Differ\Diff;
use Bootgly\ABI\Differ\Diff\Chunk;
use Bootgly\ABI\Differ\Diff\Line;
use Bootgly\ABI\Differ\Inputs as InputContract;
use Bootgly\ABI\Differ\Inputs\GitDiff\Hunk;
use Bootgly\ABI\Differ\Parser;


/**
 * Converts raw `git diff --no-color` output into Bootgly Differ structures.
 */
final class GitDiff implements InputContract
{
   private const string NO_NEWLINE_MARKER = '\\ No newline at end of file';


   private Parser $Parser;


   public function __construct (?Parser $Parser = null)
   {
      $this->Parser = $Parser ?? new Parser;
   }

   /**
    * Parse raw git-diff output into the existing unified-diff model.
    *
    * @return list<Diff>
    */
   public function parse (string $input): array
   {
      return $this->Parser->parse($input);
   }

   /**
    * Extract hunks from raw git-diff output, ready for `Differ::diff()`.
    *
    * @return list<Hunk>
    */
   public function extract (string $input): array
   {
      $extracted = [];

      foreach ($this->parse($input) as $Diff) {
         foreach ($Diff->chunks as $Chunk) {
            $Hunk = $this->convert($Diff, $Chunk);

            if ($Hunk !== null) {
               $extracted[] = $Hunk;
            }
         }
      }

      return $extracted;
   }

   private function convert (Diff $Diff, Chunk $Chunk): ?Hunk
   {
      $fromLines = [];
      $toLines   = [];

      foreach ($Chunk->lines as $Line) {
         if ($Line->content === self::NO_NEWLINE_MARKER) {
            continue;
         }

         if ($Line->type === Line::REMOVED || $Line->type === Line::UNCHANGED) {
            $fromLines[] = $Line->content;
         }

         if ($Line->type === Line::ADDED || $Line->type === Line::UNCHANGED) {
            $toLines[] = $Line->content;
         }
      }

      if ($fromLines === [] && $toLines === []) {
         return null;
      }

      [$fromFile, $toFile] = $this->label($Diff->from, $Diff->to);

      return new Hunk(
         $fromFile,
         $toFile,
         $Chunk->start,
         $Chunk->end,
         $fromLines,
         $toLines
      );
   }

   /**
    * @return array{0: string, 1: string}
    */
   private function label (string $fromFile, string $toFile): array
   {
      return [
         $this->strip($fromFile),
         $this->strip($toFile),
      ];
   }

   private function strip (string $file): string
   {
      if (str_starts_with($file, 'a/') || str_starts_with($file, 'b/')) {
         return substr($file, 2);
      }

      return $file;
   }
}
