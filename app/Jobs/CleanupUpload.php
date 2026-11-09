<?php
namespace App\Jobs;
use App\Services\UploadStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class CleanupUpload implements ShouldQueue { use Queueable; public function __construct(public string $uploadId){} public function handle(UploadStore $s):void{$s->disk()->deleteDirectory("uploads/{$this->uploadId}/chunks");} }
