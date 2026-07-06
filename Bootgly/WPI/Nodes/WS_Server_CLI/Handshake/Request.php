<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI\Handshake;


use function base64_decode;
use function explode;
use function is_string;
use function stripos;
use function strpos;
use function substr;
use function trim;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Authentications\Basic;


/**
 * Request adapter passed to handshake auth guards. Exposes the upgrade request
 * headers (so `Guard::extract()` and header-reading guards work), a Basic
 * credential parser (so the `Basic` guard works), and writable metadata slots
 * for `Guard::expose()` (`identity`, `claims`, `tokenHeaders`).
 *
 * Session-backed guards are unsupported during a stateless upgrade — `Session`
 * is always null, so such guards deny gracefully instead of erroring.
 */
class Request
{
   // * Data
   public Header $Header;
   /** @var array<string, mixed> */
   public array $headers;
   public string $token = '';
   public mixed $Session = null;
   public bool $sessioned = false;
   // # Exposed by guards (Guard::expose()).
   public mixed $identity = null;
   public mixed $claims = null;
   public mixed $tokenHeaders = null;


   /**
    * @param array<string, mixed> $fields Lowercased header map from Frame::parse().
    */
   public function __construct (array $fields)
   {
      $this->headers = $fields;
      $this->Header = new Header($fields);
   }

   /**
    * Parse HTTP Basic credentials from the Authorization header (mirrors
    * `HTTP_Server_CLI\Request::authenticate()`); null for non-Basic/malformed.
    */
   public function authenticate (): null|Basic
   {
      $authorization = $this->Header->get('Authorization');
      if (is_string($authorization) === false) {
         return null;
      }
      // ? Basic credentials.
      if (stripos($authorization, 'Basic ') !== 0) {
         return null;
      }

      $encoded = trim(substr($authorization, 6));
      if ($encoded === '') {
         return null;
      }

      $decoded = base64_decode($encoded, true);
      if ($decoded === false || strpos($decoded, ':') === false) {
         return null;
      }

      [$username, $password] = explode(':', $decoded, 2);

      // :
      return new Basic($username, $password);
   }
}
