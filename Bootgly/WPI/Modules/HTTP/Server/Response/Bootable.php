<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Response;


use function strtolower;
use function json_decode;
use function is_array;
use function getType;

use Bootgly\ABI\IO\FS\File;


trait Bootable
{
   // * Data
   // # Resource
   // @ Content
   public ?string $source;
   public ?string $type;

   // * Metadata
   // # Resource
   // @ Content
   private ?string $resource;
   // @ Status
   public bool $initied = false;
   public bool $prepared;
   public bool $processed;
   public bool $sent;


   protected function prepare (?string $resource = null): self
   {
      if ($this->initied === false) {
         $this->source  = null;
         $this->type    = null;

         $this->content = "";

         $this->initied = true;
      }

      if ($resource === null) {
         $resource = $this->resource;
      }
      else {
         $resource = strtolower($resource);
      }

      switch ($resource) {
         // Content
         case 'json':
            $this->source   = 'content';
            $this->type     = 'json';
            break;
         case 'jsonp':
            $this->source   = 'content';
            $this->type     = 'jsonp';
            break;
         case 'pre':
         case 'raw':
            $this->source   = 'content';
            $this->type     = '';
            break;

         // File
         case 'view':
            $this->source = 'file';
            $this->type = 'php';
            break;

         default:
            if ($resource) {
               // TODO inject Resource with custom prepare()
               // $prepared = $this->resources[$resource]->prepare();
               // $this->source = $prepared['source'];
               // $this->type = $prepared['type'];
            }
      }

      $this->prepared = true;

      return $this;
   }

   protected function process (mixed $data, ?string $resource = null): self
   {
      if ($resource === null) {
         $resource = $this->resource;
      }
      else {
         $resource = strtolower($resource);
      }

      switch ($resource) {
         // Content
         case 'json':
         case 'jsonp':
            if ( is_array($data) ) {
               $this->content = $data;
               break;
            }

            $this->content = json_decode($data, true);

            break;
         case 'pre':
            if ($data === null) {
               $data = $this->content;
            }

            $this->content = '<pre>'.$data.'</pre>';

            break;

         // File
         case 'view':
            $File = new File(BOOTGLY_PROJECT->path . 'views/' . $data);

            $this->source = 'file';
            $this->type   = $File->extension;

            $this->File   = $File;

            break;

         // Raw
         case 'raw':
            $this->content = $data;

            break;

         default:
            if ($resource) {
               // TODO Inject resource with custom process() created by user
            }
            else {
               switch ( getType($data) ) {
                  case 'string':
                     // TODO check if string is a valid path
                     $File = match ($data[0]) {
                        #!
                        '/' => new File(BOOTGLY_WORKING_DIR . 'projects' . $data),
                        '@' => new File(BOOTGLY_WORKING_DIR . 'projects/' . $data),
                        default => new File(BOOTGLY_PROJECT->path . $data)
                     };

                     $this->source = 'file';
                     $this->type   = $File->extension;

                     $this->File   = &$File;

                     break;
                  case 'object':
                     if ($data instanceof File) {
                        $File = $data;

                        $this->source = 'file';
                        $this->type   = $File->extension;

                        $this->File   = $File;
                     }

                     break;
               }
            }
      }

      $this->resource = null;

      $this->processed = true;

      return $this;
   }
}
