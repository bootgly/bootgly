<?php
// ! CLI
require 'CLI.php';
// ? Command
require 'CLI/Command.php';
// ? Terminal
require 'CLI/Terminal.php';
// resources
require 'CLI/Terminal/text/Formatting.php'; // @ trait
// sources
require 'CLI/Terminal/@/Logger/Logging.php'; // @trait
require 'CLI/Terminal/@/Tester/Tests.php';
require 'CLI/Terminal/@/Tester/Tests/Test.php';

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
