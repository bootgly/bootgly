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


trait Bootable
{
   protected function prepare (? string $resource = null) : self
   {
      if ($this->initied === false) {
         $this->source = null;
         $this->type   = null;

         $this->body   = null;

         $this->initied = true;
      }

      if ($resource === null) {
         $resource = $this->resource;
      }
      else {
         $resource = \strtolower($resource);
      }

      switch ($resource) {
         // @ Content
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

         // @ File
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

   protected function process ($data, ? string $resource = null) : self
   {
      if ($resource === null) {
         $resource = $this->resource;
      }
      else {
         $resource = \strtolower($resource);
      }

      switch ($resource) {
         // @ Response Content
         case 'json':
         case 'jsonp':
            if ( \is_array($data) ) {
               $this->body = $data;
               break;
            }

            $this->body = \json_decode($data, true);

            break;
         case 'pre':
            if ($data === null) {
               $data = $this->body;
            }

            $this->body = '<pre>'.$data.'</pre>';

            break;

         // @ Response File
         case 'view':
            $File = new File(BOOTGLY_PROJECT?->path . 'views/' . $data);

            $this->source = 'file';
            $this->type   = $File->extension;

            $this->body   = $File;

            break;

         // @ Response Raw
         case 'raw':
            $this->body = $data;

            break;

         default:
            if ($resource) {
               // TODO Inject resource with custom process() created by user
            }
            else {
               switch ( \getType($data) ) {
                  case 'string':
                     // TODO check if string is a valid path
                     $File = match ($data[0]) {
                        #!
                        '/' => new File(BOOTGLY_WORKING_DIR . 'projects' . $data),
                        '@' => new File(BOOTGLY_WORKING_DIR . 'projects/' . $data),
                        default => new File(BOOTGLY_PROJECT?->path . $data)
                     };

                     $this->source = 'file';
                     $this->type   = $File->extension;

                     $this->body   = &$File;

                     break;
                  case 'object':
                     if ($data instanceof File) {
                        $File = $data;

                        $this->source = 'file';
                        $this->type   = $File->extension;

                        $this->body   = $File;
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
