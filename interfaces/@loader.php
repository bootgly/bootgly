<?php
// ! CLI
require 'CLI.php';
// @
// ? Escaping
require 'CLI/@/Escaping.php';                      // @trait
// cursor
require 'CLI/@/Escaping/cursor/Positioning.php';   // @trait
require 'CLI/@/Escaping/cursor/Visualizing.php';   // @trait
require 'CLI/@/Escaping/cursor/Shaping.php';       // @trait
// text
require 'CLI/@/Escaping/text/Formatting.php';      // @trait
require 'CLI/@/Escaping/text/Modifying.php';       // @trait
// viewport
require 'CLI/@/Escaping/viewport/Positioning.php'; // @trait
// ? Template
require 'CLI/@/Template.php';

// ? Commands
require 'CLI/Commands.php';
// ? Terminal
require 'CLI/Terminal.php';
// @
// Logging
require 'CLI/Terminal/@/Logger/Logging.php';      // @trait
// Tester
require 'CLI/Terminal/@/Tester/Tests.php';
require 'CLI/Terminal/@/Tester/Tests/Test.php';

// ? Terminal/Input
require 'CLI/Terminal/Input.php';
// ? Terminal/Output
require 'CLI/Terminal/Output.php';
// Cursor
require 'CLI/Terminal/Output/Cursor.php';
// Text
require 'CLI/Terminal/Output/Text.php';
// Viewport
require 'CLI/Terminal/Output/Viewport.php';
// @ components
// Progress
require 'CLI/Terminal/components/Progress.php';
require 'CLI/Terminal/components/Progress/Bar.php';
// Table
require 'CLI/Terminal/components/Table.php';
require 'CLI/Terminal/components/Table/Cells.php';
require 'CLI/Terminal/components/Table/Columns.php';
require 'CLI/Terminal/components/Table/Row.php';
require 'CLI/Terminal/components/Table/Rows.php';

// ! OS
require 'OS/Process/Timer.php';

// ! SAPI
require 'SAPI.php';
// ? Environment
require 'SAPI/Environment.php';

// ! Web
require 'Web.php';
require 'Web/Connections.php'; // @interface
require 'Web/Packages.php';    // @interface
require 'Web/Servers.php';     // @interface
// @Events
require 'Web/@/Events/Select.php';
// ? TCP
// ? TCP\Client
require 'Web/TCP/Client.php';
require 'Web/TCP/Client/Connections.php';
require 'Web/TCP/Client/Packages.php';
// @
require 'Web/TCP/Client/@/OS/Process.php';
require 'Web/TCP/Client/@/CLI/Terminal.php';
// ? TCP\Client\Connection(s)
require 'Web/TCP/Client/Connections/Connection.php';

// ? TCP\Server
require 'Web/TCP/Server.php';
require 'Web/TCP/Server/Connections.php';
require 'Web/TCP/Server/Packages.php';
// @
require 'Web/TCP/Server/@/OS/Process.php';
require 'Web/TCP/Server/@/CLI/Terminal.php';
// ? TCP\Server\Connection(s)
require 'Web/TCP/Server/Connections/Connection.php';
