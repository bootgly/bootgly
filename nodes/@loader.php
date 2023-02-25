<?php
// * CLI
// ! HTTP Server
require 'CLI/HTTP/Server.php';
// ? Request
require 'CLI/HTTP/Server/Request.php';
// @
require 'CLI/HTTP/Server/Request/@/Meta.php';
require 'CLI/HTTP/Server/Request/@/Header.php';
require 'CLI/HTTP/Server/Request/@/Header/Cookie.php';
require 'CLI/HTTP/Server/Request/@/Content.php';

require 'CLI/HTTP/Server/Request/Downloader.php';
// ? Response
require 'CLI/HTTP/Server/Response.php';
// @
require 'CLI/HTTP/Server/Response/Content.php';
require 'CLI/HTTP/Server/Response/Header.php';
require 'CLI/HTTP/Server/Response/Header/Cookie.php';
require 'CLI/HTTP/Server/Response/Meta.php';

// * Web
// ! HTTP Server
require 'Web/HTTP/Server.php';
// ? Request
require 'Web/HTTP/Server/Request.php';
// @
require 'Web/HTTP/Server/Request/@/Meta.php';
require 'Web/HTTP/Server/Request/@/Header.php';
require 'Web/HTTP/Server/Request/@/Header/Cookie.php';
require 'Web/HTTP/Server/Request/@/Content.php';

require 'Web/HTTP/Server/Request/Session.php';
// ? Response
require 'Web/HTTP/Server/Response.php';
// @
require 'Web/HTTP/Server/Response/Content.php';
require 'Web/HTTP/Server/Response/Header.php';
require 'Web/HTTP/Server/Response/Header/Cookie.php';
require 'Web/HTTP/Server/Response/Meta.php';
// ? Router
require 'Web/HTTP/Server/Router.php';
require 'Web/HTTP/Server/Router/@constants.php';
require 'Web/HTTP/Server/Router/Route.php';
