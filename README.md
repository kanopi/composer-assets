# kanopi/composer-assets

A **CMS-agnostic Composer plugin that scaffolds asset files** from dependency
packages into your project. It is a framework-neutral take on
[`drupal/core-composer-scaffold`](https://github.com/drupal/core-composer-scaffold):
same proven configuration shape, but with **no CMS-specific defaults**, so you
can use it for Drupal, WordPress, or any Composer-managed PHP project.

## What it does

During `composer install` / `composer update` (or on demand via
`composer assets`), the plugin reads `extra.composer-assets.file-mapping` from
your project and from any **allowed** dependency, then copies, symlinks, or
appends those files into your project tree.

Typical uses: dropping in `.htaccess`, `index.php`, `robots.txt`, `wp-config`
stubs, settings files, CI configs, or any boilerplate that lives in a reusable
"profile"/"recipe" package.

## Installation

```bash
composer require kanopi/composer-assets
```

Composer will ask you to trust the plugin. To pre-approve it in CI:

```json
{
    "config": {
        "allow-plugins": { "kanopi/composer-assets": true }
    }
}
```

## Configuration

All configuration lives under `extra.composer-assets`:

```json
{
    "extra": {
        "composer-assets": {
            "allowed-packages": ["acme/site-recipe"],
            "symlink": false,
            "gitignore": true,
            "file-mapping": {
                "web/.htaccess": "assets/.htaccess",
                "web/robots.txt": { "path": "assets/robots.txt", "overwrite": false },
                "web/sites/default/settings.php": false,
                "web/.htaccess-extra": {
                    "prepend": "assets/htaccess-header.txt",
                    "append": "assets/htaccess-extra.txt",
                    "default": "assets/htaccess-default.txt",
                    "force-append": true
                }
            }
        }
    }
}
```

### `file-mapping`

The keys are **destination paths relative to your project root** (the directory
containing the root `composer.json`). There is **no `locations`/`web-root`
indirection** — if your docroot is `web/`, just write `web/...`; to target the
project root, write the path directly.

Each value selects an operation:

| Value | Operation |
|-------|-----------|
| `"assets/file"` (string) | **Replace** — copy the source file to the destination. |
| `{ "path": "...", "overwrite": false }` | **Replace**, but don't clobber an existing destination. |
| `{ "path": "...", "symlink": true }` | **Replace** via symlink (overrides the global `symlink`). |
| `{ "append": "...", "prepend": "...", "default": "...", "force-append": true }` | **Append/Prepend** content (byte-level). |
| `{ "merge": "...", "default": "...", "format": "yaml", "array": "replace", "force-merge": true }` | **Merge** structured JSON/YAML. |
| `false` | **Skip** — cancel a mapping inherited from another package. |

Source paths are resolved **relative to the package that declares them**
(for the root project, relative to the project root).

**Append/Prepend details**

- If the destination is missing and a `default` source is given, that default is
  written first.
- `prepend` content goes before the body, `append` after.
- By default the target must be a managed (scaffolded) file. Set
  `"force-append": true` to modify a pre-existing project file.
- Append/prepend is **idempotent**: content already present is not duplicated on
  re-runs.

### Structured merge (JSON / YAML)

Where `append` is byte-level (great for `.htaccess`, plain text), `merge` parses
the source and destination and **deep-merges the data structures**, so the
result is always valid. Built for config boilerplate — `composer.json` /
`package.json` fragments, CircleCI `.circleci/config.yml`, Tugboat
`.tugboat/config.yml`, `tsconfig.json`, `.eslintrc.json`, etc.

```json
"package.json":            { "merge": "assets/package-fragment.json", "force-merge": true },
".tugboat/config.yml":     { "merge": "assets/tugboat-overlay.yml", "force-merge": true },
".circleci/config.yml":    { "merge": "assets/ci-overlay.yml", "default": "assets/ci-base.yml" }
```

Merge semantics (RFC 7386-flavored):

- **Maps** merge key-by-key; the source wins on scalar conflicts.
- A source value of **`null` deletes** that key from the destination.
- **Arrays** follow the `array` strategy:
  - `replace` *(default)* — source array overwrites the destination array. **Idempotent.**
  - `concat` — destination then source. **Not idempotent** — re-runs grow the list. Use only with care.
  - `unique` — concat then de-duplicate (idempotent for scalar lists).
- **Format** is inferred from the destination extension (`.json`, `.yml`/`.yaml`);
  override with `"format": "json"` / `"yaml"`.
- `default` seeds the destination when it's missing; `force-merge: true` allows
  merging into a pre-existing project file (otherwise the target must already
  be a managed file).

> [!IMPORTANT]
> Re-serialization **discards comments**, and YAML **anchors/aliases are
> expanded inline**. Merge is intended for generated/managed config, not files a
> human hand-curates and comments. For those, prefer plain `replace`.

YAML support requires `symfony/yaml`, which ships as a dependency of this plugin.

### `allowed-packages`

The plugin is **agnostic by default**: no package is implicitly trusted
(unlike Drupal's plugin, which implicitly allows `drupal/core`). Only packages
listed here may contribute files.

- The **root project is always allowed** and is applied **last**, so it can
  override or `false`-out anything a dependency provides.
- Ordering is precedence: later entries win when two packages map the same
  destination.
- **Delegation**: an allowed package may declare its own `allowed-packages`,
  which are pulled in transitively (and ordered *before* the delegating package,
  so the delegator can still override them).

### `symlink`

`true` symlinks replace-mode files instead of copying them — handy in
development so edits flow back to the source. Defaults to `false`. Can be
overridden per file with `"symlink": false`/`true` on a `path` mapping.

### `gitignore`

Controls whether generated (scaffolded) files are added to the nearest
`.gitignore`:

- `true` — always manage `.gitignore`.
- `false` — never touch `.gitignore`.
- *unset* (default) — **auto**: manage only when the project is a git repo that
  already ignores its `vendor` directory.

Files modified via `force-append` / `force-merge` are **not** gitignored — they
are tracked project files, not generated artifacts.

#### Keeping a generated file tracked (`gitignore: false`)

Some scaffolded files **must be committed** to work — e.g. `.circleci/config.yml`
and `.github/workflows/*.yml`, since CI only runs when the config is in the
repository. Add `"gitignore": false` to an individual mapping to scaffold the
file but keep it out of `.gitignore` management:

```json
".circleci/config.yml": { "path": "assets/config.yml", "gitignore": false },
"web/.htaccess":        "assets/htaccess"
```

Here `.circleci/config.yml` is copied **and stays tracked**, while `web/.htaccess`
is still ignored. The flag works on `replace`, `merge`, and `append` mappings;
`"gitignore": true` conversely forces a file into management. (The plugin only
*adds* `.gitignore` entries — it won't remove one a previous run already wrote.)

## Running it

It runs automatically after `composer install` and `composer update`. To run on
demand:

```bash
composer assets
```

After scaffolding completes, the plugin dispatches a **`post-composer-assets-cmd`**
script event so you can chain follow-up steps:

```json
{
    "scripts": {
        "post-composer-assets-cmd": ["@php scripts/fix-permissions.php"]
    }
}
```

## How it compares to drupal/core-composer-scaffold

| | drupal/core-composer-scaffold | kanopi/composer-assets |
|---|---|---|
| Config key | `extra.drupal-scaffold` | `extra.composer-assets` |
| Implicit allowed package | `drupal/core` | none (agnostic) |
| Web root | `locations.web-root` token (`[web-root]`) | plain project-relative paths |
| Command | `composer drupal:scaffold` | `composer assets` |
| Post hook | `post-drupal-scaffold-cmd` | `post-composer-assets-cmd` |
| Replace / append / skip | ✅ | ✅ |
| Structured JSON/YAML merge | ❌ | ✅ |
| Symlink mode | ✅ | ✅ |
| `.gitignore` management | ✅ | ✅ |
| Allowed-packages + delegation | ✅ | ✅ |
| `autoload.php` generation | ✅ (Drupal-specific) | ❌ (out of scope) |

## Development

```bash
composer install
composer test              # full suite (unit + integration)
composer test:unit         # fast unit tests only
composer test:integration  # real `composer install` against fixture packages
```

The **integration test** (`tests/integration/`) builds a throwaway project that
requires this plugin plus the `tests/fixtures/provider` package via local path
repositories, runs an actual `composer install`, and asserts the scaffolded
files land correctly — the regression guard for the plugin's event wiring,
allowed-packages resolution, and operation dispatch. It self-skips if the
`composer` binary isn't on `PATH`.

## License

MIT
