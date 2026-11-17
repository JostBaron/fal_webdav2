# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`jbaron/fal_webdav2` is a TYPO3 FAL (File Abstraction Layer) storage driver that keeps files on a WebDAV server (RFC 4918). The extension key is `fal_webdav2` because `fal_webdav` was already taken. Namespace `Jbaron\FalWebdav\` maps to `Classes/`. It's one of Jost's personal open-source extensions, so `origin` is GitHub (`git@github.com:JostBaron/fal_webdav2.git`, branch `main`), not Gerrit. You push normally, without `refs/for/...` and without a `Change-Id` trailer. Commit subjects still use the `[TASK]` / `[BUGFIX]` / `[CLEANUP]` prefixes.

## Commands

There are no tests, linter, or build step. `vendor/`, `public/` (a TYPO3 core install pulled in by `composer install`) and `composer.lock` are gitignored. They exist only so the IDE can resolve TYPO3 classes.

```bash
composer install                                   # fetch TYPO3 core + PSR deps for IDE/autocompletion
find Classes -name '*.php' -exec php -l {} \;      # the only available sanity check: syntax lint
```

To exercise the driver for real, install it into one of the Sites (`../../Sites/*/frontend`) through Composer. Then create a File Storage record in the TYPO3 backend with driver "WebDAV driver for FAL".

Supported versions: follow `composer.json` (PHP `^7.4 || ^8.0 || ^8.1`, TYPO3 `^10.4 || ^11.5`). The constraints in `ext_emconf.php` (TYPO3 10.4 only) are stale. Because PHP 7.4 is still supported, avoid PHP 8-only syntax such as constructor promotion, `match`, union types, or `str_starts_with`.

## Architecture

There are four classes in `Classes/Driver/`, layered like this:

- **`WebdavDriver`** extends TYPO3's `AbstractHierarchicalFilesystemDriver`. It implements the FAL driver API and works only with FAL identifiers: paths relative to the storage root, where folders end in `/`. Every public method first runs `canonicalizeAndCheck{File,Folder}Identifier`, then delegates to the client. Lifecycle: the constructor gets a `WebdavClientFactory` via `GeneralUtility::makeInstance`. `processConfiguration()` builds the `WebdavClient` from the storage's FlexForm values (`webdav_url`, `public_url`). `initialize()` creates the logger, so the logger is not available before `initialize()`.
- **`WebdavClient`** is the HTTP/WebDAV protocol layer, built on PSR-18/PSR-17 interfaces (no Guzzle or Sabre dependency). It maps operations to verbs: MKCOL (creates each path segment, and treats 405 "already exists" as success), HEAD for existence checks, PUT, DELETE, COPY/MOVE with a `Destination` header, and PROPFIND (Depth 0/1/infinity) for metadata and listings. `executeRequest()` is the single choke point:
  - It returns `null` for transport failures (`ClientExceptionInterface`).
  - It rethrows `RequestExceptionInterface`, because that means a bug in a request we built.
  - It logs 5xx responses.
  
  Callers have to handle the `null` case.
- **`MultiStatusResponse`** is a value object for one `<D:response>` element of a 207 Multi-Status body. `splitMultiStatusResponse()` turns `href` values, whether absolute URLs or bare paths, back into FAL-style paths relative to `webdav_url`. This path mapping is subtle, so check it whenever listing or rename results look wrong.
- **`WebdavClientFactory`** is the only DI-wired service (`Configuration/Services.yaml`). It receives the PSR HTTP client and factories from TYPO3's container, because the driver itself is not DI-constructed.

Behaviors that span files:
- **Directory detection** in listings relies on `getcontenttype === 'httpd/unix-directory'`. Anything else is treated as a file. That is server-specific (Apache mod_dav style).
- **Folder rename/move** returns TYPO3's old→new identifier map by re-listing the destination recursively after the MOVE, not by computing the map up front.
- **Public URLs** do not point at the DAV endpoint. They are `public_url` + identifier, so the files have to be served publicly from a separate base URL.
- **`getFileForLocalProcessing()`** downloads the whole file into a temp file whose extension matches the original, because TYPO3 rejects files with disallowed extensions. The temp files are deleted in `__destruct()`.
- `hash()`, `replaceFile()`, and `setFileContents()` hold the entire file in memory.

## Registration

`ext_localconf.php` registers the driver in `$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers']` under the key `WebdavDriver::DRIVER_KEY` (`Jbaron.FalWebdav`), together with the FlexForm `Configuration/FlexForm/DriverFlexForm.xml`. Getting this registration to work on TYPO3 11 took a couple of bugfix commits, so be careful when changing the key or the array shape.
