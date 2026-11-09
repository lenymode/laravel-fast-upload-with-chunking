<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\CleanupUpload;
use App\Jobs\AssembleUpload;
use App\Services\UploadStore;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('uploads:cleanup', function (UploadStore $store) {
    foreach ($store->disk()->directories('uploads') as $directory) {
        $id = basename($directory);
        if ($id === 'verified') continue;
        $upload = $store->read($id);
        if ($upload && $upload['status'] === 'uploading' && now()->isAfter($upload['expires_at'])) {
            $store->locked($id, function ($fresh) use ($store) {
                if ($fresh && $fresh['status'] === 'uploading') { $fresh['status'] = 'expired'; $store->write($fresh); }
            });
            CleanupUpload::dispatch($id);
            $this->line("Expired {$id}");
        }
    }
})->purpose('Expire abandoned upload sessions and queue chunk cleanup');

Artisan::command('uploads:recover', function (UploadStore $store) {
    foreach ($store->disk()->directories('uploads') as $directory) {
        $id = basename($directory);
        $upload = $id === 'verified' ? null : $store->read($id);
        if ($upload && $upload['status'] === 'queued' && ($upload['dispatch_pending'] ?? false)) {
            AssembleUpload::dispatch($id);
            $store->locked($id, function ($fresh) use ($store) { $fresh['dispatch_pending']=false; $store->write($fresh); });
            $this->line("Recovered {$id}");
        }
    }
})->purpose('Redispatch finalization work persisted before a queue outage');

Schedule::command('uploads:cleanup')->hourly()->withoutOverlapping();
Schedule::command('uploads:recover')->everyMinute()->withoutOverlapping();
