<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <meta name="robots" content="noindex,nofollow" />
    <style>
        body { background-color: #F9F9F9; color: #222; font: 14px/1.6 Helvetica, Arial, sans-serif; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; border: 1px solid #ddd; border-radius: 6px; overflow: hidden; }
        .header { background: #1a1a2e; color: #fff; padding: 20px 24px; }
        .header h1 { margin: 0 0 4px; font-size: 20px; font-weight: 600; }
        .header .meta { font-size: 13px; opacity: .75; }
        .env-badge { display: inline-block; background: rgba(255,255,255,.15); border-radius: 3px;
                     padding: 1px 7px; font-size: 12px; font-weight: 700; letter-spacing: .06em;
                     margin-right: 8px; vertical-align: middle; }
        .body { padding: 24px; }
        .file-section { margin-bottom: 28px; }
        .file-section:last-child { margin-bottom: 0; }
        .file-heading { font-size: 13px; font-weight: 700; text-transform: uppercase; color: #6c757d;
                        border-bottom: 1px solid #dee2e6; padding-bottom: 6px; margin-bottom: 14px; }
        .file-heading .filename { font-family: Consolas, Monaco, monospace; background: #e9ecef;
                                  padding: 2px 7px; border-radius: 3px; font-size: 12px; text-transform: none; }
        .file-heading .count { font-weight: 400; margin-left: 8px; }
        .section { margin-bottom: 12px; padding: 14px 16px; background: #f8f9fa; border-radius: 4px;
                   border-left: 4px solid #dc3545; }
        .section.warning { border-left-color: #ffc107; }
        .entry-meta { font: 12px/1.4 Helvetica, Arial, sans-serif; color: #6c757d; margin-bottom: 8px; }
        .entry-count { display: inline-block; background: #dc3545; color: #fff; font-weight: 700;
                       border-radius: 3px; padding: 1px 7px; margin-right: 8px; }
        .section.warning .entry-count { background: #856404; }
        .entry-range { font-family: Consolas, Monaco, monospace; }
        .log-block { font: 12px/1.6 Consolas, Monaco, Menlo, monospace; white-space: pre-wrap;
                     word-break: break-all; margin: 0; color: #212529; }
        .line-error, .line-critical, .line-alert, .line-emergency { color: #dc3545; font-weight: 700; }
        .line-warning { color: #856404; font-weight: 700; }
        .footer { padding: 14px 24px; background: #f8f9fa; border-top: 1px solid #ddd;
                  font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1><span class="env-badge">{{ strtoupper(config('app.env')) }}</span> Daily Log Errors</h1>
        @php
            $issueCount = array_sum(array_map('count', $results));
            $occurrenceCount = array_sum(array_map(
                fn ($entries) => array_sum(array_column($entries, 'count')),
                $results
            ));
        @endphp
        <div class="meta">
            {{ $date->format('l, F j Y') }}
            &middot; {{ $issueCount }} distinct issue(s), {{ $occurrenceCount }} occurrence(s)
            across {{ count($results) }} file(s)
        </div>
    </div>

    <div class="body">
        @foreach ($results as $filename => $entries)
            <div class="file-section">
                <div class="file-heading">
                    <span class="filename">{{ $filename }}</span>
                    <span class="count">{{ count($entries) }} distinct issue(s)</span>
                </div>

                @foreach ($entries as $index => $entry)
                    @php
                        $isWarning = $entry['level'] === 'WARNING';
                        $range = $entry['first'] === $entry['last']
                            ? $entry['first']
                            : $entry['first'] . ' to ' . $entry['last'];
                    @endphp
                    <div class="section {{ $isWarning ? 'warning' : '' }}">
                        <div class="entry-meta">
                            <span class="entry-count">&times;{{ $entry['count'] }}</span>
                            @if ($entry['count'] > 1)
                                {{ $entry['count'] }} occurrences,
                                <span class="entry-range">{{ $range }}</span>
                            @else
                                <span class="entry-range">{{ $entry['first'] }}</span>
                            @endif
                        </div>
                        <pre class="log-block">{!! $highlighted[$filename][$index] !!}</pre>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="footer">
        <strong>{{ config('app.name') }}</strong> &middot; {{ config('app.url') }}
    </div>
</div>
</body>
</html>
