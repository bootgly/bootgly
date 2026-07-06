<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail;


use function gethostname;
use function is_bool;
use function is_scalar;
use function preg_match;
use Closure;
use InvalidArgumentException;


/**
 * Mail configuration.
 *
 * Array-driven and constant-defaulted, matching the shape used across Bootgly
 * (mirrors `ACI\Queues\Config`). The SMTP client can be built from a plain
 * config array or a prepared Config value.
 */
class Config
{
   public const string SECURE_NONE = 'none';
   public const string SECURE_TLS = 'tls';
   public const string SECURE_STARTTLS = 'starttls';

   public const string DEFAULT_HOST = '127.0.0.1';
   public const int DEFAULT_PORT = 587;
   public const string DEFAULT_SECURE = self::SECURE_STARTTLS;
   public const bool DEFAULT_VERIFY = true;
   public const string DEFAULT_CAFILE = '';
   public const string DEFAULT_PEER = '';
   public const string DEFAULT_USERNAME = '';
   public const string DEFAULT_PASSWORD = '';
   public const string DEFAULT_TOKEN = '';
   public const string DEFAULT_DOMAIN = '';
   public const float DEFAULT_TIMEOUT = 10.0;
   public const float DEFAULT_WAIT = 30.0;
   public const float DEFAULT_DRAIN = 120.0;
   public const bool DEFAULT_INSECURE = false;

   // * Config
   /**
    * SMTP server host.
    */
   public string $host;
   /**
    * SMTP server port (465 typical for `tls`, 587 for `starttls`).
    */
   public int $port;
   /**
    * Transport security mode: `none`, `tls` (implicit) or `starttls` (upgrade).
    * An unknown value throws — a security mode is never guessed.
    */
   public string $secure;
   /**
    * TLS certificate verification (`verify_peer` + `verify_peer_name`).
    */
   public bool $verify;
   /**
    * CA bundle path ('' uses the system default).
    */
   public string $cafile;
   /**
    * TLS `peer_name`/SNI override ('' uses `host`).
    */
   public string $peer;
   /**
    * AUTH identity ('' disables AUTH unless `token` is set).
    */
   public string $username;
   /**
    * AUTH PLAIN/LOGIN secret.
    */
   public string $password;
   /**
    * XOAUTH2 bearer token (non-empty selects the XOAUTH2 mechanism).
    */
   public string $token;
   /**
    * EHLO/HELO client name ('' falls back to the machine hostname).
    */
   public string $domain;
   /**
    * TCP connect timeout in seconds.
    */
   public float $timeout;
   /**
    * Per-reply timeout in seconds — greeting/EHLO/MAIL/RCPT/DATA-init/AUTH
    * (RFC 5321 §4.5.3.2 allows up to 5 minutes; default is pragmatic).
    */
   public float $wait;
   /**
    * Final DATA-termination reply timeout in seconds
    * (RFC 5321 §4.5.3.2 allows up to 10 minutes; default is pragmatic).
    */
   public float $drain;
   /**
    * Explicitly allow AUTH over an unencrypted session (opt-in only).
    */
   public bool $insecure;
   /**
    * Wire trace hook: `function (string $direction, string $line): void`
    * with direction `>` (sent) or `<` (received). Credentials are redacted
    * and the DATA payload is traced as a byte count only.
    */
   public null|Closure $trace;


