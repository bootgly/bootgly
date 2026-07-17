<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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


/**
 * Built-in preformatted text response formatter.
 */
class Pre extends Resource
{
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
    * Send preformatted content through the canonical Response sender.
    */
   public function send (mixed $body = null): Response
   {
      $content = $this->Response->Body->stringify($body);
      $content = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

      return $this->Response->send("<pre>{$content}</pre>");
   }
}
