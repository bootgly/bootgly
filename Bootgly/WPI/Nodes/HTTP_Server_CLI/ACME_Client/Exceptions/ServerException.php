<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions;


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


   public function __construct (
      string $type,
      string $detail,
      int $status,
      null|int $retryAfter = null
   )
   {
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
