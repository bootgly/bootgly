# Bootgly PHP Framework (v0.0.1-pre-alpha)

**Full Stack PHP Framework for Multi Projects (WIP)**

🚧 Do not use it in production environments. The alpha version hasn't even been released yet. 🚧

*Temporary repository created for Github Accelerator.*

First beta release is planned for mid-year 2023.

Documentation and Website will be released alongside the beta.

Servers | Database | Back-end | Front-end
--- | --- | --- | ---
[TCP Server (WIP)](/interfaces/Web/TCP/Server.php) | ORM (TODO) | [Router (WIP)](/nodes/Web/HTTP/Server/Router.php) | [Templating Engine (WIP)](/core/Template.php)
[HTTP Server (WIP)](/nodes/Web/HTTP/Server.php) | _ | [Router/Route (WIP)](/nodes/Web/HTTP/Server/Router/Route.php) | _
_ | _ | [Request (WIP)](/nodes/Web/HTTP/Server/Request.php) | _
_ | _ | [Response (WIP)](/nodes/Web/HTTP/Server/Response.php) | _

## Requirements

- PHP 8.2+
- Apache Rewrite compatibility in Non-CLI Web Servers

## Usage

1) git clone https://github.com/rodrigoslayertech/bootgly-php-framework
2) rename `projects/web.constructor.php.example` to `projects/web.constructor.php`
3) active Rewrite if you are using any server other than native CLI like Apache, LiteSpeed, Nginx, etc.

### HTTP Server CLI

php server@http.php

## License

The Bootgly PHP Framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
