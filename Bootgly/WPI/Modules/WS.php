<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules;


interface WS
{
   // @ Handshake magic GUID (RFC 6455 §1.3) appended to Sec-WebSocket-Key.
   public const string HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

   // @ Frame opcodes (RFC 6455 §5.2).
   public const int OPCODE_CONTINUATION = 0x0;
   public const int OPCODE_TEXT         = 0x1;
   public const int OPCODE_BINARY       = 0x2;
   public const int OPCODE_CLOSE        = 0x8;
   public const int OPCODE_PING         = 0x9;
   public const int OPCODE_PONG         = 0xA;

   // @ Close status codes (RFC 6455 §7.4.1).
   public const array CLOSE_CODES = [
      1000 => 'Normal Closure',
      1001 => 'Going Away',
      1002 => 'Protocol Error',
      1003 => 'Unsupported Data',
      1007 => 'Invalid Frame Payload Data',
      1008 => 'Policy Violation',
      1009 => 'Message Too Big',
      1010 => 'Mandatory Extension',
      1011 => 'Internal Server Error',
   ];
}