   /**
    * @param array<string,mixed> $config
    */
   public function __construct (array $config = [])
   {
      $host = $config['host'] ?? self::DEFAULT_HOST;
      $port = $config['port'] ?? self::DEFAULT_PORT;
      $secure = $config['secure'] ?? self::DEFAULT_SECURE;
      $verify = $config['verify'] ?? self::DEFAULT_VERIFY;
      $cafile = $config['cafile'] ?? self::DEFAULT_CAFILE;
      $peer = $config['peer'] ?? self::DEFAULT_PEER;
      $username = $config['username'] ?? self::DEFAULT_USERNAME;
      $password = $config['password'] ?? self::DEFAULT_PASSWORD;
      $token = $config['token'] ?? self::DEFAULT_TOKEN;
      $domain = $config['domain'] ?? self::DEFAULT_DOMAIN;
      $timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT;
      $wait = $config['wait'] ?? self::DEFAULT_WAIT;
      $drain = $config['drain'] ?? self::DEFAULT_DRAIN;
      $insecure = $config['insecure'] ?? self::DEFAULT_INSECURE;
      $trace = $config['trace'] ?? null;

      // * Config
      $this->host = is_scalar($host) ? (string) $host : self::DEFAULT_HOST;
      $this->port = is_scalar($port) ? (int) $port : self::DEFAULT_PORT;
      $this->secure = is_scalar($secure) ? (string) $secure : self::DEFAULT_SECURE;
      $this->verify = is_bool($verify) ? $verify : self::DEFAULT_VERIFY;
      $this->cafile = is_scalar($cafile) ? (string) $cafile : self::DEFAULT_CAFILE;
      $this->peer = is_scalar($peer) ? (string) $peer : self::DEFAULT_PEER;
      $this->username = is_scalar($username) ? (string) $username : self::DEFAULT_USERNAME;
      $this->password = is_scalar($password) ? (string) $password : self::DEFAULT_PASSWORD;
      $this->token = is_scalar($token) ? (string) $token : self::DEFAULT_TOKEN;
      $this->domain = is_scalar($domain) ? (string) $domain : self::DEFAULT_DOMAIN;
      $this->timeout = is_scalar($timeout) ? (float) $timeout : self::DEFAULT_TIMEOUT;
      $this->wait = is_scalar($wait) ? (float) $wait : self::DEFAULT_WAIT;
      $this->drain = is_scalar($drain) ? (float) $drain : self::DEFAULT_DRAIN;
      $this->insecure = is_bool($insecure) ? $insecure : self::DEFAULT_INSECURE;
      $this->trace = $trace instanceof Closure ? $trace : null;

      // ? Validate the security mode — never guess a security-relevant value
      if (
         $this->secure !== self::SECURE_NONE
         && $this->secure !== self::SECURE_TLS
         && $this->secure !== self::SECURE_STARTTLS
      ) {
         throw new InvalidArgumentException(
            "Invalid Mail `secure` mode `{$this->secure}`: expected `none`, `tls` or `starttls`."
         );
      }

      // ? Validate the operational values — misconfiguration must fail here,
      //   at construction, not later at the socket or on the wire
      if ($this->host === '') {
         throw new InvalidArgumentException('Invalid Mail `host`: it must not be empty.');
      }
      if ($this->port < 1 || $this->port > 65535) {
         throw new InvalidArgumentException(
            "Invalid Mail `port` `{$this->port}`: expected 1-65535."
         );
      }
      if ($this->timeout <= 0.0 || $this->wait <= 0.0 || $this->drain <= 0.0) {
         throw new InvalidArgumentException(
            'Invalid Mail timeout: `timeout`, `wait` and `drain` must be greater than zero.'
         );
      }
      // ? Validate the EHLO/HELO client name (RFC 5321: a domain or a
      //   bracketed address literal) — never let it break the command line
      if (
         $this->domain !== ''
         && preg_match('/^[A-Za-z0-9._\-]+$/', $this->domain) !== 1
         && preg_match('/^\[[A-Za-z0-9:.]+\]$/', $this->domain) !== 1
      ) {
         throw new InvalidArgumentException(
            "Invalid Mail `domain` `{$this->domain}`: expected a hostname or a bracketed address literal."
         );
      }

      // ? Fall back to the machine hostname as the EHLO/HELO client name
      if ($this->domain === '') {
         $this->domain = gethostname() ?: 'localhost';
      }
   }
}
