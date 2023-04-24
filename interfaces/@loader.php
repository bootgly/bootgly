<?php
// ! API
require 'API/Environment.php';
require 'API/Server.php';


// ! CLI
require 'CLI.php';

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
// _ components
// Alert
require 'CLI/Terminal/components/Alert/Alert.php';
// Menu
require 'CLI/Terminal/components/Menu/Item.php';
require 'CLI/Terminal/components/Menu/Items.php';
require 'CLI/Terminal/components/Menu/Menu.php';
require 'CLI/Terminal/components/Menu/Items/Option.php';
require 'CLI/Terminal/components/Menu/Items/Options.php';
require 'CLI/Terminal/components/Menu/Items/extensions/Headers/Header.php';
require 'CLI/Terminal/components/Menu/Items/extensions/Headers/Headers.php';
require 'CLI/Terminal/components/Menu/Items/extensions/Divisors/Divisor.php';
require 'CLI/Terminal/components/Menu/Items/extensions/Divisors/Divisors.php';
// Progress
require 'CLI/Terminal/components/Progress/Progress.php';
require 'CLI/Terminal/components/Progress/Bar/Bar.php';
// Table
require 'CLI/Terminal/components/Table/Table.php';
require 'CLI/Terminal/components/Table/Cells.php';
require 'CLI/Terminal/components/Table/Columns.php';
require 'CLI/Terminal/components/Table/Row.php';
require 'CLI/Terminal/components/Table/Rows.php';


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
require 'Web/TCP/Client/@/Process.php';
require 'Web/TCP/Client/@/CLI/Terminal.php';
// ? TCP\Client\Connection(s)
require 'Web/TCP/Client/Connections/Connection.php';

// ? TCP\Server
require 'Web/TCP/Server.php';
require 'Web/TCP/Server/Connections.php';
require 'Web/TCP/Server/Packages.php';
// @
require 'Web/TCP/Server/@/Process.php';
require 'Web/TCP/Server/@/CLI/Terminal.php';
// ? TCP\Server\Connection(s)
require 'Web/TCP/Server/Connections/Connection.php';
