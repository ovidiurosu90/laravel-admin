{{-- Daily log digest: WARNING and above entries of the previous day, grouped per log file, each with its occurrence
     count and time range. On the MyFinance2 emails' shared layout (header, footer, button row) when that package is
     installed, else on the main app's fallback with the same sections ($layout, DailyLogErrors::layout()), with the
     app's own name in the footer. Counts and plurals are prepared in the DailyLogErrors mailable. --}}
@extends($layout)
@use('App\Mail\DailyLogErrors')

@section('width', '1024')

@section('styles')
        .file-section { margin-bottom: 28px; }
        .file-heading { font-size: 13px; font-weight: 700; text-transform: uppercase; color: #6c757d;
            border-bottom: 1px solid #dee2e6; padding-bottom: 6px; margin-bottom: 14px; }
        .file-heading .filename { font-family: Consolas, Monaco, monospace; background: #e9ecef;
            padding: 2px 7px; border-radius: 3px; font-size: 12px; text-transform: none; }
        .file-heading .count { font-weight: 400; margin-left: 8px; }
        .section { margin-bottom: 12px; padding: 14px 16px; border-left-color: #dc3545; }
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
@endsection

@section('title', '⚠️ Daily Log Errors')

@section('subtitle')
    {{ strtoupper(app()->environment()) }} &middot; {{ $date->format('l, F j Y') }} &middot; {{ $summary }}
@endsection

@section('body')
    @foreach ($results as $filename => $entries)
        <div class="file-section">
            <div class="file-heading">
                <span class="filename">{{ $filename }}</span>
                <span class="count">{{ DailyLogErrors::counted(count($entries), 'distinct issue') }}</span>
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
                            {{ DailyLogErrors::counted($entry['count'], 'occurrence') }},
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

    @includeFirst(['myfinance2::emails.partials.action-links', 'emails.partials.action-links'], ['links' => [
        ['label' => '→ Open Logs', 'url' => $logsUrl],
    ]])
@endsection

@section('brand', config('app.name'))

@section('footer-title', 'Daily log errors')

@section('footer')
    {{ config('app.url') }}
@endsection
