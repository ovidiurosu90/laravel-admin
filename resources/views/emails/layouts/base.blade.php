{{-- The main app's email frame, used when the MyFinance2 package (whose emails.layouts.base this mirrors) is not
     installed: the same sections, so a template can extend either one (DailyLogErrors::layout()). Sections: width
     (px, default 720), styles, title, subtitle (optional), body, brand (default APP_NAME), footer-title and footer
     (optional second footer line). Keep its look in step with the package's layout. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <meta name="robots" content="noindex,nofollow" />
    <style>
        body { background-color: #F9F9F9; color: #222; font: 14px/1.6 Helvetica, Arial, sans-serif; margin: 0;
            padding: 20px; }
        .container { max-width: @yield('width', '720')px; margin: 0 auto; background: #fff; border: 1px solid #ddd;
            border-radius: 6px; overflow: hidden; }
        .header { background: #1a1a2e; color: #fff; padding: 20px 24px; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 600; }
        .header .sub { margin-top: 6px; font-size: 13px; color: #c9c9d6; }
        .body { padding: 24px; }
        .section { margin-bottom: 20px; padding: 16px; background: #f8f9fa; border-radius: 4px;
            border-left: 4px solid #6c757d; }
        .text-muted { color: #6c757d; }
        .actions { margin-top: 20px; }
        .action-link { display: inline-block; margin: 6px 8px 6px 0; padding: 8px 16px; border-radius: 4px;
            background: #0d6efd; color: #fff !important; font-size: 13px; font-weight: 600; text-decoration: none; }
        .footer { padding: 16px 24px; background: #f8f9fa; border-top: 1px solid #ddd; font-size: 12px;
            color: #6c757d; }
        @yield('styles')
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>@yield('title')</h1>
        @hasSection('subtitle')
        <div class="sub">@yield('subtitle')</div>
        @endif
    </div>
    <div class="body">
        @yield('body')
    </div>
    <div class="footer">
        <strong>@yield('brand', config('app.name'))</strong> &middot; @yield('footer-title')
        @hasSection('footer')
        <br>@yield('footer')
        @endif
    </div>
</div>
</body>
</html>
