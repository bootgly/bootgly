<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database\Connection\Protocols\PostgreSQL;


use function base64_decode;
use function base64_encode;
use function chr;
use function explode;
use function hash;
use function hash_equals;
use function hash_hmac;
use function hash_pbkdf2;
use function in_array;
use function md5;
use function ord;
use function random_bytes;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use InvalidArgumentException;

use Bootgly\ADI\Database\Config;


/**
 * PostgreSQL authentication helper.
 */
class Authentication
{
   // * Config
   public Config $Config;

   // * Data
   public string $mechanism = '';
   public string $clientNonce = '';
   public string $clientFirstBare = '';
   public string $serverFirst = '';
   public string $clientFinal = '';
   public string $serverSignature = '';
   public bool $authenticated = false;

   // * Metadata
   // ...


   public function __construct (Config $Config)
   {
      // * Config
      $this->Config = $Config;
   }

   /**
    * Create a PostgreSQL MD5 password response.
    */
   public function hash (string $salt): string
   {
      $inner = md5("{$this->Config->password}{$this->Config->username}");
      $outer = md5("{$inner}{$salt}");

      return "md5{$outer}";
   }

   /**
    * Start a SCRAM-SHA-256 exchange.
    *
    * @param array<int,string> $mechanisms
    *
    * @return array{mechanism:string,response:string}
    */
   public function start (array $mechanisms): array
   {
      if (in_array('SCRAM-SHA-256', $mechanisms, true) === false) {
         throw new InvalidArgumentException('PostgreSQL SCRAM-SHA-256 mechanism is not available.');
      }

      if ($this->clientNonce === '') {
         $this->clientNonce = base64_encode(random_bytes(18));
      }

      $this->mechanism = 'SCRAM-SHA-256';
      $username = $this->escape($this->Config->username);
      $this->clientFirstBare = "n={$username},r={$this->clientNonce}";

      return [
         'mechanism' => $this->mechanism,
         'response' => "n,,{$this->clientFirstBare}",
      ];
   }

   /**
    * Resume a SCRAM exchange from the server-first-message.
    */
   public function resume (string $message): string
   {
      $Fields = $this->parse($message);
      $nonce = $Fields['r'] ?? '';
      $salt = base64_decode($Fields['s'] ?? '', true);
      $iterations = (int) ($Fields['i'] ?? '0');

      if ($nonce === '' || str_starts_with($nonce, $this->clientNonce) === false) {
         throw new InvalidArgumentException('PostgreSQL SCRAM nonce is invalid.');
      }

      if ($salt === false || $iterations <= 0) {
         throw new InvalidArgumentException('PostgreSQL SCRAM salt or iteration count is invalid.');
      }

      $this->serverFirst = $message;
      $this->clientFinal = "c=biws,r={$nonce}";
      $auth = "{$this->clientFirstBare},{$this->serverFirst},{$this->clientFinal}";
      $salted = hash_pbkdf2('sha256', $this->Config->password, $salt, $iterations, 32, true);
      $clientKey = hash_hmac('sha256', 'Client Key', $salted, true);
      $storedKey = hash('sha256', $clientKey, true);
      $clientSignature = hash_hmac('sha256', $auth, $storedKey, true);
      $proof = '';
      $length = strlen($clientKey);

      for ($index = 0; $index < $length; $index++) {
         $proof .= chr(ord($clientKey[$index]) ^ ord($clientSignature[$index]));
      }

      $serverKey = hash_hmac('sha256', 'Server Key', $salted, true);
      $this->serverSignature = base64_encode(hash_hmac('sha256', $auth, $serverKey, true));
      $proof = base64_encode($proof);

      return "{$this->clientFinal},p={$proof}";
   }

   /**
    * Finish a SCRAM exchange by validating the server-final-message.
    */
   public function finish (string $message): bool
   {
      $Fields = $this->parse($message);
      $signature = $Fields['v'] ?? '';

      return hash_equals($this->serverSignature, $signature);
   }

   /**
    * Escape a SCRAM username.
    */
   private function escape (string $value): string
   {
      $value = str_replace('=', '=3D', $value);

      return str_replace(',', '=2C', $value);
   }

   /**
    * Parse SCRAM key-value attributes.
    *
    * @return array<string,string>
    */
   private function parse (string $message): array
   {
      $Fields = [];
      $parts = explode(',', $message);

      foreach ($parts as $part) {
         if (strlen($part) < 3) {
            continue;
         }

         $key = $part[0];
         $Fields[$key] = substr($part, 2);
      }

      return $Fields;
   }
}
