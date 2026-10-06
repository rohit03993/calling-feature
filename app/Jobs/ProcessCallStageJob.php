<?php

namespace App\Jobs;

use App\Models\ProcessedCall;
use App\Services\CallProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessCallStageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        public int $callId,
        public string $stage,
    ) {}

    public function handle(CallProcessingService $processing): void
    {
        $processing->runStage($this->callId, $this->stage);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $call = ProcessedCall::query()->find($this->callId);

        if ($call === null || $call->processing_status === 'COMPLETED') {
            return;
        }

        $call->update([
            'processing_status' => 'FAILED',
            'processing_error' => $exception->getMessage(),
        ]);
    }
}
