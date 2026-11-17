# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`jbaron/fal_webdav2` is a TYPO3 FAL (File Abstraction Layer) storage driver that keeps files on a WebDAV server (RFC 4918). The extension key is `fal_webdav2` because `fal_webdav` was already taken. Namespace `Jbaron\FalWebdav\` maps to `Classes/`. It's one of Jost's personal open-source extensions, so `origin` is GitHub (`git@github.com:JostBaron/fal_webdav2.git`, branch `main`), not Gerrit. You push normally, without `refs/for/...` and without a `Change-Id` trailer. Commit subjects still use the `[TASK]` / `[BUGFIX]` / `[CLEANUP]` prefixes.

## Commands

`vendor/`, `public/` (a TYPO3 core install pulled in by `composer install`) and `composer.lock` are gitignored.

```bash
composer install
composer test:unit                 # unit tests, no external dependencies
composer test:functional           # needs the WebDAV test server (below); tests are skipped without it
composer test                      # both
vendor/bin/phpunit --filter testRenameFolder   # single test / pattern

# WebDAV test server (Apache mod_dav, mirrors the sites' docker/processed-files image) on 127.0.0.1:8089:
docker compose -f Tests/Functional/Fixtures/webdav-server/docker-compose.yaml up -d --build
```

The test layout:
- `Tests/Unit/` covers `WebdavClient` against `RecordingHttpClient`, a fake PSR-18 client that serves queued responses, and covers `WebdavDriver` with a mocked client. The mocked client is injected through `GeneralUtility::addInstance(WebdavClientFactory::class, ...)`.
- `Tests/Functional/` runs the real driver against the container, using a fresh `/test-<random>/` folder per test.
- Both suites use TYPO3's own PSR-17 factories (`TYPO3\CMS\Core\Http\*Factory`), the same ones the DI container injects in production. Don't swap in Guzzle's `HttpFactory`: its streams close their resource on destruct, which breaks the driver's `fclose()` calls.
- `processed-files.conf` in the fixture is a copy of the sites' file. Keep the two in sync.

There is no linter or build step. To exercise the driver inside TYPO3, install it into one of the Sites (`../../Sites/*/frontend`) via Composer. Then create a File Storage record with the driver "WebDAV driver for FAL".

Supported versions: follow `composer.json`. Each major version targets exactly one TYPO3 major: `main` is 2.x for TYPO3 `^12.4` (PHP `^8.1`), and the `1.x` branch is the TYPO3 10.4/11.5 line (PHP 7.4+). Bugfixes for the 1.x line go to `1.x`, where PHP 8-only syntax must still be avoided. All public TYPO3 12.4 releases on Packagist are flagged by security advisories, because 12.4 is ELTS-only now. As a result, `composer update` refuses to install them by default.

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
