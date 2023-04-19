<p align="center">
  <img src="https://github.com/bootgly/.github/raw/main/favicon-temp1-128.png" alt="bootgly-logo" width="120px" height="120px"/>
</p>
<h1 align="center">Bootgly PHP Framework</h1>
<p align="center">
  <i>Full Stack PHP Framework for Multi Projects.</i>
</p>
<p align="center">
  <a href="https://packagist.org/packages/bootgly/bootgly">
    <img alt="Bootgly License" src="https://img.shields.io/github/license/bootgly/bootgly"/>
    <img alt="Github Actions" src="https://img.shields.io/github/actions/workflow/status/bootgly/bootgly/docker_http_server_cli-ci.yml?label=CI%2FCD"/>
  </a>
</p>

🚧 Do not use it in production environments. The alpha version hasn't even been released yet. 🚧

First beta release is planned for mid-year 2023.

Documentation will be released alongside the beta.

---

## ⚙️ Dependencies

- PHP 8.2+ `[Required]`
- Opcache with JIT enabled (+50% performance) `[Optional]`
- Linux OS (Debian based OS is recommended: Debian, Ubuntu...) `[Required]`

### Bootgly Web dependencies
- Rewrite enabled `[Required if you are using a Non-CLI SAPI only]`

---

## 🔧 Usage

### Running a HTTP Server in Bootgly Web:

#### **Option 1: Non-CLI SAPI (Apache, LiteSpeed, Nginx, etc)**

1) Enable support to Rewrite;
2) Rename the file `projects/web.constructor.php.example` to `projects/web.constructor.php`;
3) Configure the Web constructor in `projects/web.constructor.php` file;
4) Run the Non-CLI HTTP Server pointing to `index.php`.

#### **Option 2: CLI SAPI**

Directly in Linux OS *(max performance)*:

1) Configure the Bootgly HTTP Server in `server.http.php` file;
2) Rename `projects/cli.http-server.api.php.example` to `projects/cli.http-server.api.php`;
3) Configure Server API in `projects/cli.http-server.api.php` file;
4) Run the Bootgly HTTP Server CLI in the terminal:

`php \@/scripts/http-server-cli.php`

--

or using Docker:

1) Pull the Bootgly image from Docker Hub:

`docker pull bootgly/http-server-cli`

2) Run the Bootgly container in interactive mode:

`docker run -it --network host bootgly/http-server-cli`

---

## 🖼 Preview

**Screenshots** and **videos** can be found in the home page of [Bootgly Docs][PROJECT_DOCS].

---

## 🌱 Community

Join us and help the community.

**Love Bootgly? Give [our repo][GITHUB_REPOSITORY] a star ⭐!**

### 💖 Sponsorship

A lot of time and energy is devoted to Bootgly projects. To accelerate your growth, if you like this project or depend on it for your stack to work, consider [sponsoring it][GITHUB_SPONSOR].

Your sponsorship will keep this project always **up to date** with **new features** and **improvements** / **bug fixes**.

### 🔗 Social networks
- [Bootgly on Telegram][TELEGRAM]
- [Bootgly on Reddit][REDDIT]
- [Bootgly on Discord][DISCORD]

---

## 💻 Contributing

Wait for the "contributing guidelines" to start your contribution.

### 🛂 Code of Conduct

Help us keep Bootgly open and inclusive. Please read and follow our [Code of Conduct][CODE_OF_CONDUCT].

### 📑 Versioning

Bootgly PHP Framework will follow [Semantic Versioning 2.0][SEMANTIC_VERSIONING].

---

## 📃 License

The Bootgly PHP Framework is open-sourced software licensed under the [MIT license][MIT_LICENSE].


<!-- Links -->
[TEMPLATE_ENGINE]: https://github.com/bootgly/bootgly/blob/main/core/Template.php

[WEB_TCP_CLIENT_INTERFACE]: https://github.com/bootgly/bootgly/blob/main/interfaces/Web/TCP/Client.php
[WEB_TCP_SERVER_INTERFACE]: https://github.com/bootgly/bootgly/blob/main/interfaces/Web/TCP/Server.php

[CLI_HTTP_SERVER]: https://github.com/bootgly/bootgly/blob/main/nodes/CLI/HTTP/Server.php
[CLI_HTTP_SERVER_REQUEST]: https://github.com/bootgly/bootgly/blob/main/nodes/CLI/HTTP/Server/Request.php
[CLI_HTTP_SERVER_RESPONSE]: https://github.com/bootgly/bootgly/blob/main/nodes/CLI/HTTP/Server/Response.php
[WEB_HTTP_SERVER_ROUTER]: https://github.com/bootgly/bootgly/blob/main/nodes/Web/HTTP/Server/Router.php
[WEB_HTTP_SERVER_ROUTER_ROUTE]: https://github.com/bootgly/bootgly/blob/main/nodes/Web/HTTP/Server/Router/Route.php


[PROJECT_DOCS]: https://docs.bootgly.com/
[GITHUB_REPOSITORY]: https://github.com/bootgly/bootgly/
[GITHUB_SPONSOR]: https://github.com/sponsors/bootgly/

[TELEGRAM]: https://t.me/bootgly/
[REDDIT]: https://www.reddit.com/r/bootgly/
[DISCORD]: https://discord.gg/SKRHsYmtyJ/


[CODE_OF_CONDUCT]: CODE_OF_CONDUCT.md
[SEMANTIC_VERSIONING]: https://semver.org/


[MIT_LICENSE]: https://opensource.org/license/mit/
