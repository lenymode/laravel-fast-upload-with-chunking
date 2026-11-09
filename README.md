# Laravel Resumable Uploads

> A portfolio-sized Laravel 12 demonstration of durable, idempotent, checksum-verified large uploads.

## Demo in 60 seconds

```bash
make demo
# open http://localhost:8000
```

Select a file, pause after one or more chunks, then reselect the same file. The UI hashes it incrementally, asks the server which zero-based indexes already exist, and reports the accepted bytes it skipped. On completion, a Redis-backed Laravel queue worker streams the chunks into one temporary file, checks its byte length and SHA-256, and atomically publishes it.

Typical UI output:

```text
upload 12b9…ee20
3/8 chunks accepted · 5 missing
24.0 MiB already accepted · resuming
Verified and published successfully
```

This output is illustrative. No large-file or peak-memory claim is reported because this repository has not recorded a controlled 1 GiB benchmark.

## Architecture

The HTTP container, worker, and scheduler share the same private `storage/app/private` volume. Redis carries queue messages; upload manifests and chunks stay on the shared local filesystem. A random token kept in the encrypted browser session is the demo owner identity, and every session operation enforces it. In a real product, replace that value with the authenticated user's ID.

```text
Browser → start session → POST verified chunks → complete (202)
                    ↘ status / missing indexes ↗
Redis queue → worker → stream + verify → atomic publish
Scheduler → expire abandoned sessions → queued cleanup
```

Status transitions are `uploading → queued → assembling → complete`. Cancellation is allowed only while `uploading`; assembly verification exhaustion produces `failed`. Chunk and transition operations use per-upload filesystem locks. Repeating identical chunk content is a successful no-op, while different content for an accepted index returns `409`. Completion persists a `dispatch_pending` recovery marker before dispatching, and repeated completion can dispatch recovery work. The worker is retry-safe: it rewrites a temporary assembly file, and if a verified final file already exists after a publish/database-state crash, it verifies that file and converges to `complete`.

The manifest store is intentionally filesystem-backed in this demo because repository policy forbids running Laravel migrations. It uses Laravel's configured filesystem disk and can be replaced by an Eloquent repository without changing the HTTP contract.

## Reproducible scenarios

### 1. Interrupt and resume

1. Choose a file larger than 16 MiB.
2. Click **Pause** after progress starts (a request already in flight may finish).
3. Click **Resume saved upload** and reselect the exact same file.
4. Observe “already accepted · resuming,” then the verified result.

A file with different bytes gets a new server-issued upload ID. The published file is at `storage/app/private/uploads/verified/<upload-id>.bin` inside the shared Docker volume.

### 2. Cancel A and immediately upload B

1. Start Video A and click **Cancel upload**.
2. Select Video B immediately; it starts at zero with a different ID.
3. Any late request for A receives `409`; its cleanup job is safe to retry and cannot affect B.

### 3. Restart the worker

```bash
docker compose stop worker
# complete an upload; status remains queued
docker compose start worker
docker compose logs -f worker
```

The worker assembles into a deterministic temporary path. Restarting it is safe, and one verified final file remains.

## Local commands

```bash
make demo             # build and start web, Redis, worker, scheduler
make logs             # follow web and worker logs
make test             # run the focused and existing tests in Docker
make down

# choose another available host port
APP_PORT=8085 make demo
```

No database migration is needed or run by these commands. Docker is the recommended setup because the host needs neither PHP nor Node.

## HTTP contract

| Method | Endpoint | Result |
|---|---|---|
| `POST` | `/api/uploads` | Create a server-owned session from filename, size, SHA-256 |
| `GET` | `/api/uploads/{id}` | Status, received/missing indexes, accepted bytes |
| `POST` | `/api/uploads/{id}/chunks/{index}` | Multipart field `chunk`; header `X-Chunk-SHA256` |
| `POST` | `/api/uploads/{id}/complete` | Validate completeness and return `202` |
| `DELETE` | `/api/uploads/{id}` | Cancel an uploading session and queue cleanup |

The old `/upload-chunk` route remains as a transition aid but returns `410` with the new endpoint. Its client-generated path behavior was unsafe and could not preserve the new integrity guarantees.

## Limits and disk planning

The default chunk is 8 MiB and the total-file limit is independently set to 10 GiB. Configure `UPLOAD_CHUNK_SIZE`, `UPLOAD_MAX_SIZE`, and `UPLOAD_EXPIRES_HOURS`. Reverse proxies and PHP must allow a request slightly larger than one chunk (`client_max_body_size`, `post_max_size`, and `upload_max_filesize`); they do not need to allow the full file. Plan temporary disk for roughly **2× the largest in-progress file** during assembly, plus other concurrent uploads. The worker reads and writes in 1 MiB buffers, so memory does not grow with file size.

## Verification coverage

`tests/Feature/ResumableUploadTest.php` covers interrupted resume, idempotent duplicate/conflicting chunks, cancel plus a late chunk and independent replacement, missing-chunk completion, and queue dispatch. The assembly job additionally implements final checksum failure and recovery after publish-before-state-update. Run all checks with `make test`.
