<nav class="navbar navbar-expand-md navbar-light navbar-laravel">
    <div class="container">
        <a class="navbar-brand" href="{{ url('/home') }}">
            {!! config('app.name', trans('titles.app')) !!}
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#navbarSupportedContent"
            aria-controls="navbarSupportedContent" aria-expanded="false"
            aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
            <span class="visually-hidden">{!! trans('titles.toggleNav') !!}</span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            {{-- Left Side Of Navbar --}}
            <ul class="navbar-nav me-auto">
            @role('admin')
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#"
                        id="navbarDropdown" role="button" data-bs-toggle="dropdown"
                        aria-haspopup="true" aria-expanded="false">
                        {!! trans('titles.adminDropdownNav') !!}
                    </a>
                    <div class="dropdown-menu" aria-labelledby="navbarDropdown">
                        <a class="dropdown-item {{ (Request::is('roles')
                                || Request::is('permissions')) ? 'active' : null }}"
                            href="{{ route('laravelroles::roles.index') }}">
                            {!! trans('titles.laravelroles') !!}
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item {{ Request::is('users',
                                'users/' . Auth::user()->id, 'users/'
                                . Auth::user()->id . '/edit') ? 'active' : null }}"
                            href="{{ url('/users') }}">
                            {!! trans('titles.adminUserList') !!}
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item {{ Request::is('users/create')
                            ? 'active' : null }}"
                            href="{{ url('/users/create') }}">
                            {!! trans('titles.adminNewUser') !!}
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item {{ Request::is('themes',
                                'themes/create') ? 'active' : null }}"
                            href="{{ url('/themes') }}">
                            {!! trans('titles.adminThemesList') !!}
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item {{ Request::is('logs')
                                ? 'active' : null }}"
                            href="{{ url('/logs') }}">
                            {!! trans('titles.adminLogs') !!}
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item {{ Request::is('activity')
                                ? 'active' : null }}"
                            href="{{ url('/activity') }}">
                            {!! trans('titles.adminActivity') !!}
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item {{ Request::is('phpinfo')
                                ? 'active' : null }}"
                            href="{{ url('/phpinfo') }}">
                            {!! trans('titles.adminPHP') !!}
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item {{ Request::is('routes')
                                ? 'active' : null }}"
                            href="{{ url('/routes') }}">
                            {!! trans('titles.adminRoutes') !!}
                        </a>
                        {{--
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item {{ Request::is('active-users')
                                ? 'active' : null }}"
                            href="{{ url('/active-users') }}">
                            {!! trans('titles.activeUsers') !!}
                        </a>
                        --}}
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item {{ Request::is('blocker')
                                ? 'active' : null }}"
                            href="{{ route('laravelblocker::blocker.index') }}">
                            {!! trans('titles.laravelBlocker') !!}
                        </a>
                    </div>
                </li>
            @endrole
            @include('myfinance2::partials.nav')
            </ul>

            {{-- Right Side Of Navbar --}}
            <ul class="navbar-nav ms-auto" id="navbar-right">
            {{-- Authentication Links --}}
            @guest
                <li>
                    <a class="nav-link" href="{{ route('login') }}">
                        {{ trans('titles.login') }}
                    </a>
                </li>

                @if (Route::has('register'))
                <li>
                    <a class="nav-link" href="{{ route('register') }}">
                        {{ trans('titles.register') }}
                    </a>
                </li>
                @endif

            @else
                <li class="nav-item dropdown">
                    <a id="navbarDropdown" class="nav-link dropdown-toggle"
                        href="#" role="button" data-bs-toggle="dropdown"
                        aria-haspopup="true" aria-expanded="false" v-pre>

                    @if ((Auth::User()->profile)
                         && Auth::user()->profile->avatar_status == 1)
                        <img src="{{ Auth::user()->profile->avatar }}"
                            alt="{{ Auth::user()->name }}"
                            class="user-avatar-nav">
                    @else
                        <div class="user-avatar-nav"></div>
                    @endif

                        {{ Auth::user()->name }} <span class="caret"></span>
                    </a>

                    <div class="dropdown-menu" aria-labelledby="navbarDropdown">
                        <a class="dropdown-item {{
                                Request::is('profile/' . Auth::user()->name,
                                    'profile/'.Auth::user()->name . '/edit')
                                ? 'active' : null }}"
                            href="{{ url('/profile/'.Auth::user()->name) }}">
                            {!! trans('titles.profile') !!}
                        </a>

                        @role('admin')
                            @canImpersonate
                                <div class="dropdown-divider"></div>
                                @include('partials.nav-impersonate-user')
                            @endCanImpersonate
                        @else
                            @impersonating
                                <div class="dropdown-divider"></div>
                                @include('partials.nav-impersonate-user')
                            @endImpersonating
                        @endrole

                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="{{ route('logout') }}"
                           onclick="event.preventDefault();
                                document.getElementById('logout-form').submit();">
                            {{ ('Logout') }}
                        </a>
                        <form id="logout-form" action="{{ route('logout') }}"
                            method="POST" style="display: none;">
                            @csrf
                        </form>
                    </div>
                </li>
            @endguest
            </ul>
        </div>
    </div>
</nav>

