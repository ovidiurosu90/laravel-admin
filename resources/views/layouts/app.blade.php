<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('template_title')@yield('template_title') | @endif {{
        config('app.name', Lang::get('titles.app')) }}</title>
    <meta name="description" content="">
    <base href="{{ config('app.url') }}">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap"
        rel="stylesheet" />
    {{--
    HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries
     --}}
    <!--[if lt IE 9]>
        <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js">
        </script>
        <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->
    @yield('template_linked_fonts')

    <!-- Before VITE -->
    @vite(['resources/assets/sass/app.scss', 'resources/assets/js/app.js'])

    <!-- After VITE -->

    @yield('template_linked_css')

    <!-- template_fastload_css & CSS for authenticated users -->
    <style type="text/css">
        @yield('template_fastload_css')

        @if (Auth::User() && (Auth::User()->profile)
             && (Auth::User()->profile->avatar_status == 0))
        .user-avatar-nav {
            background: url({{ Gravatar::get(Auth::user()->email)
                }}) 50% 50% no-repeat;
            background-size: auto 100%;
        }
        @endif
    </style>

    <link rel="stylesheet" type="text/css" href="/css/app.css">

    <script>
        window.Laravel = {!! json_encode([
            'csrfToken' => csrf_token(),
        ]) !!};
    </script>

    @if (Auth::User() && (Auth::User()->profile) && $theme->link != null
         && $theme->link != 'null')
    <link rel="stylesheet" type="text/css" href="{{ $theme->link }}">
    @endif

    <!-- head -->
    @yield('head')

    <!-- ga-analytics -->
    @include('scripts.ga-analytics')

    <!-- barryvdh/laravel-debugbar{{-- Added automatically --}} -->
</head>

<body>
    <div id="app">
        @include('partials.nav')
        <main class="py-4">
            <div class="container">
                <div class="row">
                    <div class="col-12">
                        @include('partials.form-status')
                    </div>
                </div>
            </div>
            @yield('content')
        </main>
    </div>

    <!-- footer_scripts -->

    @yield('footer_scripts')

    @role('admin')
        @canImpersonate
            @include('scripts.impersonate-user')
        @endCanImpersonate
    @else
        @impersonating
            @include('scripts.impersonate-user')
        @endImpersonating
    @endrole

    <!-- barryvdh/laravel-debugbar{{-- Added automatically --}} -->
</body>
</html>

