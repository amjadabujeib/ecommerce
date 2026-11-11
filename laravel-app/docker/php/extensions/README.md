# Bundled PHP gRPC extension

`grpc-1.85.0-php8.5-linux-amd64.so.gz` is the existing compiled gRPC 1.85.0
extension, compressed reproducibly with gzip. Share this directory through Git;
there is no separate manual installation for teammates. The app Dockerfile
extracts, checks and enables it instead of compiling gRPC.

- Compressed size: 6,654,667 bytes (6.35 MiB).
- Uncompressed size: 16,558,504 bytes (15.79 MiB).
- Uncompressed SHA-256: `b4bc4904e6fc28ce23c3732b7f908bb4828750129efe9241d9981e5a730b72ca`.
- Target: Linux amd64, Debian/glibc, PHP 8.5 non-thread-safe ABI `20250925`.
- Exact compatible base: `php:8.5.12-fpm@sha256:c34bcf437ed61bd5e4c1114494eaf1ffd54253bce2b95d263bfa18b350ee8432`.
- Dynamic libraries: libstdc++, libc, libm and libgcc_s, supplied by that base.

Provenance: extracted from the locally built and acceptance-tested application
image `sha256:8dadf38067cb20b32cde096e7966efacf7ac8d4941ad8404dc95cfaa0566d3dd`.
Its gRPC layer was produced by `install-php-extensions grpc`; the extracted
module reports version 1.85.0. There is no claim of a reproducible upstream
binary build or signature. Source is available from
[gRPC v1.85.0](https://github.com/grpc/grpc/tree/v1.85.0/src/php).
The upstream LICENSE and NOTICE accompany the binary in this directory.

Do not reuse this binary with Alpine/musl, a different PHP ABI, or a native
ARM image. The Dockerfile selects the matching amd64 base and validates ABI,
architecture, binary checksum and loaded module version during the build.
When updating PHP/base libraries or gRPC, rebuild the extension in the intended
base, replace this artifact and checksum, update the base digest, and rerun the
build/load and real gRPC acceptance checks together. Pinning is deliberate;
security updates still require a reviewed runtime refresh.

The application and scheduler share the same built core image. Ordinary
`docker compose up -d --build` needs no native gRPC compilation and no separate
gRPC download. A first setup still needs the regular base images, locked
Composer/Node packages and PostgreSQL build dependencies if not cached.
