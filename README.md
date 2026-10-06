# Call AI

This folder is the hidden call-processing service. Schools do not open it.

The School CRM uploads a recording here. This app stores the audio, writes the transcript, writes the summary, and sends the result back to that school.

## Local setup

1. Put your OpenAI key in `.env` as `OPENAI_API_KEY`.
2. Install FFmpeg and make sure `ffmpeg` works in the terminal.
3. Run:

```powershell
cd "F:\Rohit Development\calling feature"
php artisan migrate --seed
php artisan serve --port=8001
php artisan queue:work
```

Keep the website and the queue worker running in two terminals.

The school secret and the callback secret in this `.env` must match the Call AI setup inside the School CRM.

## Test routes

These work only when `CALL_AI_TEST_ROUTES=true`.

- `POST /api/test/transcribe` with an audio file
- `POST /api/test/analyze` with a transcript

Every request needs:

```http
Authorization: Bearer <school secret>
X-School-Code: <school code>
```
