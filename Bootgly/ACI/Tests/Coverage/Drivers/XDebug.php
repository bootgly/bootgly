<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage\Drivers;


use function constant;
use function defined;
use function explode;
use function function_exists;
use function is_array;
use function is_string;
use function trim;
use LogicException;

use Bootgly\ACI\Tests\Coverage\Driver;


/**
 * ext-xdebug backend.
 *
 * Requires XDEBUG_MODE=coverage. Hits >0 mean executed; -1/-2 are folded
 * into 0 by the normalizer.
 */
final class XDebug extends Driver
{
   /**
    * Prefix shared by Xdebug coverage functions.
    */
   private const string FN_PREFIX = 'xdebug_';


   /**
    * Ensure Xdebug coverage mode is enabled before the backend is used.
    */
   public function __construct ()
   {
      self::resolve('start_code_coverage');
      $info = self::resolve('info');

      $modes = $info('mode');

      if (is_string($modes)) {
         $modes = explode(',', $modes);
      }

      if (! is_array($modes)) {
         throw new LogicException('Could not read xdebug mode; set XDEBUG_MODE=coverage.');
      }

      $covered = false;
      foreach ($modes as $mode) {
         if (is_string($mode) && trim($mode) === 'coverage') {
            $covered = true;
            break;
         }
      }

      if (! $covered) {
         throw new LogicException(
            'Xdebug is loaded but coverage mode is disabled; '
            . 'set XDEBUG_MODE=coverage (or xdebug.mode=coverage).'
         );
      }
   }

   /**
    * Start Xdebug code coverage with available line-state flags.
    */
   protected function begin (): void
   {
      $flags = 0;
      $unusedFlag = 'XDEBUG_CC_UNUSED';

      if (defined($unusedFlag)) {
         $flags |= (int) constant($unusedFlag);
      }

      $deadFlag = 'XDEBUG_CC_DEAD_CODE';

      if (defined($deadFlag)) {
         $flags |= (int) constant($deadFlag);
      }

      $start = self::resolve('start_code_coverage');
      $start($flags);
   }

   /**
    * Stop Xdebug code coverage while keeping the hit map available.
    */
   protected function end (): void
   {
      $stop = self::resolve('stop_code_coverage');
      $stop(false);
   }

   /**
    * @return array<string, array<int, int>>
    */
   public function collect (): array
   {
      $get = self::resolve('get_code_coverage');
      /** @var array<string, array<int, int>> $raw */
      $raw = $get();
      $out = [];

      foreach ($raw as $file => $lines) {
         $bucket = [];
         foreach ($lines as $line => $state) {
            $bucket[$line] = $state > 0 ? 1 : 0;
         }
         $out[$file] = $bucket;
      }

      return $out;
   }

   /**
    * @return callable-string
    */
   private static function resolve (string $suffix): string
   {
      $function = self::FN_PREFIX . $suffix;

      if (! function_exists($function)) {
         throw new LogicException("Xdebug coverage requires {$function}().");
      }

      return $function;
   }
}
