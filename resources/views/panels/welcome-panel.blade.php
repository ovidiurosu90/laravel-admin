@php

    $levelAmount = 'level';

    if (Auth::User()->level() >= 2) {
        $levelAmount = 'levels';
    }

@endphp

<div class="card">
    <div class="card-header @role('admin', true) bg-secondary text-white @endrole">

        Welcome {{ Auth::user()->name }}

        @role('admin', true)
            <span class="pull-right badge text-bg-primary" style="margin-top:4px">
                Admin Access
            </span>
        @else
            <span class="pull-right badge text-bg-warning" style="margin-top:4px">
                User Access
            </span>
        @endrole

    </div>
    <div class="card-body">
        <h2 class="lead">
            {{ trans('auth.loggedIn') }}
        </h2>

        <p>
            You have
                <strong>
                    @role('admin')
                       Admin
                    @endrole
                    @role('user')
                       User
                    @endrole
                </strong>
            Access
        </p>

        <hr>

        <p>
            You have access to {{ $levelAmount }}:
            @level(5)
                <span class="badge text-bg-primary margin-half">5</span>
            @endlevel

            @level(4)
                <span class="badge text-bg-info margin-half">4</span>
            @endlevel

            @level(3)
                <span class="badge text-bg-success margin-half">3</span>
            @endlevel

            @level(2)
                <span class="badge text-bg-warning margin-half">2</span>
            @endlevel

            @level(1)
                <span class="badge bg-light text-dark margin-half">1</span>
            @endlevel
        </p>

        <hr>

        <p>
            You have roles:
            @foreach (Auth::user()->getRoles() as $role)
                <span class="badge text-bg-secondary margin-half margin-left-0">
                    {{ $role->name }}
                </span>
            @endforeach
        </p>

        <hr>

        <p>
            You have permissions:
            @permission('view.users')
                <span class="badge text-bg-primary margin-half margin-left-0">
                    {{ trans('permsandroles.permissionView') }}
                </span>
            @endpermission

            @permission('create.users')
                <span class="badge text-bg-info margin-half margin-left-0">
                    {{ trans('permsandroles.permissionCreate') }}
                </span>
            @endpermission

            @permission('edit.users')
                <span class="badge text-bg-warning margin-half margin-left-0">
                    {{ trans('permsandroles.permissionEdit') }}
                </span>
            @endpermission

            @permission('delete.users')
                <span class="badge text-bg-danger margin-half margin-left-0">
                    {{ trans('permsandroles.permissionDelete') }}
                </span>
            @endpermission

            <br>

            @foreach (Auth::user()->getPermissions() as $permission)
                <span class="badge text-bg-light margin-half margin-left-0">
                    {{ $permission->name }}
                </span>
            @endforeach
        </p>
    </div>
</div>
