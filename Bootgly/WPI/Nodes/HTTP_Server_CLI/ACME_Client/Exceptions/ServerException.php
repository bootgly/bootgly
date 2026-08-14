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


use function preg_replace;
use function strlen;
use function substr;
use Exception;

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
    * the Line formatter renders Bootgly markup while passing raw terminal
    * control bytes straight through — so an unscrubbed value can forge a log
    * record boundary, drive the operator's terminal, or fake formatting.
    */
   private static function scrub (string $value): string
   {
      // ? Controls: no CA string needs them, and they are what forges a record
      //   boundary or reaches the terminal.
      $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';

      // ? Markup introducer: a directive is always `@` followed by one of
      //   `#\.:;@*~_-`, or one of `*~_-` followed by `@`. Dropping only those
      //   occurrences leaves ordinary text — `user@example.com` included —
      //   intact.
      $value = preg_replace('/@(?=[#\\.:;@*~_-])|(?<=[*~_-])@/', '', $value) ?? '';

      // ? The transport caps a response near 1 MiB; a log line needs far less.
      return strlen($value) > 512
         ? substr($value, 0, 512) . '...'
         : $value;
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
