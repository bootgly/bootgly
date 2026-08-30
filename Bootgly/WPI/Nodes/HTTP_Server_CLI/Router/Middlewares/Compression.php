<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use function function_exists;
use function gzdeflate;
use function gzencode;
use function str_contains;
use function strlen;
use Closure;

use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Sealing;


class Compression implements Middleware, Sealing
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

      // @ One pass serves both cycles — a deferred generation runs it at
      //   settlement (`seal()`), against the Response chosen for the wire
      /** @var Request $Request */
      /** @var Response $Response */
      $this->compress($Request, $Response);

      // :
      return $Response;
   }

   public function seal (Request $Request, Response $Response): void
   {
      $this->compress($Request, $Response);
   }

   /**
    * Encode one representation — the shared half of both cycles, typed
    * loosely because the synchronous unit tests hand doubles.
    *
    * @param Request $Request
    * @param Response $Response
    */
   private function compress (object $Request, object $Response): void
   {
      // ? Only compress cacheable responses (audit F-11): 2xx success / 3xx
      //   redirect. Skip 4xx/5xx error and auth-challenge bodies — they should
      //   not be re-encoded (keeps error representations out of the
      //   compression/validator surface).
      $code = $Response->code;
      if ($code < 200 || $code >= 400) {
         return;
      }

      // ? A representation already encoded must not be encoded again — a
      //   sealing pass may run after a synchronous pass already compressed
      //   (the wire-reporting `get()` reads an absent field as `''`)
      if ((string) $Response->Header->get('Content-Encoding') !== '') {
         return;
      }

      // ? Check body size meets minimum
      $body = $Response->Body->raw;
      if (strlen($body) < $this->minSize) {
         return;
      }

      // ! Every eligible identity/compressed representation depends on the
      //   request's Accept-Encoding value. Emit Vary before negotiation so an
      //   identity response cannot prime either Bootgly's route cache or an
      //   upstream shared cache for a later compression-capable client.
      $Response->Header->vary('Accept-Encoding');

      // ? Check Accept-Encoding
      $acceptEncoding = $Request->Header->get('Accept-Encoding') ?? '';

      // @ Compress with preferred encoding
      if (str_contains($acceptEncoding, 'gzip') && function_exists('gzencode')) {
         $compressed = gzencode($body, $this->level);
         if ($compressed !== false) {
            $Response->Body->raw = $compressed;
            $Response->Header->set('Content-Encoding', 'gzip');
         }
      }
      else if (str_contains($acceptEncoding, 'deflate') && function_exists('gzdeflate')) {
         $compressed = gzdeflate($body, $this->level);
         if ($compressed !== false) {
            $Response->Body->raw = $compressed;
            $Response->Header->set('Content-Encoding', 'deflate');
         }
      }
   }
}
