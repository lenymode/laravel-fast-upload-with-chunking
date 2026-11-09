<?php

namespace Tests\Feature;

use App\Jobs\AssembleUpload;
use App\Jobs\CleanupUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResumableUploadTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Storage::fake('local'); config(['uploads.chunk_size'=>4]); }
    private function start(string $bytes='abcdefgh'): array { return $this->postJson('/api/uploads',['filename'=>'video.bin','size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'chunk_size'=>262144])->assertCreated()->json(); }
    private function startSmall(string $bytes): array { config(['uploads.chunk_size'=>262144]); return $this->start($bytes); }
    private function chunk(array $u,int $i,string $bytes){ return $this->call('POST',"/api/uploads/{$u['id']}/chunks/{$i}",[],[],['chunk'=>UploadedFile::fake()->createWithContent('chunk',$bytes)],['HTTP_X_CHUNK_SHA256'=>hash('sha256',$bytes),'HTTP_ACCEPT'=>'application/json']); }

    public function test_interrupted_upload_reports_missing_chunks_and_resumes(): void
    {
        $bytes=str_repeat('a',262144).'tail'; $u=$this->startSmall($bytes); $this->chunk($u,0,str_repeat('a',262144))->assertOk();
        $this->getJson("/api/uploads/{$u['id']}")->assertJsonPath('received_chunks',[0])->assertJsonPath('missing_chunks',[1]);
        $this->chunk($u,1,'tail')->assertOk(); Queue::fake(); $this->postJson("/api/uploads/{$u['id']}/complete")->assertAccepted(); Queue::assertPushed(AssembleUpload::class);
    }

    public function test_duplicate_is_idempotent_but_conflict_is_rejected(): void
    {
        $u=$this->startSmall(str_repeat('a',262144)); $this->chunk($u,0,str_repeat('a',262144))->assertOk(); $this->chunk($u,0,str_repeat('a',262144))->assertOk();
        $this->chunk($u,0,str_repeat('b',262144))->assertConflict(); $this->getJson("/api/uploads/{$u['id']}")->assertJsonPath('received_bytes',262144);
    }

    public function test_cancel_rejects_late_chunk_and_new_upload_is_independent(): void
    {
        Queue::fake(); $a=$this->startSmall(str_repeat('a',262144)); $this->deleteJson("/api/uploads/{$a['id']}")->assertAccepted();
        $this->chunk($a,0,str_repeat('a',262144))->assertConflict(); $b=$this->startSmall(str_repeat('b',262144)); $this->assertNotSame($a['id'],$b['id']); $this->assertSame(0,$b['received_bytes']); Queue::assertPushed(CleanupUpload::class);
    }

    public function test_complete_with_missing_chunks_is_a_conflict(): void
    { $u=$this->startSmall(str_repeat('x',262145)); $this->postJson("/api/uploads/{$u['id']}/complete")->assertConflict()->assertJsonPath('missing_chunks',[0,1]); }
}
