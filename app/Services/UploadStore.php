<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class UploadStore
{
    public function disk()
    {
        return Storage::disk(config('uploads.disk'));
    }

    public function read(string $id): ?array
    {
        $path = $this->manifestPath($id);
        if (! $this->disk()->exists($path)) return null;

        return json_decode($this->disk()->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function create(array $upload): array
    {
        $this->disk()->makeDirectory("uploads/{$upload['id']}/chunks");
        $this->write($upload);
        return $upload;
    }

    public function locked(string $id, callable $callback): mixed
    {
        $directory = storage_path('framework/upload-locks');
        if (! is_dir($directory)) mkdir($directory, 0775, true);
        $handle = fopen($directory.'/'.$id.'.lock', 'c+');
        if (! $handle || ! flock($handle, LOCK_EX)) throw new RuntimeException('Could not lock upload.');
        try {
            $upload = $this->read($id);
            return $callback($upload);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function write(array $upload): void
    {
        $upload['updated_at'] = now()->toIso8601String();
        $json = json_encode($upload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $tmp = $this->manifestPath($upload['id']).'.tmp-'.bin2hex(random_bytes(4));
        $this->disk()->put($tmp, $json);
        $this->disk()->move($tmp, $this->manifestPath($upload['id']));
    }

    public function manifestPath(string $id): string { return "uploads/{$id}/manifest.json"; }
    public function chunkPath(string $id, int $index): string { return "uploads/{$id}/chunks/{$index}.part"; }
    public function finalPath(string $id): string { return "uploads/verified/{$id}.bin"; }

    public function response(array $u): array
    {
        $received = array_map('intval', array_keys($u['chunks'])); sort($received);
        $missing = array_values(array_diff(range(0, $u['chunk_count'] - 1), $received));
        return [
            'id' => $u['id'], 'filename' => $u['filename'], 'size' => $u['size'],
            'chunk_size' => $u['chunk_size'], 'chunk_count' => $u['chunk_count'],
            'status' => $u['status'], 'received_chunks' => $received, 'missing_chunks' => $missing,
            'received_bytes' => array_sum(array_column($u['chunks'], 'size')),
            'sha256' => $u['sha256'], 'final_sha256' => $u['final_sha256'] ?? null,
            'error' => $u['error'] ?? null, 'expires_at' => $u['expires_at'],
        ];
    }
}
