<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


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
use function preg_match;
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
   /**
    * The highest SCRAM iteration count accepted: PBKDF2 runs synchronously in
    * the worker, so a hostile server could otherwise stall it for minutes
    * (about two seconds at this ceiling).
    */
   private const int CEILING = 10_000_000;

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
   /**
    * The iteration count of the last server-first message `resume()` read
    * (`0` until one carried a valid count).
    */
   public private(set) int $iterations = 0;


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
    *
    * @param int $floor The lowest iteration count the caller accepts — the
    *    count is judged from the same strict parse that derives the key.
    *
    * @throws InvalidArgumentException When the message is malformed or ambiguous, or its count is below `$floor`.
    */
   public function resume (string $message, int $floor = 1): string
   {
      $this->iterations = 0;
      $Fields = $this->parse($message);
      $nonce = $Fields['r'] ?? '';
      $salt = base64_decode($Fields['s'] ?? '', true);
      $count = $Fields['i'] ?? '';

      if ($nonce === '' || str_starts_with($nonce, $this->clientNonce) === false) {
         throw new InvalidArgumentException('PostgreSQL SCRAM nonce is invalid.');
      }

      // ? A count is a decimal integer and nothing else
      $iterations = preg_match('/^[0-9]{1,10}$/', $count) === 1 ? (int) $count : 0;
      if ($salt === false || $iterations <= 0) {
         throw new InvalidArgumentException('PostgreSQL SCRAM salt or iteration count is invalid.');
      }
      $this->iterations = $iterations;

      // ? A count that would stall the worker
      if ($iterations > self::CEILING) {
         throw new InvalidArgumentException('PostgreSQL SCRAM iteration count is too large.');
      }

      // ? Below the floor the caller requires
      if ($iterations < $floor) {
         throw new InvalidArgumentException("PostgreSQL SCRAM iteration count {$iterations} is below {$floor}.");
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
      try {
         $Fields = $this->parse($message);
      }
      catch (InvalidArgumentException) {
         return false;
      }
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
    * Parse SCRAM key-value attributes — strictly: every attribute has `=`
    * right after its one-character name and appears once, so no two readers
    * of one message can see different values.
    *
    * @return array<string,string>
    *
    * @throws InvalidArgumentException When an attribute is malformed or repeated.
    */
   private function parse (string $message): array
   {
      $Fields = [];
      $parts = explode(',', $message);

      foreach ($parts as $part) {
         // ?
         if (strlen($part) < 2 || $part[1] !== '=') {
            throw new InvalidArgumentException('PostgreSQL SCRAM attribute is malformed.');
         }

         $key = $part[0];
         // ?
         if (isset($Fields[$key])) {
            throw new InvalidArgumentException('PostgreSQL SCRAM attribute is repeated.');
         }

         $Fields[$key] = substr($part, 2);
      }

      return $Fields;
   }
}
