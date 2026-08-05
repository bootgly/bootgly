<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw;


use function json_validate;
use function preg_match;
use function trim;


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw;
   public string|null $input;

   // * Metadata
   public int|null $length;
   public int|null $position;
   public int|null $downloaded;
   public bool $waiting;
   public bool $streaming;


   public function __construct ()
   {
      // * Config
      // ... inherited

      // * Data
      $this->raw = '';
      $this->input = null;

      // * Metadata
      $this->length = null;
      $this->position = null;
      $this->downloaded = null;
      // ---
      $this->waiting = false;
      $this->streaming = false;
   }

   /**
    * Assume the decoded body of a cached template Body.
    *
    * Used by `Request::assume()` on the per-connection cache-hit path:
    * unconditional straight-line overwrite of every member, so no body state
    * from the previous request on this connection can survive. String
    * assignments are COW — the template stays untouched by later mutations.
    */
   public function assume (self $Template): void
   {
      // * Data
      $this->raw = $Template->raw;
      $this->input = $Template->input;

      // * Metadata
      $this->length = $Template->length;
      $this->position = $Template->position;
      $this->downloaded = $Template->downloaded;
      $this->waiting = $Template->waiting;
      $this->streaming = $Template->streaming;
   }

   /**
    * Reset every member to its constructor default.
    *
    * Used by `Request::reset()` on the per-connection cache-miss path: a
    * body-less `Request::decode()` writes only `position`, so without this
    * scrub a previous request's body would survive on the reused instance —
    * and a stale `length > downloaded` pair would flip `parse()` back into
    * `waiting`, deferring the response forever. Unconditional straight-line
    * writes (same tracing-JIT constraint as `Request::__clone`).
    */
   public function reset (): void
   {
      // * Data
      $this->raw = '';
      $this->input = null;

      // * Metadata
      $this->length = null;
      $this->position = null;
      $this->downloaded = null;
      $this->waiting = false;
      $this->streaming = false;
   }

   /**
    * Drop the decoded payload, keeping the decode metadata intact.
    *
    * Called by `Encoder_::encode()` once the response cycle has ended, so a
    * COMPLETED body does not stay resident on the connection's Request until
    * the next request happens to reuse it. Without this an idle keep-alive
    * peer parks its whole body with no reservation behind it — the decoders
    * release theirs on completion — which is memory the worker ceiling in
    * `Decoders\Bodies` never sees.
    *
    * Only `raw`/`input` are cleared: `length`, `downloaded` and `position`
    * are decode state that the body decoders read, and `reset()` already
    * owns the full-object scrub on the next decode.
    *
    * !! KEEP THIS STRAIGHT-LINE AND UNCONDITIONAL — same tracing-JIT
    *   constraint as `Request::__clone` (see its comment: data-dependent
    *   branches on this path deopt the request pipeline, 717k → 320k req/s).
    */
   public function scrub (): void
   {
      // * Data
      $this->raw = '';
      $this->input = null;
   }

   public function parse (string $content, ?string $type): bool|string
   {
      if ($type === null) {
         return false;
      }

      switch ($content) {
         case 'Form-data':
            // @ Parse Form-data (boundary)
            $matched = preg_match('/boundary="?(\S+)"?/', $type, $match);

            if ($matched === 1) {
               $boundary = trim('--' . $match[1], '"');

               return $boundary;
            }

            return false;
         case 'raw':
            // @ Check if Body downloaded length is minor than Body length
            if ($this->downloaded < $this->length) {
               $this->waiting = true;
               return false;
            }

            $this->waiting = false;

            $this->input = $this->raw;

            switch ($type) {
               // @ Parse raw - JSON
               case 'application/json':
                  if (json_validate($this->raw) === false) {
                     return false;
                  }

                  return true;
               // @ Parse raw - URL Encoded
               case 'application/x-www-form-urlencoded':
                  return true;
            }
      }

      return false;
   }
}
