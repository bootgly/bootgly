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


interface HTTP2
{
   // @ Connection preface sent by clients before any frame (RFC 9113 §3.4).
   public const string PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

   // @ Frame types (RFC 9113 §6).
   public const int FRAME_DATA          = 0x0;
   public const int FRAME_HEADERS       = 0x1;
   public const int FRAME_PRIORITY      = 0x2;
   public const int FRAME_RST_STREAM    = 0x3;
   public const int FRAME_SETTINGS      = 0x4;
   public const int FRAME_PUSH_PROMISE  = 0x5;
   public const int FRAME_PING          = 0x6;
   public const int FRAME_GOAWAY        = 0x7;
   public const int FRAME_WINDOW_UPDATE = 0x8;
   public const int FRAME_CONTINUATION  = 0x9;

   // @ Frame flags (RFC 9113 §6; meaning depends on the frame type).
   public const int FLAG_ACK         = 0x01; // SETTINGS, PING
   public const int FLAG_END_STREAM  = 0x01; // DATA, HEADERS
   public const int FLAG_END_HEADERS = 0x04; // HEADERS, PUSH_PROMISE, CONTINUATION
   public const int FLAG_PADDED      = 0x08; // DATA, HEADERS, PUSH_PROMISE
   public const int FLAG_PRIORITY    = 0x20; // HEADERS

   // @ Settings identifiers (RFC 9113 §6.5.2).
   public const int SETTINGS_HEADER_TABLE_SIZE      = 0x1;
   public const int SETTINGS_ENABLE_PUSH            = 0x2;
   public const int SETTINGS_MAX_CONCURRENT_STREAMS = 0x3;
   public const int SETTINGS_INITIAL_WINDOW_SIZE    = 0x4;
   public const int SETTINGS_MAX_FRAME_SIZE         = 0x5;
   public const int SETTINGS_MAX_HEADER_LIST_SIZE   = 0x6;
}
