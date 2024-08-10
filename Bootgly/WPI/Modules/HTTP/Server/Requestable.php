<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server;


use const JSON_THROW_ON_ERROR;
use function explode;
use function count;
use function json_decode;
use function parse_str;
use function strpos;
use function strlen;
use function strtotime;
use function substr;
use function base64_decode;
use function preg_match_all;
use function preg_match;
use function uasort;
use function array_merge;
use function array_keys;
use JsonException;

use const Bootgly\WPI;


trait Requestable
{
   // * Metadata
   protected string $encoding;


   /**
    * Receive the input data from the request.
    *
    * @return ?array<string>
    */
   public function input (): ?array
   {
      $inputs = [];

      // @ Try to convert input automatically
      try {
         $input = $this->input;

         // raw (JSON)
         $inputs = json_decode(
            json: $input,
            associative: true,
            depth: 512,
            flags: JSON_THROW_ON_ERROR
         );
      }
      catch (JsonException) {
         // x-www-form-urlencoded
         parse_str(
            string: $input,
            result: $inputs
         );
      }

      return $inputs;
   }

   // HTTP Basic Authentication
   public function authenticate (): object|null
   {
      $authorization = $this->Header->get('Authorization');

      $username = '';
      $password = '';
      if (strpos($authorization, 'Basic') === 0) {
         $encoded_credentials = substr($authorization, 6);
         $decoded_credentials = base64_decode($encoded_credentials);

         [$username, $password] = explode(':', $decoded_credentials, 2);

         $this->username = $username;
         $this->password = $password;
      }

      return new class ($username, $password) {
         public function __construct 
         (
            public string $username,
            public string $password
         ){}
      };
   }

   // HTTP Content Negotiation
   public const int ACCEPTS_TYPES = 1;
   public const int ACCEPTS_LANGUAGES = 2;
   public const int ACCEPTS_CHARSETS = 4;
   public const int ACCEPTS_ENCODINGS = 8;
   /**
    * Negotiate the request content.
    *
    * @param int $with The content to negotiate.
    *
    * @return array<string> The negotiated content.
    */
   public function negotiate (int $with = self::ACCEPTS_TYPES): array
   {
      switch ($with) {
         case self::ACCEPTS_TYPES:
            // @ Accept
            $header = (
               $_SERVER['HTTP_ACCEPT']
               ?? $this->Header->get('Accept')
            );
            $pattern = '/([\w\/\+\*.-]+)(?:;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
         case self::ACCEPTS_CHARSETS:
            // @ Accept-Charset
            $header = (
               $_SERVER['HTTP_ACCEPT_CHARSET']
               ?? $this->Header->get('Accept-Charset')
            );
            $pattern = '/([a-z0-9]{1,8}(?:[-_][a-z0-9]{1,8}){0,3})\s*(?:;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
         case self::ACCEPTS_LANGUAGES:
            // @ Accept-Language
            $header = (
               $_SERVER['HTTP_ACCEPT_LANGUAGE']
               ?? $this->Header->get('Accept-Language')
            );
            $pattern = '/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
         case self::ACCEPTS_ENCODINGS:
            // @ Accept-Encoding
            $header = (
               $_SERVER['HTTP_ACCEPT_ENCODING']
               ?? $this->Header->get('Accept-Encoding')
            );
            $pattern = '/([a-z0-9]{1,8}(?:[-_][a-z0-9]{1,8}){0,3})\s*(?:;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
      }

      // @ Validate header
      if ( empty($header) ) {
         return [];
      }

      // @ Validate RegEx
      preg_match_all(
         $pattern ?? self::ACCEPTS_TYPES,
         $header,
         $matches,
         PREG_SET_ORDER
      );

      $results = [];
      foreach ($matches as $match) {
         $item = $match[1];
         $quality = (float) ($match[2] ?? 1.0);

         $results[$item] = $quality;
      }

      uasort($results, function ($a, $b) {
         return $b <=> $a;
      });

      $results = array_merge(array_keys($results), $results);

      return $results;
   }

   // HTTP Caching Specification
   public function freshen (): bool
   {
      if ($this->method !== 'GET' && $this->method !== 'HEAD') {
         return false;
      }

      $if_modified_since = $this->Header->get('If-Modified-Since');
      $if_none_match = $this->Header->get('If-None-Match');
      if ( ! $if_modified_since && ! $if_none_match ) {
         return false;
      }

      // @ cache-control
      $cache_control = $this->Header->get('Cache-Control');
      if ($cache_control && preg_match('/(?:^|,)\s*?no-cache\s*?(?:,|$)/', $cache_control)) {
         return false;
      }

      // @ if-none-match
      if ($if_none_match && $if_none_match !== '*') {
         $entity_tag = WPI->Response->Header->get('ETag');

         if ( ! $entity_tag ) {
            return false;
         }

         $entity_tag_stale = true;

         // ? HTTP Parse Token List
         $matches = [];
         $start = 0;
         $end = 0;
         // @ Gather tokens
         for ($i = 0; $i < strlen($if_none_match); $i++) {
            switch ($if_none_match[$i]) {
               case ' ':
                  if ($start === $end) {
                     $start = $end = $i + 1;
                  }
                  break;
               case ',':
                  $matches[] = substr($if_none_match, $start, $end);
                  $start = $end = $i + 1;
                  break;
               default:
                  $end = $i + 1;
                  break;
            }
         }
         // final token
         $matches[] = substr($if_none_match, $start, $end);

         for ($i = 0; $i < count($matches); $i++) {
            $match = $matches[$i];
            if ($match === $entity_tag || $match === 'W/' . $entity_tag || 'W/' . $match === $entity_tag) {
               $entity_tag_stale = false;
               break;
            }
         }

         if ($entity_tag_stale) {
            return false;
         }
      }

      // @ if-modified-since
      if ($if_modified_since) {
         $last_modified = WPI->Response->Header->get('Last-Modified');
         if ($last_modified === '') {
            return false;
         }

         $last_modified_time = strtotime($last_modified);
         $if_modified_since_time = strtotime($if_modified_since);
         if ($last_modified_time === false || $if_modified_since_time === false) {
            return false;
         }

         $modified_stale = $last_modified_time > $if_modified_since_time;
         if ($modified_stale) {
            return false;
         }
      }

      return true;
   }
}
