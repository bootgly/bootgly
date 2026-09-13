<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Analyzers;


/**
 * What an analyzer reports for one file: the file, its source and its issues.
 */
class Result
{
   // * Config
   /** Absolute path of the analyzed file */
   public readonly string $file;
   /** The source the issues were found in — what a formatter rewrites */
   public readonly string $source;
   /** @var array<int,Issue> */
   public readonly array $issues;
   /** Why the file was not analyzed — null when it was */
   public readonly null|string $notice;

   // * Metadata
   /** Whether the file carries at least one issue */
   public bool $failed {
      get => $this->issues !== [];
   }


   /**
    * @param string $file
    * @param string $source
    * @param array<int,Issue> $issues
    * @param null|string $notice Why the file was not analyzed, null when it was
    */
   public function __construct (string $file, string $source, array $issues, null|string $notice = null)
   {
      // * Config
      $this->file = $file;
      $this->source = $source;
      $this->issues = $issues;
      $this->notice = $notice;
   }
}
