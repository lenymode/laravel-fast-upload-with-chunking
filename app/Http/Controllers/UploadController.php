<?php

namespace App\Http\Controllers;

use App\Jobs\AssembleUpload;
use App\Jobs\CleanupUpload;
use App\Services\UploadStore;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadController extends Controller
{
    public function __construct(private UploadStore $store) {}

    public function start(Request $request)
    {
        $data = $request->validate([
            'filename' => ['required','string','max:255'], 'size' => ['required','integer','min:1','max:'.config('uploads.max_size')],
            'sha256' => ['required','regex:/^[a-f0-9]{64}$/i'], 'chunk_size' => ['nullable','integer','min:262144','max:16777216'],
        ]);
        $chunkSize = $data['chunk_size'] ?? config('uploads.chunk_size');
        $id = (string) Str::uuid();
        $owner = $request->session()->get('upload_owner');
        if (! $owner) { $owner = (string) Str::uuid(); $request->session()->put('upload_owner', $owner); }
        $upload = $this->store->create([
            'id'=>$id, 'owner'=>$owner, 'filename'=>basename($data['filename']),
            'size'=>(int)$data['size'], 'sha256'=>strtolower($data['sha256']), 'chunk_size'=>$chunkSize,
            'chunk_count'=>(int)ceil($data['size']/$chunkSize), 'chunks'=>[], 'status'=>'uploading',
            'expires_at'=>now()->addHours(config('uploads.expires_hours'))->toIso8601String(), 'created_at'=>now()->toIso8601String(),
        ]);
        return response()->json($this->store->response($upload), 201);
    }

    public function show(Request $request, string $id)
    {
        $u = $this->owned($request, $id);
        return $this->store->response($u);
    }

    public function chunk(Request $request, string $id, int $index)
    {
        $checksum = strtolower((string)$request->header('X-Chunk-SHA256'));
        if (! preg_match('/^[a-f0-9]{64}$/', $checksum)) throw ValidationException::withMessages(['checksum'=>'X-Chunk-SHA256 is required.']);
        $file = $request->file('chunk');
        if (! $file || ! $file->isValid()) throw ValidationException::withMessages(['chunk'=>'A valid chunk file is required.']);

        $result = $this->store->locked($id, function (?array $u) use ($request, $id, $index, $checksum, $file) {
            $u = $this->assertOwned($request, $u);
            if ($u['status'] !== 'uploading') abort(409, "Upload is {$u['status']}.");
            if ($index < 0 || $index >= $u['chunk_count']) abort(422, 'Chunk index is out of range.');
            $expected = $index === $u['chunk_count'] - 1 ? $u['size'] - ($index * $u['chunk_size']) : $u['chunk_size'];
            if ($file->getSize() !== $expected) abort(422, "Expected {$expected} bytes.");
            if (hash_file('sha256', $file->getRealPath()) !== $checksum) abort(422, 'Chunk checksum mismatch.');
            if (isset($u['chunks'][(string)$index])) {
                if ($u['chunks'][(string)$index]['sha256'] !== $checksum) abort(409, 'Different content was already accepted for this index.');
                return $u;
            }
            $path = $this->store->chunkPath($id, $index);
            $stream = fopen($file->getRealPath(), 'rb');
            $this->store->disk()->put($path.'.tmp', $stream); if (is_resource($stream)) fclose($stream);
            if ($u['status'] !== 'uploading') { $this->store->disk()->delete($path.'.tmp'); abort(409); }
            $this->store->disk()->move($path.'.tmp', $path);
            $u['chunks'][(string)$index] = ['size'=>$expected, 'sha256'=>$checksum];
            $this->store->write($u);
            return $u;
        });
        return response()->json($this->store->response($result));
    }

    public function complete(Request $request, string $id)
    {
        [$u,$dispatch] = $this->store->locked($id, function (?array $u) use ($request) {
            $u = $this->assertOwned($request, $u);
            if (in_array($u['status'], ['queued','assembling','complete'], true)) return [$u,false];
            if ($u['status'] !== 'uploading') abort(409, "Upload is {$u['status']}.");
            $missing = array_diff(range(0,$u['chunk_count']-1), array_map('intval',array_keys($u['chunks'])));
            if ($missing) return [$u,false];
            $u['status']='queued'; $u['dispatch_pending']=true; $this->store->write($u); return [$u,true];
        });
        if ($u['status']==='uploading') return response()->json(['message'=>'Chunks are missing']+$this->store->response($u), 409);
        if ($dispatch || ($u['dispatch_pending'] ?? false)) {
            try {
                AssembleUpload::dispatch($id);
                $this->store->locked($id, function ($fresh) { $fresh['dispatch_pending']=false; $this->store->write($fresh); });
            } catch (\Throwable $e) {
                report($e);
            }
        }
        return response()->json($this->store->response($this->store->read($id)), 202);
    }

    public function cancel(Request $request, string $id)
    {
        $u = $this->store->locked($id, function (?array $u) use ($request) {
            $u=$this->assertOwned($request,$u); if ($u['status']!=='uploading') abort(409,'Only uploading sessions can be cancelled.');
            $u['status']='cancelled'; $this->store->write($u); return $u;
        });
        CleanupUpload::dispatch($id); return response()->json($this->store->response($u), 202);
    }

    private function owned(Request $r,string $id): array { return $this->assertOwned($r,$this->store->read($id)); }
    private function assertOwned(Request $r,?array $u): array { if(!$u) abort(404); if($u['owner']!==$r->session()->get('upload_owner')) abort(403); return $u; }
}
