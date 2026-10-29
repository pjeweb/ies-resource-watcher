# IES Resource Watcher

Indexes published IES resources as soon as they arrive on the web node.

Sitepark IES publishes resources to the web nodes, often through rsync. The native ad-hoc indexing is triggered by the CMS and has to guess when a file has actually arrived. This watcher observes the publication directory on the web node instead. When a resource file is written, moved or deleted, it queues the path durably and lets the **native indexer** build the document, with its excludes, filters, enrichers and language routing. Nothing about the document is reimplemented here.

The package is a Symfony bundle. It runs inside an Atoolo application, or attached to an installed one that must not be modified, such as the FEDS module of an IES web node.

> Status: in production at Stadt Braunschweig on the WWW web node since 2026-10-29, attached to FEDS 1.30 with Atoolo Search 1.14 and about 69,000 published resources. It replaced a Python predecessor with the same guarantees.

## Guarantees

- **Durable queue.** File events are committed to SQLite (WAL, `synchronous=FULL`) before any job starts. A crash or restart loses nothing; changes made while the watcher was stopped are found by the next scan.
- **No stale acknowledgement.** Every observation of a path increases its revision. A job can only acknowledge the revision it processed; a newer version stays queued.
- **Identity before writing.** A job first plans which documents it will write, records them durably, and only then writes. If the job dies after writing and the file is deleted while the watcher is down, the document can still be removed.
- **Exact version.** The planned resource is pinned in memory, so the indexer writes exactly the version whose identity was recorded.
- **Conditional removal.** A previous document is removed only if it still has the remembered URL, using a versioned delete. A document that was renamed or rewritten concurrently survives; a version conflict retries the job.
- **Verification.** A job succeeds only after the index reflects the update and the removals. The Atoolo adapter compares ID, URL and the version's generation time, not the name: enrichers may rewrite `sp_name`, as the CityGov bundle does for products.
- **Full indexing is respected, never blocked.** The watcher never takes the native indexer's lock; even a brief acquisition could make a scheduled full run skip. It inspects the lock read-only (`/proc/locks` for flock, `semctl` for SysV semaphores) and watches the native status file. Jobs wait while a full run is active. A job that overlaps a full run is not acknowledged but replayed, because the run's final cleanup may delete documents written in the meantime.
- **Repair.** Startup, inotify queue overflow and a periodic full scan compare content hashes with the recorded state. Hashes catch changes even when rsync preserves size and modification time.
- **Isolation.** Each job runs in its own process with a timeout. Paths that failed are retried with exponential backoff in halving batches, so a broken resource is isolated and never holds back new publications.

## How it works

```
                  ┌──────────── watcher (long-running) ────────────┐
 inotify ───────▶ │ events ─┐                                      │
 scans  ────────▶ │         ├─▶ SQLite queue ─▶ next batch ─┐      │
 (startup, new    │ hashes ─┘     (files, pending, meta)    │      │
  dirs, overflow, │                                         ▼      │
  periodic)       │   full index guard (read-only) ◀── start/ack   │
                  └──────────────────────────────────────┬─────────┘
                                                         │ one at a time
                                ┌────────────────────────▼─────────┐
                                │ job process: plan → record intent│
                                │ → native update → conditional    │
                                │ removal → verify                 │
                                └──────────────────────────────────┘
```

- Directories are watched rather than files, so atomic replacement by rename is seen. A new directory is scanned immediately, since its first files can arrive before its watch exists. A directory moved away or deleted rechecks every file known below it.
- Events for one path are coalesced for a quiet period (default 0.75 s), but no path is deferred more than 3 s beyond its first event. Up to 32 paths form one job.
- On the first start, or when the scopes change, existing files are recorded as a **baseline** instead of being reindexed. Background jobs then ask the index which documents belong to those files (*identification*), so that a later deletion removes them.
- Each job boots a fresh container. Native loaders and enrichers start without caches, and a fatal error, memory leak or hung request ends with the job, not the watcher.

The bundled adapter is `Atoolo\Search1\AtooloSearchTarget`. Other index targets implement [`IndexTarget`](src/Index/IndexTarget.php):

| Method | Called by | Purpose |
|---|---|---|
| `layout()` | watcher | Resource root and file conventions |
| `fullIndexGuard()` | watcher | Read-only view of a competing full run |
| `plan(paths)` | job | Documents the paths will hold; writes nothing |
| `apply(plan, previous)` | job | Write, then remove previous documents conditionally |
| `verify(plan, previous)` | job | Throw unless the index reflects both |
| `identify(paths)` | job | Documents currently held for baseline files |

