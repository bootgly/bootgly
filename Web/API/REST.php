<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Web\API;


use Bootgly\WPI;

use Web;
use Web\API;


class REST extends API // TODO
{
   public Web $Web;

   // * Config
   // ...imported from API

   // * Data
   public array $data;
   // @ data['result']
   // protected $result;
   // @ data['exception']
   // protected $debug;
   // protected $error;
   // protected $warning;
   // protected $info;
   // @ data['metadata']

   // * Meta
   public string $key;


   public function __construct ()
   {
      $Web = $this->Web = new Web;
      // ---
      $Web->API = $this;
      // ---
      // TODO TEMP
      $Web->Request = WPI::$Request;
      $Web->Response = WPI::$Response;
      $Web->Router = WPI::$Router;

      $Web->Response->use('API', $this);
      $Web->Response->use('Web', $Web);

      // * Config
      $this->debugger = true;

      // * Data
      $this->data = [];
      $this->data['result'] = NULL;
      $this->data['exception'] = NULL; // TODO rename to events? log*?
      $this->data['metadata'] = NULL;

      // * Meta
      $this->key = '';
   }
   public function __get (string $index)
   {
      switch ($index) {
         case 'result':
            $this->key = 'result';
            return $this;
         case 'exception':
            $this->key = 'exception';
            return $this;
         case 'metadata':
            $this->key = 'metadata';
            return $this;

         default:
            return $this->$index ?? NULL;
      }
   }
   // TODO custom data templating
   public function __set (string $index, $value)
   {
      // ? data['exception']
      switch ($index) {
         case 'debug':
         case 'error':
         case 'warning':
         case 'info':
            if ($this->data['exception'] === NULL) {
               $this->data['exception'] = [];
            }
            break;
      }

      if ($this->key) { // Walked
         switch ($this->key) {
            case 'result':
               $this->data['result'][$index] = $value;
               break;
            case 'exception':
               $this->data['exception'][$index][] = $value;
               break;
            case 'metadata':
               $this->data['metadata'][$index][] = $value;
               break;
         }

         $this->key = '';
      } else { // Root
         switch ($index) {
            case 'data':
               $this->data = $value;
               break;

            // @ data['result']
            case 'result':
               $this->data['result'] = $value;
               break;

            // @ data['exception']
            case 'debug':
               if ($this->debugger) {
                  $this->data['exception']['debug'] = $value;
               }
               break;
            case 'error':
               if (!$this->debugger) {
                  unset($value['message']);
               }
               $this->data['exception']['error'] = $value;
               break;
            case 'warning':
               $this->data['exception']['warning'] = $value;
               break;
            case 'info':
               $this->data['exception']['info'] = $value;
               break;

            // @ data['metadata']
            default:
               $this->data[$index] = $value;
         }
      }
   }

   public function debug ($data, string $password = '')
   {
      if ($this->debugger === false) {
         return false;
      }

      $Request = &WPI::$Request;
      if (@$Request->queries['debug'] === $password || $password === '') {
         $this->data['exception']['debug'] = $data;
         $this->respond();
      }
   }
   public function respond ()
   {
      error_reporting(0);

      WPI::$Response->Json->send($this->data, JSON_PRETTY_PRINT);

      if (\PHP_SAPI !== 'cli') {
         exit;
      }
   }
}
