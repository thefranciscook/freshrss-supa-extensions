# freshrss-supa-extensions

Custom [FreshRSS](https://freshrss.org) extensions, plus a one-command local
FreshRSS instance for developing them.

## Local development

Requirements: Docker Desktop. Nothing else (no local PHP needed).

```sh
make up        # starts FreshRSS at http://localhost:8080  (admin / admin123)
make logs      # tail Apache/PHP logs (PHP errors from your extension show up here)
make lint      # php -l every .php/.phtml under extensions/ using the container's PHP
make refresh   # fetch feeds now (cron refresh is disabled in dev)
make shell     # sh inside the container, FreshRSS root is /var/www/FreshRSS
make down      # stop, keep data
make reset     # stop AND wipe data -> clean reinstall on next `make up`
```

How it works:

- `./extensions` is bind-mounted to `/var/www/FreshRSS/extensions` in the
  container. Edit a file on your Mac, reload the page, done. No restart.
- The first `make up` auto-installs FreshRSS with SQLite and creates the
  `admin` user (see `FRESHRSS_INSTALL` / `FRESHRSS_USER` in `docker-compose.yml`).
  Those env vars are only read on the very first start; run `make reset`
  after changing them.
- Pin the image to your VPS version to avoid surprises:
  `FRESHRSS_TAG=1.30.1 make up` (default is `latest`).

### Enabling an extension

1. Log in, go to **Settings → Extensions** (user-type extensions) or
   **Administration → Extensions** (system-type).
2. Click the toggle next to the extension, then the gear icon to open its
   `configure.phtml` form.
3. Add a feed, hit `make refresh`, open an article. The sample extension
   shows a banner above every article body.

Enable/disable state lives in the `data` volume, not in `extensions/`, so
`make reset` also clears it.

### Extension anatomy

```
extensions/xExtension-<Name>/
├── metadata.json       name, author, version, "entrypoint": "<Name>", "type": user|system
├── extension.php       final class <Name>Extension extends Minz_Extension { init() … }
├── configure.phtml     optional settings form, handled by handleConfigureAction()
├── i18n/en/ext.php     optional translations -> _t('ext.<name>.key')
├── static/             css/js served via $this->getFileUrl('style.css', 'css')
├── Controllers/        optional, registerController('<Name>')
└── views/              optional, registerViews()
```

Hooks are registered in `init()` with `$this->registerHook(Minz_HookType::X, callable)`.
The full list of hook types and their callback signatures is in
`lib/Minz/HookType.php` inside the container (`make shell`). Common ones:

| Hook | Callback |
|---|---|
| `EntryBeforeDisplay` | `(FreshRSS_Entry) -> FreshRSS_Entry\|null` (null drops the entry) |
| `EntryBeforeInsert` | `(FreshRSS_Entry) -> FreshRSS_Entry\|null` |
| `FeedBeforeActualize` | `(FreshRSS_Feed) -> FreshRSS_Feed\|null` |
| `SimplepieBeforeInit` | `(FreshRSS_SimplePieCustom, FreshRSS_Feed) -> void` |
| `JsVars` | `(array) -> array` |
| `NavMenu`, `MenuConfigurationEntry` | `() -> string` (HTML) |

Official docs: <https://freshrss.github.io/FreshRSS/en/developers/03_Backend/05_Extensions.html>

`xExtension-HelloWorld` is a minimal working example to copy from.

## Deploying to the VPS

Extensions are just folders. Either:

- **Docker on the VPS:** mount this repo's `extensions/` (or a clone of it)
  to `/var/www/FreshRSS/extensions`, same as `docker-compose.yml` here, and
  `git pull` to update.
- **Bare-metal install:** copy or `git clone` into `<freshrss>/extensions/`
  and make sure the web server user can read it. FreshRSS also supports
  installing third-party extensions by Git URL from the Extensions page.

After updating, disable and re-enable the extension if `install()` logic changed.
