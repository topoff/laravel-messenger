<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mail Manager Custom Mail Action</title>
    <style>
        :root { --line: #e5e7eb; --bg: #f8fafc; --card: #fff; --muted: #6b7280; --ok: #0f766e; --fail: #b91c1c; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: var(--bg); margin: 0; padding: 24px; color: #111827; }
        .wrap { max-width: 920px; margin: 0 auto; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        h1 { margin: 0 0 12px; font-size: 24px; }
        p { color: var(--muted); margin-top: 0; }
        label { font-weight: 600; display: block; margin-bottom: 6px; }
        input, textarea { width: 100%; border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; box-sizing: border-box; }
        textarea { min-height: 220px; resize: vertical; }
        .grid { display: grid; gap: 12px; }
        .actions { display: flex; gap: 10px; margin-top: 12px; }
        button, a.btn { border: 1px solid var(--line); border-radius: 8px; background: #111827; color: #fff; padding: 10px 14px; font-weight: 600; text-decoration: none; cursor: pointer; }
        a.btn.secondary, button.secondary { background: #fff; color: #111827; }
        .badge { display: inline-block; padding: 6px 10px; border-radius: 999px; font-weight: 700; font-size: 12px; }
        .ok { background: #ccfbf1; color: var(--ok); }
        .fail { background: #fee2e2; color: var(--fail); }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Custom Mail Action</h1>
        <p>Send a custom test email, with an additional recipient email field for direct test sending.</p>
        <a class="btn secondary" href="{{ $back_url }}">Back to SES/SNS Dashboard</a>
    </div>

    @if(session()->has('messenger_custom_mail_result'))
        @php($result = (array) session('messenger_custom_mail_result'))
        <div class="card">
            @if((bool) data_get($result, 'ok'))
                <span class="badge ok">Sent</span>
            @else
                <span class="badge fail">Failed</span>
            @endif
            <p>{{ data_get($result, 'message') }}</p>
        </div>
    @endif

    <div class="card">
        <form method="POST" action="{{ $send_url }}" class="grid">
            @csrf
            <div>
                <label for="mailer">Mailer</label>
                <select id="mailer" name="mailer" style="width:100%;border:1px solid var(--line);border-radius:8px;padding:10px 12px;box-sizing:border-box;background:#fff;">
                    @foreach(array_keys(config('mail.mailers', [])) as $mailerName)
                        <option value="{{ $mailerName }}" @selected(old('mailer', config('mail.default')) === $mailerName)>{{ strtoupper($mailerName) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="email">Recipient Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required>
            </div>
            <div>
                <label for="subject">Subject</label>
                <input id="subject" name="subject" type="text" value="{{ old('subject') }}" required>
            </div>
            <div>
                <label for="markdown">Email Body (Markdown)</label>
                <textarea id="markdown" name="markdown" required>{{ old('markdown') }}</textarea>
            </div>
            <div class="actions">
                <button type="submit">Send Email</button>
                <button class="secondary" type="submit" formaction="{{ $preview_url }}" formtarget="_blank">Preview</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
