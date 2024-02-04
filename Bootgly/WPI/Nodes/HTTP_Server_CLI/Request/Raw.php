<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Waiting;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Body;


class Raw
{
   public Header $Header;
   public Body $Body;

   // * Data
   public string $data;


   public function __construct ()
   {
      $this->Header = new Header;
      $this->Body = new Body;
   }

   public function __toString () : string
   {
      return $this->data ?? '';
   }

   public function input (Packages $Package, string &$buffer, int $size) : int // @ return Request length
   {
      // @ Check Request raw separator
      $separator_position = \strpos($buffer, "\r\n\r\n");
      // @ Check if the Request raw has a separator
      if ($separator_position === false) {
         // @ Check Request raw length
         if ($size >= 16384) { // Package size
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
         }

         return 0;
      }

      // @ Init Request length
      $length = $separator_position + 4;

      // ? Request Meta (first line of HTTP Header)
      // @ Get Request Meta raw
      // Sample: GET /path HTTP/1.1
      $meta_raw = \strstr($buffer, "\r\n", true);

      @[$method, $URI, $protocol] = \explode(' ', $meta_raw, 3);

      // @ Check Request Meta
      if (! $method || ! $URI || ! $protocol) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return 0;
      }
      // method
      switch ($method) {
         case 'GET':
         case 'HEAD':
         case 'POST':
         case 'PUT':
         case 'PATCH':
         case 'DELETE':
         case 'OPTIONS':
            break;
         default:
            $Package->reject("HTTP/1.1 405 Method Not Allowed\r\n\r\n");
            return 0;
      }
      // URI
      // protocol

      // @ Set Request Meta length
      $meta_length = \strlen($meta_raw);

      // ? Request Header
      // @ Get Request Header raw
      $header_raw = \substr($buffer, $meta_length + 2, $separator_position - $meta_length);

      // ? Request Body
      // @ Set Request Body length if possible
      if ( $_ = \strpos($header_raw, "\r\nContent-Length: ") ) {
         $content_length = (int) \substr($header_raw, $_ + 18, 10);
      }
      else if (\preg_match("/\r\ncontent-length: ?(\d+)/i", $header_raw, $match) === 1) {
         $content_length = $match[1];
      }
      else if (\stripos($header_raw, "\r\nTransfer-Encoding:") !== false) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return 0;
      }

      // @ Set Request Body raw if possible
      if ( isSet($content_length) ) {
         $length += $content_length; // @ Add Request Body length

         if ($length > 10485760) { // @ 10 megabytes
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return 0;
         }

         if ($content_length > 0) {
            // @ Check if HTTP content is not empty
            if ($size >= $separator_position + 4) {
               $this->Body->raw = \substr($buffer, $separator_position + 4, $content_length);
               $this->Body->downloaded = \strlen($this->Body->raw);
            }

            if ($content_length > $this->Body->downloaded) {
               $this->Body->waiting = true;
               Server::$Decoder = new Decoder_Waiting;
            }
         }

         $this->Body->length = $content_length;
      }

      // @ Set Request
      // ! Request
      // address
      $_SERVER['REMOTE_ADDR'] = $Package->Connection->ip;
      // port
      $_SERVER['REMOTE_PORT'] = $Package->Connection->port;
      // scheme
      $_SERVER['HTTPS'] = $Package->Connection->encrypted;
      // @@
      // method
      $_SERVER['REQUEST_METHOD'] = $method;
      // URI
      $_SERVER['REQUEST_URI'] = $URI;
      // protocol
      $_SERVER['SERVER_PROTOCOL'] = $protocol;

      // ! Request Header
      // raw
      $this->Header->set(raw: $header_raw);
      // host
      #$_SERVER['HTTP_HOST'] = $this->Header->get('HOST');

      // ! Request Body
      $this->Body->position = $separator_position + 4;

      // @ return Request length
      return $length;
   }
}
