<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use function htmlspecialchars;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Releasable;


/**
 * Built-in preformatted text response formatter.
 */
class Pre extends Resource
{
   use Releasable;


   // * Config
   // ...

   // * Data
   protected Response $Response;

   // * Metadata
   // ...


   public function __construct (Response $Response)
   {
      parent::__construct(persistent: true);

      // * Data
      $this->Response = $Response;
   }

   /**
    * Let the response go when the deferred job it served ends (Releasable).
    */
   public function clean (): void
   {
      $this->release();
   }

   /**
    * Send preformatted content through the canonical Response sender.
    */
   public function send (mixed $body = null): Response
   {
      $Response = $this->Response ?? $this->resolve(); // @phpstan-ignore nullCoalesce.initializedProperty

      $content = $Response->Body->stringify($body);
      $content = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

      return $Response->send("<pre>{$content}</pre>");
   }
}
