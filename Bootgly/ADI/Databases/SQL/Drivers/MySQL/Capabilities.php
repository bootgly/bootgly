<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Drivers\MySQL;


/**
 * MySQL client/server capability flags and status bits.
 */
final class Capabilities
{
   // # Capability flags (protocol::CapabilityFlags)
   public const int LONG_PASSWORD = 0x00000001;
   public const int FOUND_ROWS = 0x00000002;
   public const int LONG_FLAG = 0x00000004;
   public const int CONNECT_WITH_DB = 0x00000008;
   public const int COMPRESS = 0x00000020;
   public const int LOCAL_FILES = 0x00000080;
   public const int PROTOCOL_41 = 0x00000200;
   public const int SSL = 0x00000800;
   public const int TRANSACTIONS = 0x00002000;
   public const int SECURE_CONNECTION = 0x00008000;
   public const int MULTI_STATEMENTS = 0x00010000;
   public const int MULTI_RESULTS = 0x00020000;
   public const int PS_MULTI_RESULTS = 0x00040000;
   public const int PLUGIN_AUTH = 0x00080000;
   public const int CONNECT_ATTRS = 0x00100000;
   public const int PLUGIN_AUTH_LENENC = 0x00200000;
   public const int SESSION_TRACK = 0x00800000;
   public const int DEPRECATE_EOF = 0x01000000;

   // # Server status bits (protocol::StatusFlags)
   public const int STATUS_TRANSACTION = 0x0001;
   public const int STATUS_AUTOCOMMIT = 0x0002;
   public const int STATUS_MORE_RESULTS = 0x0008;

   // # Charset
   /** utf8mb4_general_ci — valid on MySQL 5.7+, 8.x and MariaDB. */
   public const int CHARSET_UTF8MB4 = 45;
}
