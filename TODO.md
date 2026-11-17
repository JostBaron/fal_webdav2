# TODO

## Use only a subpath of a WebDAV server

Goal: several sites (Mein-Bauernhof, Handwerkergesucht, Sportswanted) share one WebDAV server, each
restricted to its own subfolder, e.g. `https://dav.example.com/dav/mein-bauernhof/`.

State as of 2026-11-17: putting the subpath into `webdav_url` (and the matching one into `public_url`)
already works for basic operations (create, list, rename, public URLs - checked manually against the
functional-test server). What's missing:

- [ ] Create the base folder automatically if it doesn't exist. Right now `createFolder()` only creates
      the segments below `webdav_url`, so MKCOL fails with 409 when the base folder is missing.
- [ ] Decide whether the subpath should be its own FlexForm field (e.g. `base_path`) instead of being
      part of `webdav_url`. A separate field would let several storages share the same server and
      public URL settings.
- [ ] Make sure no operation can leave the subpath: `Destination` headers, absolute `href`s from other
      hosts/paths in multi-status responses, redirects followed in `getProperties()`.
- [ ] Add functional tests that run two storages with different subpaths on the same server and
      check that they don't see each other's files.
- [ ] Document the setup in the README (including the server-side config: one `/dav/` location, one
      read-only `/file/` alias, per-site subfolders).
