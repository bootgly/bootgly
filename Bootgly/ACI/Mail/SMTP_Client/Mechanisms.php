<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail\SMTP_Client;


use function base64_encode;


/**
 * SMTP AUTH mechanisms supported by the client (RFC 4954).
 */
enum Mechanisms: string
{
   case Plain = 'PLAIN';
   case Login = 'LOGIN';
   case XOAuth2 = 'XOAUTH2';


   /**
    * Base64 initial response for this mechanism.
    *
    * Plain: `base64("\0username\0secret")` (RFC 4616).
    * Login: `base64(username)` — the password challenge answer is encoded
    * by the caller.
    * XOAuth2: `base64("user=<username>\x01auth=Bearer <secret>\x01\x01")`
    * where `$secret` is the bearer token.
    */
   public function encode (string $username, string $secret): string
   {
      // :
      return match ($this) {
         self::Plain => base64_encode("\0{$username}\0{$secret}"),
         self::Login => base64_encode($username),
         self::XOAuth2 => base64_encode("user={$username}\x01auth=Bearer {$secret}\x01\x01")
      };
   }
}
