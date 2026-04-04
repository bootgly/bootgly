<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use function function_exists;
use function gzencode;
use function gzdeflate;
use function strlen;
use function str_contains;
use Closure;

use Bootgly\API\Server\Middleware;


class Compression implements Middleware
{
   // * Config
   public private(set) int $level;
   public private(set) int $minSize;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param int $level Compression level (1-9, default: 6).
    * @param int $minSize Minimum body size to compress in bytes (default: 1024).
    */
   public function __construct (
      int $level = 6,
      int $minSize = 1024
   )
   {
      // * Config
      $this->level = $level;
      $this->minSize = $minSize;
   }

   public function process (object $Request, object $Response, Closure $next): object
   {
      // @ Pass through to handler first
      $Response = $next($Request, $Response);

      // ? Check body size meets minimum
      $body = $Response->Body->raw; // @phpstan-ignore-line
      if (strlen($body) < $this->minSize) {
         return $Response;
      }

      // ? Check Accept-Encoding
      $acceptEncoding = $Request->Header->get('Accept-Encoding') ?? ''; // @phpstan-ignore-line

      // @ Compress with preferred encoding
      if (str_contains($acceptEncoding, 'gzip') && function_exists('gzencode')) {
         $compressed = gzencode($body, $this->level);
         if ($compressed !== false) {
            $Response->Body->raw = $compressed; // @phpstan-ignore-line
            $Response->Header->set('Content-Encoding', 'gzip'); // @phpstan-ignore-line
            $Response->Header->set('Vary', 'Accept-Encoding'); // @phpstan-ignore-line
         }
      }
      else if (str_contains($acceptEncoding, 'deflate') && function_exists('gzdeflate')) {
         $compressed = gzdeflate($body, $this->level);
         if ($compressed !== false) {
            $Response->Body->raw = $compressed; // @phpstan-ignore-line
            $Response->Header->set('Content-Encoding', 'deflate'); // @phpstan-ignore-line
            $Response->Header->set('Vary', 'Accept-Encoding'); // @phpstan-ignore-line
         }
      }

      // :
      return $Response;
   }
}
