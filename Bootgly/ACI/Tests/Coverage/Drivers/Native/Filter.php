<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage\Drivers\Native;


use const PSFS_FEED_ME;
use const PSFS_PASS_ON;
use function end;
use function stream_bucket_append;
use function stream_bucket_make_writeable;
use function stream_bucket_new;
use php_user_filter;

use Bootgly\ACI\Tests\Coverage\Drivers\Native;


/**
 * php_user_filter that injects line-hit markers into PHP source as it
 * is read from an `include` stream.
 *
 * The filter buffers the entire source until the upstream signals
 * end-of-file, then emits a single rewritten bucket. This avoids
 * chunk-local token boundary issues.
 *
 * The original (canonical) file path is recovered from the static
 * include stack maintained by `Native::load()`, not from
 * `$this->params`, because php://filter URLs do not propagate user
 * params reliably through the resource= chain.
 */
final class Filter extends php_user_filter
{
   /**
    * Filter name registered with `stream_filter_register()`.
    */
   public const string NAME = 'bootgly.coverage';

   /**
    * Buffered PHP source read from the upstream stream buckets.
    */
   private string $buffer = '';


   /**
    * Rewrite the buffered PHP source when the include stream closes.
    *
    * @param resource $in
    * @param resource $out
    * @param int $consumed
    */
   public function filter ($in, $out, &$consumed, bool $closing): int
   {
      while ($bucket = stream_bucket_make_writeable($in)) {
         $this->buffer .= $bucket->data;
         $consumed += $bucket->datalen;
      }

      if (! $closing) {
         return PSFS_FEED_ME;
      }

      $file = end(Native::$stack);
      if ($file === false) {
         // No identity available — pass-through.
         $rewritten = $this->buffer;
      }
      else {
         $lines = [];
         $spans = [];
         $labels = [];
         $declarations = [];
         $rewritten = Compiler::compile(
            source: $this->buffer,
            file: $file,
            lines: $lines,
            mode: Native::$mode,
            spans: $spans,
            labels: $labels,
            declarations: $declarations
         );

         Universe::register($file, $lines, $spans, $labels, $declarations);
      }

      $bucket = stream_bucket_new($this->stream, $rewritten);

      stream_bucket_append($out, $bucket);

      return PSFS_PASS_ON;
   }
}