The core enforces the order *plan → durable intent → apply → verify*. Adapters cannot skip the intent.

## Requirements

- Linux (inotify), local filesystem for the publication and the state directory.
- PHP ≥ 8.1 CLI with `ffi`, `pdo_sqlite`, `pcntl` and `posix`. inotify and semaphore inspection use FFI on libc, so no PECL extension is needed. The default `ffi.enable=preload` permits FFI in the CLI.
- Symfony 6.4 or 7.x (`console`, `dependency-injection`, `http-kernel`, `process`; `framework-bundle` and `dotenv` for attached mode).
- For the bundled adapter: `atoolo/search-bundle` 1.x with `atoolo/resource-bundle` (tested with Search 1.14.0 and Resource 1.8.0), SiteKit resources and schema 2.x documents.
- Enough inotify watches (`fs.inotify.max_user_watches`) for every directory of the publication.

## Installation

### In an Atoolo application

```sh
composer require stadt-braunschweig/ies-resource-watcher
```

```php
// config/bundles.php
StadtBraunschweig\IesResourceWatcher\IesResourceWatcherBundle::class => ['all' => true],
```

```yaml
# config/packages/ies_resource_watcher.yaml
ies_resource_watcher:
    state_dir: '%env(IES_RESOURCE_WATCHER_STATE)%'
    atoolo_search:
        index: '%env(default::IES_RESOURCE_WATCHER_INDEX)%'
```

Run one watcher per channel, with the channel's `RESOURCE_ROOT` as for the native indexer:

```sh
RESOURCE_ROOT=/var/www/example/www IES_RESOURCE_WATCHER_STATE=/var/lib/ies-resource-watcher/www \
  bin/console ies-resource-watcher:watch
```

### Attached to an installed application

`bin/ies-resource-watcher` boots the installed application's kernel with its own `vendor/`, `.env` and configuration, adds the bundle in memory, and compiles its container below the state directory. The application's files and caches are only read. This package needs no `vendor/` directory of its own in this mode.

```sh
# As root, from an unpacked copy of this package (no vendor/ needed):
cp resources/attached.example.json /root/www.json   # adjust
bash resources/install.sh www /root/www.json --enable
```

The installer copies the code to `/usr/local/lib/ies-resource-watcher`, the configuration to `/etc/ies-resource-watcher/<channel>.json` and the units to `/etc/systemd/system`. It backs up the previous installation and a queue snapshot. Before starting, it runs `ies-resource-watcher:check` as the service user. On failure it restores the previous state. `systemctl enable --now` returns once the first full scan has completed.

[`resources/attached.example.json`](resources/attached.example.json):

| Key | Meaning |
|---|---|
| `project` | Installed application; a release symlink is fine. The watcher exits when its target changes and restarts with the new release. |
| `resource_root` | `RESOURCE_ROOT` of the channel |
| `environment` | Kernel environment (default: `APP_ENV`, else `prod`) |
| `watcher` | Bundle configuration (below). `console`, `restart_on_change` and `atoolo_search.native_cache_dir` are derived. |

The watcher must run as the user of the native indexer, not as root. The [systemd unit](resources/systemd/ies-resource-watcher@.service) shares the host's `/tmp` and IPC namespaces so that the native lock is visible. It keeps the publication read-only and allows writes only to the state directory.

## Configuration

| Option | Default | |
|---|---|---|
| `state_dir` | required | Queue, status and (attached) compiled container. One per channel. |
| `target` | Atoolo Search adapter | Service implementing `IndexTarget` |
| `scopes` | `['/']` | Watched directories below the resource root |
| `prune` | `[]` | Additional directory name patterns to skip, e.g. `*.scaled`, `node_modules` |
| `quiet_period` | `0.75` | Seconds to coalesce events of one path |
| `batch_size` | `32` | Paths per job |
| `reconcile_interval` | `3600` | Seconds between repair scans; a recovery interval, not the indexing delay |
| `job_timeout` | `120` | Seconds before a job is killed and retried |
| `identify_batch_size` | `250` | Baseline paths identified per job; `0` disables |
| `console` | `[PHP_BINARY, bin/console]` | Command line for job processes |
| `restart_on_change` | `[]` | Symlinks whose target change makes the watcher exit for a restart |
| `atoolo_search.index` | `null` | Expected base index; refuse to run against another channel |
| `atoolo_search.native_cache_dir` | `%kernel.cache_dir%` | Cache directory of the application that runs full indexing (status file, cache-relative lock path) |

