<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP2;


/**
 * HTTP/2 error codes (RFC 9113 §7), carried by RST_STREAM and GOAWAY frames.
 */
enum Errors: int
{
   case None               = 0x0;
   case Protocol           = 0x1;
   case Internal           = 0x2;
   case FlowControl        = 0x3;
   case SettingsTimeout    = 0x4;
   case StreamClosed       = 0x5;
   case FrameSize          = 0x6;
   case RefusedStream      = 0x7;
   case Cancel             = 0x8;
   case Compression        = 0x9;
   case Connect            = 0xa;
   case EnhanceYourCalm    = 0xb;
   case InadequateSecurity = 0xc;
   case HTTP11Required     = 0xd;
}
