<?php

namespace App\Services;

use App\Jobs\ProcessCallStageJob;
use App\Models\CallProcessingJob;
use App\Models\ProcessedCall;
use Throwable;

class CallProcessingService
{
    public const STAGES = [
        'AUDIO_VALIDATE',
        'AUDIO_PREPROCESS',
        'TRANSCRIBE',
        'CLEAN_TRANSCRIPT',
        'AI_ANALYSIS',
        'CRM_SYNC',
    ];

    public function __construct(
        private AudioService $audio,
        private TranscriptionService $transcription,
        private TranscriptService $transcripts,
        private AiAnalysisService $analysis,
        private SchoolNotifier $notifier,
    ) {}

    public function queueFromStart(ProcessedCall $call): void
    {
        $call->update([
            'processing_status' => 'QUEUED',
            'processing_error' => null,
        ]);

        ProcessCallStageJob::dispatch($call->id, self::STAGES[0]);
    }

    public function retry(ProcessedCall $call): void
    {
        $stage = $this->firstIncompleteStage($call);
        $call->update([
            'processing_status' => 'RETRY_PENDING',
            'processing_error' => null,
        ]);

        ProcessCallStageJob::dispatch($call->id, $stage);
    }

    public function runStage(int $callId, string $stage): void
    {
        $call = ProcessedCall::query()->findOrFail($callId);

        if (! in_array($stage, self::STAGES, true)) {
            return;
        }

        if ($this->stageCompleted($call, $stage)) {
            $this->dispatchNext($call, $stage);

            return;
        }

        $job = CallProcessingJob::query()->create([
            'call_id' => $call->id,
            'job_type' => $stage,
            'status' => 'running',
            'attempts' => 1,
            'max_attempts' => 3,
            'started_at' => now(),
        ]);

        try {
            $this->perform($call, $stage);
            $job->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            $this->dispatchNext($call->fresh(), $stage);
        } catch (Throwable $exception) {
            $job->update([
                'status' => 'failed',
                'error_code' => $stage,
                'error_message' => $exception->getMessage(),
            ]);
            $call->update([
                'processing_status' => 'RETRY_PENDING',
                'processing_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function markStageFailed(int $callId, Throwable $exception): void
    {
        $call = ProcessedCall::query()->find($callId);

        if ($call === null || $call->processing_status === 'COMPLETED') {
            return;
        }

        $call->update([
            'processing_status' => 'FAILED',
            'processing_error' => $exception->getMessage(),
        ]);
    }

    private function perform(ProcessedCall $call, string $stage): void
    {
        match ($stage) {
            'AUDIO_VALIDATE' => $this->audio->validate($call),
            'AUDIO_PREPROCESS' => $this->audio->preprocess($call->fresh()),
            'TRANSCRIBE' => $this->transcription->transcribe($call->fresh()),
            'CLEAN_TRANSCRIPT' => $this->transcripts->clean($call->fresh()),
            'AI_ANALYSIS' => $this->analysis->analyze($call->fresh()),
            'CRM_SYNC' => $this->notifier->notify($call->fresh()),
        };

        $status = match ($stage) {
            'AUDIO_VALIDATE' => 'PROCESSING_AUDIO',
            'AUDIO_PREPROCESS' => 'TRANSCRIBING',
            'TRANSCRIBE', 'CLEAN_TRANSCRIPT' => 'TRANSCRIPT_READY',
            'AI_ANALYSIS' => 'AI_READY',
            'CRM_SYNC' => 'COMPLETED',
        };

        if ($stage !== 'CRM_SYNC') {
            $call->fresh()->update(['processing_status' => $status]);
        }
    }

    private function stageCompleted(ProcessedCall $call, string $stage): bool
    {
        return CallProcessingJob::query()
            ->where('call_id', $call->id)
            ->where('job_type', $stage)
            ->where('status', 'completed')
            ->exists();
    }

    private function dispatchNext(ProcessedCall $call, string $stage): void
    {
        $next = $this->nextStage($stage);

        if ($next === null) {
            return;
        }

        $alreadyQueued = CallProcessingJob::query()
            ->where('call_id', $call->id)
            ->where('job_type', $next)
            ->whereIn('status', ['running', 'completed'])
            ->exists();

        if ($alreadyQueued) {
            return;
        }

        if ($next === 'AI_ANALYSIS') {
            $call->update(['processing_status' => 'ANALYZING']);
        }

        ProcessCallStageJob::dispatch($call->id, $next);
    }

    private function nextStage(string $stage): ?string
    {
        $index = array_search($stage, self::STAGES, true);

        if ($index === false || ! isset(self::STAGES[$index + 1])) {
            return null;
        }

        return self::STAGES[$index + 1];
    }

    private function firstIncompleteStage(ProcessedCall $call): string
    {
        foreach (self::STAGES as $stage) {
            if (! $this->stageCompleted($call, $stage)) {
                return $stage;
            }
        }

        return 'CRM_SYNC';
    }
}
