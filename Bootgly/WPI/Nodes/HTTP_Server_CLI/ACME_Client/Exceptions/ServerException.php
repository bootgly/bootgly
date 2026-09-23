<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions;


use function mb_strcut;
use function strlen;
use Exception;

use Bootgly\ABI\Code\__String\Controls;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptioning;


/**
 * The ACME server answered with an `application/problem+json` error document
 * (RFC 7807 / RFC 8555 §6.7) — `badNonce` (after the single transparent
 * retry), `rateLimited`, `unauthorized`, `malformed`, etc. `$code` carries
 * the HTTP status.
 */
final class ServerException extends Exception implements Exceptioning
{
   // * Config
   /**
    * ACME problem type — `urn:ietf:params:acme:error:*`.
    */
   public private(set) string $type;
   /**
    * Human-readable problem detail provided by the server.
    */
   public private(set) string $detail;
   /**
    * HTTP status of the problem document.
    */
   public private(set) int $status;
   /**
    * Parsed `Retry-After` header in seconds from now — null when absent.
    */
   public private(set) null|int $retryAfter;


   /**
    * Make one CA-supplied diagnostic string safe to log.
    *
    * A problem document is attacker-influenced whenever the configured CA is
    * malicious or compromised. Its `type`/`detail` reach the log message, and
    * the log formatters render Bootgly markup — so an unscrubbed value can
    * forge a log record boundary or fake formatting — and would carry its
    * control bytes into every sink that does not escape them.
    */
   private static function scrub (string $value): string
   {
      // ? The transport caps a response near 1 MiB; a log line needs far less.
      //   Capped first, on a character boundary: a cut made after the scrub
      //   could part a kept `@` from the letter that keeps it inert.
      if (strlen($value) > 512) {
         $value = mb_strcut($value, 0, 512, 'UTF-8') . '...';
      }

      // : Controls escaped visibly — no CA string needs them, and they are what
      //   forges a record boundary or reaches the terminal — then the markup
      //   introducers dropped; ordinary text (`user@example.com`) stays intact.
      return TemplateEscaped::scrub(Controls::escape($value));
   }

   public function __construct (
      string $type,
      string $detail,
      int $status,
      null|int $retryAfter = null
   )
   {
      // ! Both values come from the CA and are logged, so they are scrubbed
      //   at this boundary rather than at each sink.
      $type = self::scrub($type);
      $detail = self::scrub($detail);

      // * Config
      $this->type = $type;
      $this->detail = $detail;
      $this->status = $status;
      $this->retryAfter = $retryAfter;

      parent::__construct(
         message: "ACME server error `{$type}` (HTTP {$status}): {$detail}",
         code: $status
      );
   }
}
