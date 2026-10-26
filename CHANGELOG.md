# Changelog — FlashAsia maintenance fork

## v0.6.0

- Preserve the legacy upstream 0.5.2 API and Redis state format used by FlashAsia.
- Add Illuminate 11 to the existing supported dependency ranges.
- Make the optional `Dialog` constructor `Api` argument explicitly nullable for PHP 8.4.
- Test Illuminate 10/11 with PHP 8.3/8.4, including real Laravel component bindings and serialized dialog state.
- Check API compatibility against actual maintenance-branch revisions with strict runtime dependency gates.

This is the FlashAsia fork release, not upstream 0.6.0 or the upstream 1.x API.
The MIT license and original attribution are retained. No live Telegram or Redis
calls are made by the compatibility tests.
