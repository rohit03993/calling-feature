<?php

namespace App\Services;

use App\Models\ProcessedCall;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class AudioService
{
    public function store(ProcessedCall $call, UploadedFile $file): void
    {
        $schoolCode = $call->school()->value('code') ?: 'school';
        $recorded = $call->recorded_at ?? now();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'm4a');
        $key = $schoolCode.'/'.$recorded->format('Y/m/d').'/'.$call->public_id.'.'.$extension;

        Storage::disk('calls')->putFileAs(
            dirname($key),
            $file,
            basename($key),
        );

        $checksum = hash_file('sha256', Storage::disk('calls')->path($key));

        $call->update([
            'original_storage_key' => $key,
            'audio_mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            'audio_size' => $file->getSize() ?: Storage::disk('calls')->size($key),
            'audio_checksum' => $checksum,
            'processing_status' => 'UPLOADED',
        ]);
    }

    public function validate(ProcessedCall $call): void
    {
        $key = (string) $call->original_storage_key;

        if ($key === '' || ! Storage::disk('calls')->exists($key)) {
            throw new RuntimeException('The recording file is missing.');
        }

        $size = Storage::disk('calls')->size($key);

        if ($size < 100) {
            throw new RuntimeException('The recording file is too small to be a call.');
        }

        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        $allowed = ['m4a', 'mp3', 'wav', 'aac', 'ogg', 'webm', 'mp4', 'm4v', '3gp', 'amr'];

        if (! in_array($extension, $allowed, true)) {
            throw new RuntimeException('This audio type is not supported.');
        }

        $duration = $this->probeDuration(Storage::disk('calls')->path($key));

        if ($duration !== null) {
            if ($duration > (int) config('call_ai.max_duration_seconds')) {
                throw new RuntimeException('This recording is longer than the allowed limit.');
            }

            $call->update(['duration_seconds' => (int) round($duration)]);
        }
    }

    public function preprocess(ProcessedCall $call): void
    {
        if ((bool) config('call_ai.skip_ffmpeg')) {
            $call->update(['processing_storage_key' => $call->original_storage_key]);

            return;
        }

        $sourceKey = (string) $call->original_storage_key;
        $targetKey = preg_replace('/\.[^.]+$/', '', $sourceKey).'-processing.wav';
        $source = Storage::disk('calls')->path($sourceKey);
        $target = Storage::disk('calls')->path($targetKey);

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        $process = new Process([
            (string) config('call_ai.ffmpeg_binary'),
            '-y',
            '-i',
            $source,
            '-ar',
            '16000',
            '-ac',
            '1',
            '-c:a',
            'pcm_s16le',
            $target,
        ]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($target)) {
            throw new RuntimeException('FFmpeg could not prepare the recording. Install FFmpeg on the Call AI server.');
        }

        $call->update(['processing_storage_key' => $targetKey]);
    }

    public function absolutePath(ProcessedCall $call): string
    {
        $key = $call->processing_storage_key ?: $call->original_storage_key;

        if ($key === null || ! Storage::disk('calls')->exists($key)) {
            throw new RuntimeException('The recording file is missing.');
        }

        return Storage::disk('calls')->path($key);
    }

    private function probeDuration(string $path): ?float
    {
        if ((bool) config('call_ai.skip_ffmpeg')) {
            return null;
        }

        $process = new Process([
            (string) config('call_ai.ffprobe_binary'),
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $value = trim($process->getOutput());

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
