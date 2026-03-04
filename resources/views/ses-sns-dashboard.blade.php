<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mail Manager SES/SNS Dashboard</title>
    <style>
        :root {
            --ok: #0f766e;
            --warn: #b45309;
            --fail: #b91c1c;
            --muted: #6b7280;
            --bg: #f8fafc;
            --card: #ffffff;
            --line: #e5e7eb;
            --blue: #1d4ed8;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: var(--bg); margin: 0; padding: 24px; color: #111827; }
        .wrap { max-width: 1120px; margin: 0 auto; display: grid; gap: 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
        h1, h2, h3 { margin: 0 0 12px; }
        h1 { font-size: 24px; }
        h2 { font-size: 18px; }
        h3 { font-size: 15px; }
        .meta { color: var(--muted); margin-bottom: 8px; }
        .badge { display: inline-block; padding: 6px 10px; border-radius: 999px; font-weight: 700; font-size: 12px; }
        .ok { background: #ccfbf1; color: var(--ok); }
        .warn { background: #fef3c7; color: var(--warn); }
        .fail { background: #fee2e2; color: var(--fail); }
        .grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }

        /* Section nav */
        .section-nav { position: sticky; top: 0; z-index: 10; background: var(--bg); padding: 10px 0; display: flex; gap: 6px; flex-wrap: wrap; }
        .section-nav a { padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--muted); text-decoration: none; background: var(--card); border: 1px solid var(--line); }
        .section-nav a:hover { color: #111827; border-color: #cbd5e1; }
        [id] { scroll-margin-top: 60px; }

        /* Status cards with colored left border */
        .card-ok { border-left: 4px solid var(--ok); }
        .card-fail { border-left: 4px solid var(--fail); }
        .card-warn { border-left: 4px solid var(--warn); }
        .check-summary { font-size: 13px; color: var(--muted); margin-top: 8px; margin-bottom: 0; }

        /* Command result alert banner */
        .alert { border-radius: 12px; padding: 16px 20px; }
        .alert-ok { background: #ecfdf5; border: 1px solid #a7f3d0; }
        .alert-fail { background: #fef2f2; border: 1px solid #fecaca; }
        .alert pre { margin-top: 10px; }

        /* Actions */
        .button-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .actions-layout { display: grid; gap: 14px; }
        .actions-group { border: 1px solid var(--line); border-radius: 10px; padding: 12px; background: #fcfdff; }
        .actions-group h3 { margin-bottom: 8px; }
        .command-button { width: 100%; border: 1px solid var(--line); background: #f9fafb; color: #111827; border-radius: 10px; padding: 10px 12px; font-weight: 700; cursor: pointer; text-align: left; font-size: inherit; }
        .command-button:hover { border-color: #cbd5e1; background: #f3f4f6; }
        .command-button.danger { border-color: #fca5a5; background: #fef2f2; color: var(--fail); }
        .command-button.danger:hover { border-color: #f87171; background: #fee2e2; }
        .link-button { display: inline-block; border: 1px solid var(--line); background: #111827; color: #fff; border-radius: 10px; padding: 10px 12px; font-weight: 700; text-decoration: none; }
        .command-desc { margin: 6px 0 0; color: var(--muted); font-size: 12px; line-height: 1.4; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 8px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        th { color: var(--muted); font-weight: 600; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 6px; font-size: 12px; }
        pre { margin: 0; background: #0b1020; color: #d1d5db; padding: 14px; border-radius: 10px; overflow-x: auto; }

        /* Copy-to-clipboard button */
        .copyable { display: flex; align-items: flex-start; gap: 6px; }
        .copyable code { flex: 1; word-break: break-all; }
        .copy-btn { flex-shrink: 0; background: none; border: 1px solid var(--line); border-radius: 6px; padding: 2px 8px; font-size: 11px; color: var(--muted); cursor: pointer; white-space: nowrap; }
        .copy-btn:hover { color: #111827; border-color: #cbd5e1; }

        /* Identity section dividers (replaces nested cards) */
        .section-hr { border: none; border-top: 1px solid var(--line); margin: 20px 0; }

        /* Collapsible reference sections */
        .collapsible { background: var(--card); border: 1px solid var(--line); border-radius: 12px; }
        .collapsible summary { padding: 16px 20px; cursor: pointer; font-weight: 700; font-size: 18px; list-style: none; display: flex; align-items: center; gap: 8px; user-select: none; }
        .collapsible summary::before { content: '\25B6'; font-size: 10px; transition: transform 0.2s; }
        .collapsible[open] summary::before { transform: rotate(90deg); }
        .collapsible summary::-webkit-details-marker { display: none; }
        .collapsible-content { padding: 0 20px 20px; }

        ul { margin: 0; padding-left: 18px; }
        li { margin: 6px 0; }
        a { color: var(--blue); text-decoration: none; }
    </style>
</head>
<body>
<div class="wrap">
    {{-- Header --}}
    <div class="card">
        <h1>Amazon SES + SNS Dashboard</h1>
        <p class="meta">One place to setup and verify AWS SES sending + SES/SNS event tracking for a new app.</p>
    </div>

    {{-- Section Nav --}}
    <nav class="section-nav">
        <a href="#status">Status</a>
        <a href="#checks">Checks</a>
        <a href="#dns">DNS & Identities</a>
        <a href="#actions">Actions</a>
        <a href="#routes">Routes & AWS</a>
        <a href="#reference">Reference</a>
    </nav>

    {{-- Command Result Alert Banner --}}
    @if(session()->has('messenger_ses_sns_command_result'))
        @php $result = (array) session('messenger_ses_sns_command_result'); @endphp
        <div class="alert {{ (bool) data_get($result, 'ok') ? 'alert-ok' : 'alert-fail' }}" id="command-result">
            <p style="margin:0;">
                @if((bool) data_get($result, 'ok'))
                    <span class="badge ok">Success</span>
                @else
                    <span class="badge fail">Failed</span>
                @endif
                <code>{{ data_get($result, 'label') }}</code>
                <code>exit: {{ data_get($result, 'exit_code') }}</code>
            </p>
            <pre>{{ data_get($result, 'output') }}</pre>
        </div>
    @endif

    {{-- Status Overview --}}
    @php
        $sendingChecks = collect((array) data_get($sending, 'checks', []));
        $sendingPassed = $sendingChecks->where('ok', true)->count();
        $sendingTotal = $sendingChecks->count();
        $trackingChecks = collect((array) data_get($tracking, 'checks', []));
        $trackingPassed = $trackingChecks->where('ok', true)->count();
        $trackingTotal = $trackingChecks->count();
    @endphp
    <div class="grid" id="status">
        <div class="card {{ data_get($sending, 'ok') === true ? 'card-ok' : (data_get($sending, 'ok') === false ? 'card-fail' : 'card-warn') }}">
            <h2>Sending Status</h2>
            @if(data_get($sending, 'ok') === true)
                <span class="badge ok">Healthy</span>
            @elseif(data_get($sending, 'ok') === false)
                <span class="badge fail">Needs Fixes</span>
            @else
                <span class="badge warn">Unknown</span>
            @endif
            @if($sendingTotal > 0)
                <p class="check-summary">{{ $sendingPassed }}/{{ $sendingTotal }} checks passing</p>
            @endif
        </div>

        <div class="card {{ data_get($tracking, 'ok') === true ? 'card-ok' : (data_get($tracking, 'ok') === false ? 'card-fail' : 'card-warn') }}">
            <h2>Tracking Status</h2>
            @if(data_get($tracking, 'ok') === true)
                <span class="badge ok">Healthy</span>
            @elseif(data_get($tracking, 'ok') === false)
                <span class="badge fail">Needs Fixes</span>
            @else
                <span class="badge warn">Unknown</span>
            @endif
            @if($trackingTotal > 0)
                <p class="check-summary">{{ $trackingPassed }}/{{ $trackingTotal }} checks passing</p>
            @endif
        </div>

        <div class="card">
            <h2>Mail Transport</h2>
            <p><strong>Mailer:</strong> <code>{{ data_get($app_config, 'mail_default_mailer') ?: '(empty)' }}</code></p>
            <p><strong>From Email:</strong> <code>{{ data_get($app_config, 'mail_from_address') ?: '(empty)' }}</code></p>
            <p><strong>From Name:</strong> <code>{{ data_get($app_config, 'mail_from_name') ?: '(empty)' }}</code></p>
        </div>
    </div>

    {{-- BCC Warning --}}
    @if($bcc_address)
        <div class="card card-warn">
            <h2>BCC is Active</h2>
            <p>All outgoing emails are BCC'd to <code>{{ $bcc_address }}</code>. The BCC recipient shares the same SES message ID as the TO recipient, so SNS tracking events (delivery, bounce, complaint) for the BCC address are filtered out to prevent corrupting the original recipient's tracking data.</p>
            <p>If the BCC mailbox rejects mail, those bounces will <strong>not</strong> appear in tracking. Monitor the BCC mailbox separately.</p>
        </div>
    @endif

    {{-- Health Checks --}}
    <div class="grid" id="checks">
        <div class="card">
            <h2>Sending Checks (SES)</h2>
            @if(data_get($sending, 'error'))
                <p><span class="badge fail">{{ data_get($sending, 'error') }}</span></p>
            @endif
            <table>
                <tr>
                    <th>Status</th>
                    <th>Check</th>
                    <th>Details</th>
                </tr>
                @forelse((array) data_get($sending, 'checks', []) as $check)
                    <tr>
                        <td>
                            @if((bool) data_get($check, 'ok'))
                                <span class="badge ok">OK</span>
                            @else
                                <span class="badge fail">FAIL</span>
                            @endif
                        </td>
                        <td>{{ data_get($check, 'label') }}</td>
                        <td>{{ data_get($check, 'details') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="meta">No checks available.</td></tr>
                @endforelse
            </table>
        </div>

        <div class="card">
            <h2>Tracking Checks (SES/SNS)</h2>
            @if(data_get($tracking, 'error'))
                <p><span class="badge fail">{{ data_get($tracking, 'error') }}</span></p>
            @endif
            <table>
                <tr>
                    <th>Status</th>
                    <th>Check</th>
                    <th>Details</th>
                </tr>
                @forelse((array) data_get($tracking, 'checks', []) as $check)
                    <tr>
                        <td>
                            @if((bool) data_get($check, 'ok'))
                                <span class="badge ok">OK</span>
                            @else
                                <span class="badge fail">FAIL</span>
                            @endif
                        </td>
                        <td>{{ data_get($check, 'label') }}</td>
                        <td>{{ data_get($check, 'details') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="meta">No checks available.</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    {{-- DNS Records --}}
    <div class="card" id="dns">
        <h2>Required DNS Records (Sending)</h2>
        <table>
            <tr>
                <th>Status</th>
                <th>Type</th>
                <th>Name</th>
                <th>Values</th>
                <th>Identity</th>
            </tr>
            @forelse((array) data_get($sending, 'dns_records', []) as $record)
                @php $recordStatus = (string) data_get($record, 'status', ''); @endphp
                <tr>
                    <td>
                        @if($recordStatus === 'SUCCESS')
                            <span class="badge ok">{{ $recordStatus }}</span>
                        @elseif($recordStatus === 'PENDING')
                            <span class="badge warn">{{ $recordStatus }}</span>
                        @elseif($recordStatus !== '')
                            <span class="badge fail">{{ $recordStatus }}</span>
                        @else
                            <span class="badge warn">UNKNOWN</span>
                        @endif
                    </td>
                    <td><code>{{ data_get($record, 'type') }}</code></td>
                    <td class="copyable">
                        <code>{{ data_get($record, 'name') }}</code>
                        <button class="copy-btn" onclick="copyValue(this)">Copy</button>
                    </td>
                    <td class="copyable">
                        <code>{{ implode(' | ', (array) data_get($record, 'values', [])) }}</code>
                        <button class="copy-btn" onclick="copyValue(this)">Copy</button>
                    </td>
                    <td><code>{{ data_get($record, 'identity', '') }}</code></td>
                </tr>
            @empty
                <tr><td colspan="5" class="meta">No DNS records available (configure sending identity first).</td></tr>
            @endforelse
        </table>
    </div>

    {{-- Identity Details (flattened layout, no nested cards) --}}
    @foreach((array) data_get($sending, 'identities_details', []) as $identityKey => $detail)
        <div class="card">
            <h2>Identity Details: <code>{{ data_get($detail, 'identity', $identityKey) }}</code></h2>

            {{-- DKIM --}}
            <h3>DKIM</h3>
            @php $dkimStatus = (string) data_get($detail, 'dkim.status', ''); @endphp
            <p>
                <strong>Status:</strong>
                @if($dkimStatus === 'SUCCESS')
                    <span class="badge ok">{{ $dkimStatus }}</span>
                @elseif($dkimStatus === 'PENDING')
                    <span class="badge warn">{{ $dkimStatus }}</span>
                @elseif($dkimStatus !== '')
                    <span class="badge fail">{{ $dkimStatus }}</span>
                @else
                    <span class="badge warn">UNKNOWN</span>
                @endif
            </p>
            <p><strong>DKIM signatures:</strong> {{ data_get($detail, 'dkim.signing_enabled') ? 'Enabled' : 'Disabled' }}</p>
            @if(data_get($detail, 'dkim.current_signing_key_length'))
                <p><strong>Current signing key length:</strong> <code>{{ data_get($detail, 'dkim.current_signing_key_length') }}</code></p>
            @endif
            @if(data_get($detail, 'dkim.next_signing_key_length'))
                <p><strong>Next signing key length:</strong> <code>{{ data_get($detail, 'dkim.next_signing_key_length') }}</code></p>
            @endif
            @if(data_get($detail, 'dkim.last_key_generation_timestamp'))
                <p><strong>Last key generated:</strong> <code>{{ data_get($detail, 'dkim.last_key_generation_timestamp') }}</code></p>
            @endif

            @php $dkimTokens = (array) data_get($detail, 'dkim.tokens', []); @endphp
            <h3 style="margin-top: 12px;">Publish DNS Records (CNAME)</h3>
            @if(count($dkimTokens) > 0)
                <table>
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Value</th>
                    </tr>
                    @php $dkimDomain = (string) data_get($detail, 'domain', ''); @endphp
                    @foreach($dkimTokens as $token)
                        <tr>
                            <td><code>CNAME</code></td>
                            <td class="copyable">
                                <code>{{ $token }}._domainkey.{{ $dkimDomain }}</code>
                                <button class="copy-btn" onclick="copyValue(this)">Copy</button>
                            </td>
                            <td class="copyable">
                                <code>{{ $token }}.dkim.amazonses.com</code>
                                <button class="copy-btn" onclick="copyValue(this)">Copy</button>
                            </td>
                        </tr>
                    @endforeach
                </table>
            @else
                <p class="meta">No DKIM tokens available. Run setup to generate DKIM tokens.</p>
            @endif

            <hr class="section-hr">

            {{-- MAIL FROM --}}
            <h3>Custom MAIL FROM Domain</h3>
            @php $mfStatus = (string) data_get($detail, 'mail_from.status', ''); @endphp
            @php $mfDomain = (string) data_get($detail, 'mail_from.domain', ''); @endphp
            <p>
                <strong>Status:</strong>
                @if($mfStatus === 'SUCCESS')
                    <span class="badge ok">{{ $mfStatus }}</span>
                @elseif($mfStatus === 'PENDING')
                    <span class="badge warn">{{ $mfStatus }}</span>
                @elseif($mfStatus !== '')
                    <span class="badge fail">{{ $mfStatus }}</span>
                @else
                    <span class="badge warn">NOT SET</span>
                @endif
            </p>
            <p><strong>MAIL FROM domain:</strong> <code>{{ $mfDomain !== '' ? $mfDomain : '(not configured)' }}</code></p>
            @if(data_get($detail, 'mail_from.behavior_on_mx_failure'))
                <p><strong>Behavior on MX failure:</strong> <code>{{ data_get($detail, 'mail_from.behavior_on_mx_failure') }}</code></p>
            @endif

            @if($mfDomain !== '')
                @php $region = (string) config('messenger.ses_sns.aws.region', 'eu-central-1'); @endphp
                <h3 style="margin-top: 12px;">MAIL FROM DNS Records</h3>
                <table>
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Value</th>
                    </tr>
                    <tr>
                        <td><code>MX</code></td>
                        <td class="copyable">
                            <code>{{ $mfDomain }}</code>
                            <button class="copy-btn" onclick="copyValue(this)">Copy</button>
                        </td>
                        <td class="copyable">
                            <code>10 feedback-smtp.{{ $region }}.amazonses.com</code>
                            <button class="copy-btn" onclick="copyValue(this)">Copy</button>
                        </td>
                    </tr>
                    <tr>
                        <td><code>TXT</code></td>
                        <td class="copyable">
                            <code>{{ $mfDomain }}</code>
                            <button class="copy-btn" onclick="copyValue(this)">Copy</button>
                        </td>
                        <td class="copyable">
                            <code>"v=spf1 include:amazonses.com -all"</code>
                            <button class="copy-btn" onclick="copyValue(this)">Copy</button>
                        </td>
                    </tr>
                </table>
            @endif

            <hr class="section-hr">

            {{-- DMARC --}}
            <h3>DMARC</h3>
            <p class="meta">DMARC specifies how email servers should handle messages that fail the authentication checks.</p>
            <table>
                <tr>
                    <th>Type</th>
                    <th>Name</th>
                    <th>Value</th>
                </tr>
                <tr>
                    <td><code>TXT</code></td>
                    <td class="copyable">
                        <code>{{ data_get($detail, 'dmarc.record_name') }}</code>
                        <button class="copy-btn" onclick="copyValue(this)">Copy</button>
                    </td>
                    <td class="copyable">
                        <code>{{ data_get($detail, 'dmarc.record_value') }}</code>
                        <button class="copy-btn" onclick="copyValue(this)">Copy</button>
                    </td>
                </tr>
            </table>
        </div>
    @endforeach

    {{-- Actions --}}
    <div class="card" id="actions">
        <h2>Actions</h2>
        <p><a class="link-button" href="{{ $custom_mail_action_url }}" target="_blank" rel="noopener">Open Custom Mail Action</a></p>
        @php
            $allButtons = collect((array) $command_buttons);
            $groupedButtons = [
                'Setup' => $allButtons->filter(fn (array $button): bool => str_starts_with((string) data_get($button, 'label', ''), 'Setup')),
                'Checks' => $allButtons->filter(fn (array $button): bool => str_starts_with((string) data_get($button, 'label', ''), 'Check')),
                'Simulator Tests' => $allButtons->filter(fn (array $button): bool => str_starts_with((string) data_get($button, 'label', ''), 'Test') && ! str_contains((string) data_get($button, 'label', ''), 'DB Verify')),
                'Simulator + DB Verify' => $allButtons->filter(fn (array $button): bool => str_contains((string) data_get($button, 'label', ''), 'DB Verify')),
                'Cleanup' => $allButtons->filter(fn (array $button): bool => str_starts_with((string) data_get($button, 'label', ''), 'Teardown')),
            ];
        @endphp
        <div class="actions-layout">
            @foreach($groupedButtons as $groupLabel => $buttons)
                @continue($buttons->isEmpty())
                <div class="actions-group">
                    <h3>{{ $groupLabel }}</h3>
                    <div class="button-grid">
                        @foreach($buttons as $button)
                            @php $isTeardown = str_starts_with((string) data_get($button, 'label', ''), 'Teardown'); @endphp
                            <form method="POST" action="{{ data_get($button, 'url') }}"
                                @if($isTeardown) onsubmit="return confirm('This will remove all SES/SNS tracking resources. if ses is your current MAIL_MAILER, Mails WON\'T be sent anymore! Are you sure?')" @endif
                            >
                                @csrf
                                <button type="submit" class="command-button{{ $isTeardown ? ' danger' : '' }}">{{ data_get($button, 'label') }}</button>
                                <p class="command-desc">{{ data_get($button, 'description') }}</p>
                            </form>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Tracking Routes & AWS Console --}}
    <div class="grid" id="routes">
        <div class="card">
            <h2>Tracking Routes</h2>
            <ul>
                <li>Open pixel: <code>{{ data_get($routes, 'tracking_open') ?: '(route not available)' }}</code></li>
                <li>Click redirect: <code>{{ data_get($routes, 'tracking_click') ?: '(route not available)' }}</code></li>
                <li>SNS callback: <code>{{ data_get($routes, 'sns_callback') ?: '(route not available)' }}</code></li>
            </ul>
        </div>

        <div class="card">
            <h2>AWS Console Cross-Check</h2>
            <ul>
                <li><a href="{{ data_get($tracking, 'aws_console.ses_dashboard', '#') }}" target="_blank" rel="noopener">SES Dashboard</a></li>
                <li><a href="{{ data_get($tracking, 'aws_console.ses_identities', '#') }}" target="_blank" rel="noopener">SES Identities</a></li>
                <li><a href="{{ data_get($tracking, 'aws_console.ses_configuration_sets', '#') }}" target="_blank" rel="noopener">SES Configuration Sets</a></li>
                <li><a href="{{ data_get($tracking, 'aws_console.ses_reputation', '#') }}" target="_blank" rel="noopener">SES Reputation Manager</a></li>
                <li><a href="{{ data_get($tracking, 'aws_console.ses_tenants', '#') }}" target="_blank" rel="noopener">SES Multi-Tenant (VDM)</a></li>
                <li><a href="{{ data_get($tracking, 'aws_console.sns_topics', '#') }}" target="_blank" rel="noopener">SNS Topics</a></li>
                <li><a href="{{ data_get($tracking, 'aws_console.sns_subscriptions', '#') }}" target="_blank" rel="noopener">SNS Subscriptions</a></li>
            </ul>
        </div>
    </div>

    {{-- Reference Sections (collapsed by default) --}}
    <div id="reference" style="display: grid; gap: 16px;">
        <details class="collapsible">
            <summary>Required Environment Variables</summary>
            <div class="collapsible-content">
                <ul>
                    @foreach($required_env as $name)
                        <li><code>{{ $name }}</code></li>
                    @endforeach
                </ul>
            </div>
        </details>

        <details class="collapsible">
            <summary>App Configuration Snapshot</summary>
            <div class="collapsible-content">
                <table>
                    @foreach((array) $app_config as $key => $value)
                        <tr>
                            <th>{{ $key }}</th>
                            <td>
                                @if(is_bool($value))
                                    <code>{{ $value ? 'true' : 'false' }}</code>
                                @elseif(is_array($value))
                                    <code>{{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code>
                                @else
                                    <code>{{ $value !== '' ? (string) $value : '(empty)' }}</code>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </details>

        <details class="collapsible">
            <summary>Artisan Setup Commands</summary>
            <div class="collapsible-content">
                <pre>@foreach($commands as $command){{ $command }}
@endforeach</pre>
            </div>
        </details>
    </div>
</div>

<script>
    function copyValue(btn) {
        var code = btn.parentElement.querySelector('code');
        if (!code) return;
        navigator.clipboard.writeText(code.textContent.trim()).then(function () {
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
        });
    }
</script>
</body>
</html>
