<?php
// ! API
include 'API/Environment.php';
include 'API/Server.php';


// ! CLI
#include 'CLI.php';

// ? Commands
include 'CLI/Commands.php';
// ? Terminal
include 'CLI/Terminal.php';
// @
// Logging
include 'CLI/Terminal/@/Logger/Logging.php';      // @trait
// Tester
include 'CLI/Terminal/@/Tester/Tests.php';
include 'CLI/Terminal/@/Tester/Tests/Test.php';

// ? Terminal/Input
include 'CLI/Terminal/Input.php';
// ? Terminal/Output
include 'CLI/Terminal/Output.php';
// Cursor
include 'CLI/Terminal/Output/Cursor.php';
// Text
include 'CLI/Terminal/Output/Text.php';
// Viewport
include 'CLI/Terminal/Output/Viewport.php';
// _ components
// Alert
include 'CLI/Terminal/components/Alert/Alert.php';
// Menu
include 'CLI/Terminal/components/Menu/Item.php';
include 'CLI/Terminal/components/Menu/Items.php';
include 'CLI/Terminal/components/Menu/Menu.php';
include 'CLI/Terminal/components/Menu/Items/Option.php';
include 'CLI/Terminal/components/Menu/Items/Options.php';
include 'CLI/Terminal/components/Menu/Items/extensions/Headers/Header.php';
include 'CLI/Terminal/components/Menu/Items/extensions/Headers/Headers.php';
include 'CLI/Terminal/components/Menu/Items/extensions/Divisors/Divisor.php';
include 'CLI/Terminal/components/Menu/Items/extensions/Divisors/Divisors.php';
// Progress
include 'CLI/Terminal/components/Progress/Progress.php';
include 'CLI/Terminal/components/Progress/Bar/Bar.php';
// Table
include 'CLI/Terminal/components/Table/Table.php';
include 'CLI/Terminal/components/Table/Cells.php';
include 'CLI/Terminal/components/Table/Columns.php';
include 'CLI/Terminal/components/Table/Row.php';
include 'CLI/Terminal/components/Table/Rows.php';


// ! Web
#include 'Web.php';
include 'Web/Connections.php'; // @interface
include 'Web/Packages.php';    // @interface
include 'Web/Servers.php';     // @interface
// @Events
include 'Web/@/Events/Select.php';
// ? TCP
// ? TCP\Client
include 'Web/TCP/Client.php';
include 'Web/TCP/Client/Connections.php';
include 'Web/TCP/Client/Packages.php';
// @
include 'Web/TCP/Client/@/Process.php';
include 'Web/TCP/Client/@/CLI/Terminal.php';
// ? TCP\Client\Connection(s)
include 'Web/TCP/Client/Connections/Connection.php';

// ? TCP\Server
include 'Web/TCP/Server.php';
include 'Web/TCP/Server/Connections.php';
include 'Web/TCP/Server/Packages.php';
// @
include 'Web/TCP/Server/@/Process.php';
include 'Web/TCP/Server/@/CLI/Terminal.php';
// ? TCP\Server\Connection(s)
include 'Web/TCP/Server/Connections/Connection.php';
