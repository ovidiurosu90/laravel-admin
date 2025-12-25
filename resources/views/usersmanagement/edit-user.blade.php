@extends('layouts.app')

@section('template_title'){!! trans('usersmanagement.editing-user', ['name' => $user->name]) !!}@endsection

@section('template_linked_css')
    <style type="text/css">
        .btn-save,
        .pw-change-container {
            display: none;
        }
    </style>
@endsection

@section('content')

    <div class="container">
        <div class="row">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            {!! trans('usersmanagement.editing-user', ['name' => $user->name]) !!}
                            <div class="pull-right">
                                <a href="{{ route('users') }}" class="btn btn-light btn-sm float-right" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ trans('usersmanagement.tooltips.back-users') }}">
                                    <i class="fa fa-fw fa-reply-all" aria-hidden="true"></i>
                                    {!! trans('usersmanagement.buttons.back-to-users') !!}
                                </a>
                                <a href="{{ url('/users/' . $user->id) }}" class="btn btn-light btn-sm float-right" data-bs-toggle="tooltip" data-bs-placement="left" title="{{ trans('usersmanagement.tooltips.back-users') }}">
                                    <i class="fa fa-fw fa-reply" aria-hidden="true"></i>
                                    {!! trans('usersmanagement.buttons.back-to-user') !!}
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        {{ html()->form('POST', route('users.update', $user->id))
                                ->attribute('role', 'form')
                                ->class('needs-validation')
                                ->open() }}

                            @csrf
                            @method('PUT')

                            <div class="mb-3 has-feedback row {{ $errors->has('name') ? ' has-error ' : '' }}">
                                <label for="name" class="col-md-3 control-label">{{ trans('forms.create_user_label_username') }}</label>
                                <div class="col-md-9">
                                    <div class="input-group">
                                        {{ html()->text('name', old('name', $user->name))->id('name')->class('form-control')->placeholder(trans('forms.create_user_ph_username')) }}
                                        <div class="input-group-append">
                                            <label class="input-group-text" for="name">
                                                <i class="fa fa-fw {{ trans('forms.create_user_icon_username') }}" aria-hidden="true"></i>
                                            </label>
                                        </div>
                                    </div>
                                    @if($errors->has('name'))
                                        <span class="help-block">
                                            <strong>{{ $errors->first('name') }}</strong>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mb-3 has-feedback row {{ $errors->has('first_name') ? ' has-error ' : '' }}">
                                <label for="first_name" class="col-md-3 control-label">{{ trans('forms.create_user_label_firstname') }}</label>
                                <div class="col-md-9">
                                    <div class="input-group">
                                        {{ html()->text('first_name', old('first_name', $user->first_name))->id('first_name')->class('form-control')->placeholder(trans('forms.create_user_ph_firstname')) }}
                                        <div class="input-group-append">
                                            <label class="input-group-text" for="first_name">
                                                <i class="fa fa-fw {{ trans('forms.create_user_icon_firstname') }}" aria-hidden="true"></i>
                                            </label>
                                        </div>
                                    </div>
                                    @if($errors->has('first_name'))
                                        <span class="help-block">
                                            <strong>{{ $errors->first('first_name') }}</strong>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mb-3 has-feedback row {{ $errors->has('last_name') ? ' has-error ' : '' }}">
                                <label for="last_name" class="col-md-3 control-label">{{ trans('forms.create_user_label_lastname') }}</label>
                                <div class="col-md-9">
                                    <div class="input-group">
                                        {{ html()->text('last_name', old('last_name', $user->last_name))->id('last_name')->class('form-control')->placeholder(trans('forms.create_user_ph_lastname')) }}
                                        <div class="input-group-append">
                                            <label class="input-group-text" for="last_name">
                                                <i class="fa fa-fw {{ trans('forms.create_user_icon_lastname') }}" aria-hidden="true"></i>
                                            </label>
                                        </div>
                                    </div>
                                    @if($errors->has('last_name'))
                                        <span class="help-block">
                                            <strong>{{ $errors->first('last_name') }}</strong>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mb-3 has-feedback row {{ $errors->has('email') ? ' has-error ' : '' }}">
                                <label for="email" class="col-md-3 control-label">{{ trans('forms.create_user_label_email') }}</label>
                                <div class="col-md-9">
                                    <div class="input-group">
                                        {{ html()->email('email', old('email', $user->email))->id('email')->class('form-control')->placeholder(trans('forms.create_user_ph_email')) }}
                                        <div class="input-group-append">
                                            <label for="email" class="input-group-text">
                                                <i class="fa fa-fw {{ trans('forms.create_user_icon_email') }}" aria-hidden="true"></i>
                                            </label>
                                        </div>
                                    </div>
                                    @if ($errors->has('email'))
                                        <span class="help-block">
                                            <strong>{{ $errors->first('email') }}</strong>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mb-3 has-feedback row {{ $errors->has('role') ? ' has-error ' : '' }}">

                                <label for="role" class="col-md-3 control-label">{{ trans('forms.create_user_label_role') }}</label>

                                <div class="col-md-9">
                                    <div class="input-group">
                                        <select class="custom-select form-control" name="role" id="role">
                                            <option value="">{{ trans('forms.create_user_ph_role') }}</option>
                                            @if ($roles)
                                                @foreach($roles as $role)
                                                    <option value="{{ $role->id }}" {{ $currentRole->id == $role->id ? 'selected="selected"' : '' }}>{{ $role->name }}</option>
                                                @endforeach
                                            @endif
                                        </select>
                                        <div class="input-group-append">
                                            <label class="input-group-text" for="role">
                                                <i class="{{ trans('forms.create_user_icon_role') }}" aria-hidden="true"></i>
                                            </label>
                                        </div>
                                    </div>
                                    @if ($errors->has('role'))
                                        <span class="help-block">
                                            <strong>{{ $errors->first('role') }}</strong>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="pw-change-container">
                                <div class="mb-3 has-feedback row {{ $errors->has('password') ? ' has-error ' : '' }}">

                                    <label for="password" class="col-md-3 control-label">{{ trans('forms.create_user_label_password') }}</label>

                                    <div class="col-md-9">
                                        <div class="input-group">
                                            {{ html()->password('password', old('password'))->id('password')->class('form-control ')->placeholder(trans('forms.create_user_ph_password'))->attribute('autocomplete', 'off') }}
                                            <div class="input-group-append">
                                                <label class="input-group-text" for="password">
                                                    <i class="fa fa-fw {{ trans('forms.create_user_icon_password') }}" aria-hidden="true"></i>
                                                </label>
                                            </div>
                                        </div>
                                        @if ($errors->has('password'))
                                            <span class="help-block">
                                                <strong>{{ $errors->first('password') }}</strong>
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                <div class="mb-3 has-feedback row {{ $errors->has('password_confirmation') ? ' has-error ' : '' }}">

                                    <label for="password_confirmation" class="col-md-3 control-label">{{ trans('forms.create_user_label_pw_confirmation') }}</label>

                                    <div class="col-md-9">
                                        <div class="input-group">
                                            {{ html()->password('password_confirmation', old('password_confirmation'))->id('password_confirmation')->class('form-control')->placeholder(trans('forms.create_user_ph_pw_confirmation'))->attribute('autocomplete', 'off') }}
                                            <div class="input-group-append">
                                                <label class="input-group-text" for="password_confirmation">
                                                    <i class="fa fa-fw {{ trans('forms.create_user_icon_pw_confirmation') }}" aria-hidden="true"></i>
                                                </label>
                                            </div>
                                        </div>
                                        @if ($errors->has('password_confirmation'))
                                            <span class="help-block">
                                                <strong>{{ $errors->first('password_confirmation') }}</strong>
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12 col-sm-6 mb-2">
                                    <a href="javascript://" class="btn btn-outline-secondary w-100 btn-change-pw mt-3" title="{{ trans('forms.change-pw')}} ">
                                        <i class="fa fa-fw fa-lock" aria-hidden="true"></i>
                                        <span></span> {!! trans('forms.change-pw') !!}
                                    </a>
                                    <input type="hidden" name="change_password" value="0" />
                                </div>
                                <div class="col-12 col-sm-6">
                                    {{ html()->button(trans('forms.save-changes'))
                                        ->id('user-save-confirm')
                                        ->class('btn btn-success w-100 margin-bottom-1 mt-3 mb-2 btn-save')
                                        ->type('button')
                                        ->attribute('data-bs-toggle', 'modal')
                                        ->attribute('data-bs-target', '#confirm-save-modal')
                                        ->attribute('data-initiator-id', 'user-save-confirm')
                                        ->attribute('data-title', trans('modals.edit_user__modal_text_confirm_title'))
                                        ->attribute('data-message', trans('modals.edit_user__modal_text_confirm_message')) }}
                                </div>
                            </div>
                        {{ html()->form()->close() }}
                    </div>

                </div>
            </div>
        </div>
    </div>

    @include('modals.modal-save')
    @include('modals.modal-delete')

@endsection

@section('footer_scripts')
    <script type="module">
    $('#user-save-confirm').ready(function()
    {
        var addInitiatorIdToConfirmSaveModal = function(eventData)
        {
            var initiatorId = $(eventData.target).attr('id');
            $('#confirm-save-modal').data('initiator-id', initiatorId);
        };

        $('#user-save-confirm').click(addInitiatorIdToConfirmSaveModal);
    });
    </script>

    @include('scripts.delete-modal-script')
    @include('scripts.save-modal-script')
    @include('scripts.check-changed')

@endsection

