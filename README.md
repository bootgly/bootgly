<p align="center">
  <img src="https://github.com/bootgly/.github/raw/main/favicon-temp1-128.png" alt="bootgly-logo" width="120px" height="120px"/>
</p>
<h1 align="center">Bootgly</h1>
<p align="center">
  <i>Base PHP Framework for Multi Projects.</i>
</p>
<p align="center">
  <a href="https://packagist.org/packages/bootgly/bootgly">
    <img alt="Bootgly License" src="https://img.shields.io/github/license/bootgly/bootgly"/>
    <img alt="Github Actions - Bootgly Workflow" src="https://img.shields.io/github/actions/workflow/status/bootgly/bootgly/bootgly.yml?label=bootgly"/>
    <img alt="Github Actions - Docker Workflow" src="https://img.shields.io/github/actions/workflow/status/bootgly/bootgly/docker.yml?label=docker"/>
  </a>
</p>

🚧
Do not use it in production environments.

The first beta is planned for mid-year 2023 (near the end of July).

[Documentation is under construction][PROJECT_DOCS] and will be released alongside the beta.
🚧

## Table of Contents

- [About](#-about)
  - [Bootgly CLI 📟](#bootgly-cli-)
  - [Bootgly Web 🌐](#bootgly-web-)
- [Boot](#-boot)
  - [Compatibility](#-compatibility)
  - [Dependencies](#️-dependencies)
    - [Bootgly CLI 📟](#-bootgly-cli-)
    - [Bootgly Web 🌐](#-bootgly-web-)
- [Community](#-community)
  - [Contributing](#-contributing)
  - [Code of Conduct](#-code-of-conduct)
  - [Social Networks](#-social-networks)
  - [Sponsorship](#-sponsorship)
- [Compliances](#-compliances)
  - [License](#-license)
  - [Versioning](#-versioning)
- [Highlights](#-highlights)
  - [Bootgly CLI 📟](#-bootgly-cli-)
  - [Bootgly Web 🌐](#-bootgly-web-)
- [Usage](#-usage)
  - [Bootgly CLI 📟](#-bootgly-cli-)
    - [Run CLI demo](#run-cli-demo)
  - [Bootgly Web 🌐](#-bootgly-web-)
    - [Running a HTTP Server](#running-a-http-server)

---

## 🤔 About

Bootgly is a base framework for developing APIs and Apps for both command-line interfaces (CLI) 📟 and Web 🌐.
Focused on performance, versatility, and easy-to-understand codebase APIs.

### Bootgly CLI 📟

interfaces | platforms
--- | ---
[Terminal Input][CLI_TERMINAL_INTERFACE_INPUT] | Console (TODO)
[Terminal Output][CLI_TERMINAL_INTERFACE_OUTPUT] | 

Terminal components |
--- |
[Alert component][CLI_TERMINAL_ALERT] | 
[Menu component][CLI_TERMINAL_MENU] | 
[Progress component][CLI_TERMINAL_PROGRESS] | 
[Table component][CLI_TERMINAL_TABLE] | 


### Bootgly Web 🌐

Web interfaces | Web nodes
--- | ---
[TCP Client][WEB_TCP_CLIENT_INTERFACE] | HTTP Client (TODO) 
[TCP Server][WEB_TCP_SERVER_INTERFACE] | [HTTP Server][WEB_HTTP_SERVER_NODE]

---

## 🟢 Boot

### 🤝 Compatibility

Operation System |
--- |
✅ Linux (Debian based) |
❌ Windows |
❔ Unix |

--

✅ = Compatible
❌ = Incompatible
❔ = Untested

Above is the native compatibility, of course it is possible to run on Windows and Unix using containers.

### ⚙️ Dependencies

- PHP 8.2+ ⚠️
- Opcache with JIT enabled (+50% performance) 👍

#### \- Bootgly CLI 📟
- `php-cli` ⚠️
- `php-readline` ⚠️

#### \- Bootgly Web 🌐

##### CLI + Web *API ¹ (eg. Bootgly HTTP Server CLI):
- \* See Bootgly CLI dependencies \*

##### Web in Non-CLI (apache2handler, litespeed and nginx) SAPI ²:
- `rewrite` module enabled ⚠️

--

⚠️ = Required
👍 = Recommended

¹ *API = Can be Server API (SAPI), Client API (CAPI), etc.
² SAPI = Server API

---

## 🌱 Community

Join us and help the community.

**Love Bootgly? Give [our repo][GITHUB_REPOSITORY] a star ⭐!**

### 💻 Contributing

Wait for the "contributing guidelines" to start your contribution.

#### 🛂 Code of Conduct

Help us keep Bootgly open and inclusive. Please read and follow our [Code of Conduct][CODE_OF_CONDUCT].

### 🔗 Social networks
- [Bootgly on Telegram][TELEGRAM]
- [Bootgly on Reddit][REDDIT]
- [Bootgly on Discord][DISCORD]

### 💖 Sponsorship

A lot of time and energy is devoted to Bootgly projects. To accelerate your growth, if you like this project or depend on it for your stack to work, consider [sponsoring it][GITHUB_SPONSOR].

Your sponsorship will keep this project always **up to date** with **new features** and **improvements** / **bug fixes**.

---

## 📝 Compliances

### 📃 License

The Bootgly is open-sourced software licensed under the [MIT license][MIT_LICENSE].

### 📑 Versioning

Bootgly will follow [Semantic Versioning 2.0][SEMANTIC_VERSIONING].

---

## 🖼 Highlights

### \- Bootgly CLI 📟

| ![HTTP Server CLI started - Initial output](https://github.com/bootgly/.github/raw/main/screenshots/bootgly-php-framework/Bootgly-Progress-Bar-component.png "Render 6x faster than Symfony / Laravel") |
|:--:| 
| *Progress component (with Bar) - Render 6x faster than Symfony / Laravel* |

### \- Bootgly Web 🌐

| ![HTTP Server CLI - Faster than Workerman +7%](https://github.com/bootgly/.github/raw/main/screenshots/bootgly-php-framework/Server-CLI-HTTP-Benchmark-Ryzen-9-3900X-WSL2.png "HTTP Server CLI - +7% faster than Workerman (Plain Text test)'") |
|:--:| 
| *HTTP Server CLI - +7% faster than [Workerman](https://www.techempower.com/benchmarks/#section=data-r21&test=plaintext&l=zik073-6bj) (Plain Text test)* |

More **Screenshots**, videos and details can be found in the home page of [Bootgly Docs][PROJECT_DOCS].

---

## 🔧 Usage

### \- Bootgly CLI 📟:

<details>
  <summary>Run CLI demo</summary>

  1) See the examples in `projects/Bootgly/CLI/examples/`;
  2) Check the file `projects/CLI.constructor.php`;
  3) Run the Bootgly CLI demo in terminal:

  `php bootgly demo`
</details>

### \- Bootgly Web 🌐:

<details>
  <summary>Running a HTTP Server:</summary>

  ##### **Option 1: Non-CLI SAPI (Apache, LiteSpeed, Nginx, etc)**

  1) Enable support to `rewrite`;
  2) Configure the WPI constructor in `projects/WPI.constructor.php` file;
  3) Run the Non-CLI HTTP Server pointing to `index.php`.

  ##### **Option 2: CLI SAPI**

  Directly in Linux OS *(max performance)*:

  1) Configure the Bootgly HTTP Server script in `scripts/http-server-cli` file;
  2) Configure the HTTP Server API in `projects/Bootgly/Web/HTTP-Server.API.php` file;
  3) Run the Bootgly HTTP Server CLI in the terminal:

  `php scripts/http-server-cli`

  --

  or using Docker:

  1) Pull the image:

  `docker pull bootgly/http-server-cli`

  2) Run the container in interactive mode and in the host network for max performance:

  `docker run -it --network host bootgly/http-server-cli`
</details>



<!-- Links -->
[CLI_TERMINAL_INTERFACE_INPUT]: https://github.com/bootgly/bootgly/blob/main/Bootgly/CLI/Terminal/Input.php
[CLI_TERMINAL_INTERFACE_OUTPUT]: https://github.com/bootgly/bootgly/blob/main/Bootgly/CLI/Terminal/Output.php
[CLI_TERMINAL_COMPONENTS]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components

[CLI_TERMINAL_ALERT]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Alert
[CLI_TERMINAL_MENU]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Menu
[CLI_TERMINAL_PROGRESS]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Progress
[CLI_TERMINAL_TABLE]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Table

[WEB_TCP_CLIENT_INTERFACE]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/interfaces/TCP/Client.php
[WEB_TCP_SERVER_INTERFACE]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/interfaces/TCP/Server.php
[WEB_HTTP_SERVER_NODE]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/nodes/HTTP/Server.php


[PROJECT_DOCS]: https://docs.bootgly.com/
[GITHUB_REPOSITORY]: https://github.com/bootgly/bootgly/
[GITHUB_SPONSOR]: https://github.com/sponsors/bootgly/

[TELEGRAM]: https://t.me/bootgly/
[REDDIT]: https://www.reddit.com/r/bootgly/
[DISCORD]: https://discord.gg/SKRHsYmtyJ/


[CODE_OF_CONDUCT]: CODE_OF_CONDUCT.md
[SEMANTIC_VERSIONING]: https://semver.org/


[MIT_LICENSE]: https://opensource.org/license/mit/
