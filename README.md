# KraftInk

KraftInk is a self-hosted CMS for game communities. Create a website for your server, publish news, manage member accounts and choose a theme from the administration panel.

[Website](https://kraftink.com/en) · [Downloads](https://kraftink.com/en/downloads) · [Documentation](https://kraftink.com/en/documentation) · [Discord](https://discord.kraftink.com)

## What you can do

- Publish pages and announcements, and manage access with roles and permissions.
- Connect a supported game server to show its status or deliver commands and rewards. [Supported games and connections](https://kraftink.com/en/documentation/games)
- Add a shop, voting page, wiki, forum or support tickets with [plugins and themes](https://kraftink.com/en/market).

## Install on a web host

Download the `KraftInk-VERSION.zip` installation archive from the [downloads page](https://kraftink.com/en/downloads). It includes the PHP dependencies and compiled assets.

1. Extract the archive on your hosting account.
2. Set your domain’s document root to the `public/` directory inside the extracted files.
3. Open the site over HTTPS and follow the setup wizard.

You’ll need 64-bit PHP 8.4.1 or newer and SQLite, MySQL, MariaDB or PostgreSQL. The [installation guide](https://kraftink.com/en/documentation/installation) covers the full requirements and server setup.

## Run with Docker

With Docker Compose installed, run this command from the extracted archive.

```shell
docker compose up -d --build
```

Open [http://localhost:8080](http://localhost:8080) to finish setup. To start KraftInk with the included MariaDB service, see the [Docker Compose guide](https://kraftink.com/en/documentation/installation#docker-compose).

## Get help

Read the [documentation](https://kraftink.com/en/documentation) for setup and administration, or ask the community on [Discord](https://discord.kraftink.com).
