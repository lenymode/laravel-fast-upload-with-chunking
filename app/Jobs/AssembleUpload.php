<?php

namespace App\Jobs;

use App\Services\UploadStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AssembleUpload implements ShouldQueue
{
    use Queueable;
    public int $tries=3; public int $timeout=3600;
    public function __construct(public string $uploadId) {}
    public function handle(UploadStore $store): void
    {
        $u=$store->read($this->uploadId); if(!$u || $u['status']==='complete') return;
        $final=$store->finalPath($this->uploadId);
        if($store->disk()->exists($final)) { $this->verifyPublished($store,$u,$final); return; }
        $u['status']='assembling'; $store->write($u);
        $tmp="uploads/{$this->uploadId}/assembled.tmp"; $out=fopen($store->disk()->path($tmp),'wb');
        $hash=hash_init('sha256'); $bytes=0;
        try {
            foreach(range(0,$u['chunk_count']-1) as $i) {
                $in=$store->disk()->readStream($store->chunkPath($u['id'],$i));
                while(!feof($in)){ $buffer=fread($in,1024*1024); $bytes+=strlen($buffer); hash_update($hash,$buffer); fwrite($out,$buffer); }
                fclose($in);
            }
        } finally { fclose($out); }
        $digest=hash_final($hash);
        if($bytes!==$u['size'] || !hash_equals($u['sha256'],$digest)) { $store->disk()->delete($tmp); throw new \RuntimeException('Final size or SHA-256 verification failed.'); }
        $store->disk()->makeDirectory('uploads/verified'); $store->disk()->move($tmp,$final);
        $u['status']='complete'; $u['final_sha256']=$digest; $u['completed_at']=now()->toIso8601String(); $store->write($u);
        $store->disk()->deleteDirectory("uploads/{$u['id']}/chunks");
    }
    private function verifyPublished(UploadStore $store,array $u,string $final): void
    {
        $path=$store->disk()->path($final); if(filesize($path)!==$u['size'] || hash_file('sha256',$path)!==$u['sha256']) throw new \RuntimeException('Existing published file failed verification.');
        $u['status']='complete'; $u['final_sha256']=$u['sha256']; $store->write($u); $store->disk()->deleteDirectory("uploads/{$u['id']}/chunks");
    }
    public function failed(?Throwable $e): void { $s=app(UploadStore::class); $u=$s->read($this->uploadId); if($u){$u['status']='failed';$u['error']=$e?->getMessage()??'Assembly failed';$s->write($u);} }
}
