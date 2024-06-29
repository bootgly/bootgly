<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Response\Raw;


/**
 * @property string $protocol
 * @property string $status
 * 
 * @property string $raw
 * @property int $code
 * @property string $message
 */
abstract class Ack
{
   // * Config
   // ...

   // * Data
   protected string $protocol = 'HTTP/1.1';
   protected string $status = '200 OK';

   // * Metadata
   protected string $raw;
   // @ status
   protected int $code;
   protected string $message;
}
