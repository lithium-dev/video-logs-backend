<?php

namespace Lithium\VideoLogs\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Lithium\VideoLogs\Contracts\VideoStorageProvider;
use Lithium\VideoLogs\Http\Requests\AbortVideoLogUploadRequest;
use Lithium\VideoLogs\Http\Requests\CheckVideoLogStatusRequest;
use Lithium\VideoLogs\Http\Requests\FinalizeVideoLogUploadRequest;
use Lithium\VideoLogs\Http\Requests\IndexVideoLogRequest;
use Lithium\VideoLogs\Http\Requests\RetryVideoLogProcessingRequest;
use Lithium\VideoLogs\Http\Requests\SignUploadPartsRequest;
use Lithium\VideoLogs\Http\Requests\StoreVideoLogRequest;
use Lithium\VideoLogs\Http\Requests\UpdateVideoLogRequest;
use Lithium\VideoLogs\Http\Requests\UploadStatusVideoLogRequest;
use Lithium\VideoLogs\Jobs\DeleteVideoLogAssets;
use Lithium\VideoLogs\Jobs\ProcessVideoLog;
use Lithium\VideoLogs\Models\VideoLog;
use Lithium\VideoLogs\Support\WebhookRequest;

class VideoLogController extends Controller
{
    public function __construct(
        private readonly VideoStorageProvider $videoStorageProvider,
    ) {}

    public function index(IndexVideoLogRequest $request)
    {
        $data = $request->validated();

        $query = VideoLog::query()
            ->selectAttributes()
            ->when(isset($data['loggable_type']), function ($query) use ($data) {
                return $query->where('loggable_type', $data['loggable_type']);
            })
            ->when(isset($data['loggable_id']), function ($query) use ($data) {
                return $query->where('loggable_id', $data['loggable_id']);
            })
            ->latest();

        if (isset($data['per_page'])) {
            return $query->paginate((int) $data['per_page']);
        }

        return $query->get();
    }

    public function store(StoreVideoLogRequest $request)
    {
        $data = $request->validated();
        $videoLog = VideoLog::create($data);
        $uploadTarget = $this->videoStorageProvider->createUpload($videoLog);
        $videoLog->appendAttributes();

        return [
            'upload_target' => $uploadTarget,
            'video_log' => $videoLog,
        ];
    }

    public function finalizeUpload(FinalizeVideoLogUploadRequest $request, VideoLog $videoLog)
    {
        $data = $request->validated();
        $this->videoStorageProvider->finalizeUpload($videoLog, $data);
        ProcessVideoLog::dispatch($videoLog->id);

        return $videoLog->fresh();
    }

    public function signUploadParts(SignUploadPartsRequest $request, VideoLog $videoLog)
    {
        $data = $request->validated();
        $parts = $this->videoStorageProvider->signUploadParts(
            $videoLog,
            $data['part_numbers'],
        );

        return [
            'parts' => $parts,
        ];
    }

    public function uploadStatus(UploadStatusVideoLogRequest $request, VideoLog $videoLog)
    {
        return $this->videoStorageProvider->uploadStatus($videoLog);
    }

    public function abortUpload(AbortVideoLogUploadRequest $request, VideoLog $videoLog)
    {
        $this->videoStorageProvider->abortUpload($videoLog);

        return response()->noContent();
    }

    public function retryProcessing(RetryVideoLogProcessingRequest $request, VideoLog $videoLog)
    {
        // Clear any state left behind by the previous (failed) attempt so the
        // provider's idempotency guard doesn't skip re-submitting the job.
        $metadata = $videoLog->provider_metadata ?? [];
        unset(
            $metadata['mediaconvert_job_id'],
            $metadata['output_prefix'],
            $metadata['playback_key'],
            $metadata['poster_key'],
            $metadata['failure'],
        );

        $videoLog->update([
            'status' => 'processing',
            'provider_metadata' => $metadata,
        ]);

        ProcessVideoLog::dispatch($videoLog->id);

        return $videoLog;
    }

    public function checkStatus(CheckVideoLogStatusRequest $request, VideoLog $videoLog)
    {
        // Pull the current status straight from the provider and reconcile it.
        // Useful when the completion webhook isn't configured or never arrived.
        $this->videoStorageProvider->checkStatus($videoLog);

        return $videoLog->fresh();
    }

    public function show(VideoLog $videoLog)
    {
        Gate::authorize('view', $videoLog);
        $videoLog->appendPlayback($this->videoStorageProvider);
        $videoLog->appendAttributes();

        return $videoLog;
    }

    public function update(UpdateVideoLogRequest $request, VideoLog $videoLog)
    {
        $videoLog->update($request->validated());

        return $videoLog;
    }

    public function destroy(VideoLog $videoLog)
    {
        Gate::authorize('delete', $videoLog);
        $videoLog->delete();

        DeleteVideoLogAssets::dispatch($videoLog->id);

        return $videoLog;
    }

    public function handleWebhook(Request $request)
    {
        $webhookRequest = new WebhookRequest(
            payload: $request->all(),
            headers: $request->headers->all(),
            rawBody: $request->getContent(),
        );
        $this->videoStorageProvider->handleWebhook($webhookRequest);

        return response()->noContent();
    }
}
