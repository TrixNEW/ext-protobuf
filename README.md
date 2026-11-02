# ext-protobuf

The protobuf PHP extension from [PECL protobuf 5.36.2](https://pecl.php.net/package/protobuf), pinned for our PHP binaries and tested on threaded PHP (pmmpthread / qthreads, Quark).

## Why

Without the extension, `google/protobuf` falls back to its pure PHP runtime. That's fine for a few small messages, but it encodes byte by byte on whichever thread calls it. For [pRPC](https://github.com/TrixNEW/pRPC) that's the main server thread, where a 16 KB request costs about half a millisecond just to encode.

The extension keeps all of its per request state in module globals, which ZTS gives every thread on its own, so it needs no patch. This repo is the PECL source as is plus a thread stress test.

## Tested

Linux, PHP 8.4.27 ZTS with qthreads 6.4.0 (pmmpthread), OPcache on, `google/protobuf` 5.36.0.

`tests/threads.php` builds, encodes, decodes and JSON round trips messages (maps, lists, 16 KB strings, random garbage) on 16 threads at once, with `INHERIT_NONE` and `INHERIT_ALL`, starting threads while the main thread is mid encode. Then it starts and joins 1,000 more threads and checks memory stays flat.

- 10 of 10 runs passed, RSS moved under 1 MB across the 800 measured threads
- 3 of 3 passed with `protobuf.keep_descriptor_pool_after_request=1`
- Valgrind found no invalid reads or writes, and no leaks from the extension

Encode and decode per call, same machine:

| Message | Pure PHP encode | Extension encode | Pure PHP decode | Extension decode |
| --- | ---: | ---: | ---: | ---: |
| 64 B string | 7.0 µs | 0.13 µs | 2.6 µs | 0.34 µs |
| 16 KB string | 475 µs | 5.1 µs | 15.6 µs | 1.6 µs |
| Struct, 20 fields | 553 µs | 1.3 µs | 267 µs | 3.2 µs |

The extension version should match the `google/protobuf` package your generated code is built against, same major and minor.

## Build

```bash
phpize
./configure --with-php-config=/path/to/php-config
make -j"$(nproc)"
make install
```

Then `extension=protobuf` in php.ini. In a pmmp style `compile.sh`:

```bash
EXT_PROTOBUF_VERSION="5.36.2-trix.1"
get_github_extension "protobuf" "$EXT_PROTOBUF_VERSION" "TrixNEW" "ext-protobuf" "v"
```

Run the stress test with any project that has `google/protobuf` installed:

```bash
php tests/threads.php /path/to/vendor/autoload.php
```

## License

BSD-3-Clause, see [LICENSE](LICENSE).
