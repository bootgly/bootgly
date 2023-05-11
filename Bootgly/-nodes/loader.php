<?php
/*
// * CLI
// ! HTTP Server
include 'CLI/HTTP/Server.php';
// ? Request
include 'CLI/HTTP/Server/Request.php';
// @
include 'CLI/HTTP/Server/Request/@/Meta.php';
include 'CLI/HTTP/Server/Request/@/Header.php';
include 'CLI/HTTP/Server/Request/@/Header/Cookie.php';
include 'CLI/HTTP/Server/Request/@/Content.php';

include 'CLI/HTTP/Server/Request/Downloader.php';
// ? Response
include 'CLI/HTTP/Server/Response.php';
// @
include 'CLI/HTTP/Server/Response/Content.php';
include 'CLI/HTTP/Server/Response/Header.php';
include 'CLI/HTTP/Server/Response/Header/Cookie.php';
include 'CLI/HTTP/Server/Response/Meta.php';
*/

// * Web
// ! HTTP Server
include 'Web/HTTP/Server.php';
// ? Request
include 'Web/HTTP/Server/Request.php';
// @
include 'Web/HTTP/Server/Request/@/Meta.php';
include 'Web/HTTP/Server/Request/@/Header.php';
include 'Web/HTTP/Server/Request/@/Header/Cookie.php';
include 'Web/HTTP/Server/Request/@/Content.php';

include 'Web/HTTP/Server/Request/Downloader.php';
#include 'Web/HTTP/Server/Request/Session.php';
// ? Response
include 'Web/HTTP/Server/Response.php';
// @
include 'Web/HTTP/Server/Response/Content.php';
include 'Web/HTTP/Server/Response/Header.php';
include 'Web/HTTP/Server/Response/Header/Cookie.php';
include 'Web/HTTP/Server/Response/Meta.php';
// ? Router
#include 'Web/HTTP/Server/Router.php';
#include 'Web/HTTP/Server/Router/@constants.php';
#include 'Web/HTTP/Server/Router/Route.php';