The Atoolo adapter adds its own conventions: `.php` resources, `WEB-IES` pruned, `*-1015t.php` preview files excluded and `.translations` directories allowed.

## Operation

```sh
bin/console ies-resource-watcher:check          # target, index, native lock, status file; writes nothing
bin/console ies-resource-watcher:status         # JSON; exit 1 if unhealthy
journalctl -u ies-resource-watcher@www -n 50     # one JSON event per line
```

`status.json` in the state directory contains watches, scan state, pending count, age of the oldest queued path, attempts and recent errors, full index state and unidentified baseline files. Health fails on a stale status, an incomplete initial scan, a scan error, or a path queued for longer than `--max-pending-age` (default 300 s). A long full run legitimately delays jobs: check `full_index_busy`.

To stop using the watcher, stop it; the native indexing path is unchanged. Keep the state directory: on the next start, everything queued or changed in between is processed. Do not delete the queue to resolve an incident.

## Compatibility

Only the combination listed under *Requirements* is tested. The current development state of Atoolo (`atoolo/index-bundle`, ID-based resource layout, resource change messages) changes interfaces, service IDs, the status file name and the file layout. It needs its own adapter:

- `IndexerProgressHandler` and `ResourceLoader` gain methods (`unchanged()`, `idToLocation()`).
- The status file becomes `atoolo.index.<key>.status.json`; services move to `atoolo_index.*`.
- Objects live under `objects/000/000/123.php` with translations and temporary files elsewhere.
- Resource change messages could carry the watcher's observations into Messenger instead of calling the indexer directly. Acknowledgement would then mean *enqueued*, not *verified*, and deletions need the recorded resource ID.

## Limitations

- One watcher per channel and host; the queue is local.
- File names must be valid UTF-8; others are skipped and logged (`skipped_name`).
- Documents an enricher writes on its own, such as CityGov alternative titles (`<id>-<n>`), are not tracked. When their resource is deleted they remain until the next native full run, as with the native ad-hoc indexing.
- Search tips (`objectType` `searchTip`) carry no `sp_source` because the native enricher stops early. The adapter recognises them by their object type.
- Identification of baseline files runs in the background after the first scan. A baseline file deleted before it was identified, or one that cannot be identified, is not removed by the watcher; the next native full run does it.
- Content changes during the 3-second deferral window are coalesced, so intermediate versions are never indexed (by design).
- PHP reports modification times in seconds. The full-index checkpoint therefore also uses inode, change time and size; the lock itself is observed continuously.

## Testing

```sh
composer install
vendor/bin/phpunit
```

- **Unit:** queue semantics (revisions, baseline, intents, halving retries, atomic groups), content signatures, path policy, inotify, read-only lock probes against real Symfony flock and semaphore locks.
- **Watcher:** real inotify, SQLite and job processes with a file-based index and fault injection. Covers burst coalescing, atomic replacement, new, renamed and moved-out directories, changes while stopped, backend outage across a restart, a broken resource beside fresh ones, a full run blocking and overlapping a job, a crash after writing followed by deletion while stopped, a renamed document, a hung job, a second watcher on the same state, a replaced scope directory, an undecodable file name, an unreadable file, a console started with `--quiet` and isolation of a failing identification.
- **Atoolo Search 1.14:** the adapter in a real container with Resource and Search bundles and an in-memory Solr. Covers the native loader, filter, enrichers and language routing, translations, `noIndex` and channel excludes, media URLs and media without generation time, an enricher that rewrites the name, search tips, conditional removal with a concurrent rewrite (HTTP 409), intent before the first write, identification that ignores leftover copies with the same ID, and lock detection through the autowired lock factory.
- **Attached mode:** `bin/ies-resource-watcher` as a process against a separate host application, including job processes, the health check, SIGUSR1 (ignored) and SIGTERM, without writing to the host.

A real Solr with the Sitepark schema and a CMS publication are not part of this suite. They belong to the acceptance test of an installation.

## License

To be decided before publication. MIT, as for Atoolo, is proposed.
