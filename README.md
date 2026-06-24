Bolt Extensions site (Bolt 6)
=============================

This is the [Bolt CMS][bolt] extensions & themes registry site. It runs on
**Bolt 6** (Symfony 6.4, PHP 8.2+) and periodically pulls the list of Bolt
extensions and themes from [Packagist][packagist].

Requirements
------------

- PHP **8.2** or higher
- [Composer][composer] 2.x
- [Symfony CLI][symfony-cli] (recommended, for the local web server)
- A database — **SQLite** works out of the box (default); MySQL/MariaDB or
  PostgreSQL are also supported via `DATABASE_URL`.

Local setup
-----------

Clone the repository, then from the project root run these **three commands**:

```bash
composer install         # 1. install dependencies (runs Bolt's post-install scripts)
bin/console bolt:setup   # 2. create the database schema and the first admin user
symfony server:start -d  # 3. start the local web server (http://127.0.0.1:8088)
```

The committed `.env` defaults to SQLite, so no database server is required. To
use MySQL/MariaDB or PostgreSQL instead, set `DATABASE_URL` in a local
`.env.local` (which is git-ignored) rather than editing `.env`.

Notes:

- `bin/console bolt:setup` creates the schema and prompts you to create the
  first admin user. Add `-f` to also load demo fixtures, or `-nf` to skip user
  creation and start with an empty database.
- Instead of the Symfony CLI you can use the Makefile helper, which serves on
  port **8088**:

  ```bash
  make server        # start  -> http://127.0.0.1:8088
  make server-stop   # stop
  ```

Open the site at the URL printed by the server. The Bolt admin panel is at
`…/bolt` (e.g. http://127.0.0.1:8088/bolt). Log in with the user you created
during `bolt:setup`.

Loading the extensions & themes data
------------------------------------

The package registry is populated from Packagist by two custom console
commands (defined in `src/PackagistExtension.php`):

```bash
bin/console app:list extension   # create records for all `bolt-extension` packages
bin/console app:list theme       # create records for all `bolt-theme` packages
bin/console app:update           # enrich every record with details from Packagist
```

`app:list` creates lightweight stub records; `app:update` fills in the
description, version, downloads, stars, maintainers, etc. Run all three after a
fresh `bolt:setup` to populate the site, and re-run `app:update` periodically
(e.g. via cron) to keep the data current.

[bolt]: https://boltcms.io
[packagist]: https://packagist.org
[composer]: https://getcomposer.org
[symfony-cli]: https://symfony.com/download
