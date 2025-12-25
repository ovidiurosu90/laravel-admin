@extends('layouts.app')

@section('template_title'){{ trans('titles.adminThemesAdd') }}@endsection

@section('template_fastload_css')
@endsection

@section('content')

    <div class="container">
        <div class="row">
            <div class="col-12 col-xl-10 offset-xl-1">
                <div class="card">

                    <div class="card-header">
                        <div class="float-left">
                            {{ trans('titles.adminThemesAdd') }}
                        </div>
                        <div class="float-right">
                            <a href="{{ url('/themes/') }}" class="btn btn-light btn-sm float-right" data-bs-toggle="tooltip" data-bs-placement="left" title="{{ trans('themes.backToThemesTt') }}">
                                <i class="fa fa-fw fa-mail-reply" aria-hidden="true"></i>
                                {!! trans('themes.backToThemesBtn') !!}
                            </a>
                        </div>
                    </div>


                    {{ html()->form('POST', action([App\Http\Controllers\ThemesManagementController::class, 'store']))
                            ->attribute('role', 'form')
                            ->open() }}

                        @csrf

                        <div class="card-body">

                            <div class="mb-3 has-feedback row {{ $errors->has('status') ? 'has-error' : '' }}">
                                <label for="status" class="col-md-3 control-label">{{ trans('themes.statusLabel') }}</label>
                                <div class="col-md-9">
                                    <label class="switch checked" for="status">
                                        <span class="active"><i class="fa fa-toggle-on fa-2x"></i> {{ trans('themes.statusEnabled') }}</span>
                                        <span class="inactive"><i class="fa fa-toggle-on fa-2x fa-rotate-180"></i> {{ trans('themes.statusDisabled') }}</span>
                                        <input type="radio" name="status" value="1" checked>
                                        <input type="radio" name="status" value="0">
                                    </label>

                                    @if ($errors->has('status'))
                                        <span class="help-block">
                                            <strong>{{ $errors->first('status') }}</strong>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mb-3 has-feedback row {{ $errors->has('name') ? 'has-error' : '' }}">
                                <label for="name" class="col-md-3 control-label">{{ trans('themes.nameLabel') }}</label>
                                <div class="col-md-9">
                                    <div class="input-group">
                                        {{ html()->text('name', old('name'))->id('name')->class('form-control')->placeholder(trans('themes.namePlaceholder')) }}
                                        <div class="input-group-append">
                                            <label for="name" class="input-group-text">
                                                <i class="fa fa-fw fa-pencil" aria-hidden="true"></i>
                                            </label>
                                        </div>
                                    </div>
                                    @if ($errors->has('name'))
                                        <span class="help-block">
                                            <strong>{{ $errors->first('name') }}</strong>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mb-3 has-feedback row {{ $errors->has('link') ? 'has-error' : '' }}">
                                <label for="link" class="col-md-3 control-label">{{ trans('themes.linkLabel') }}</label>
                                <div class="col-md-9">
                                    <div class="input-group">
                                        {{ html()->text('link', old('link'))->id('link')->class('form-control')->placeholder(trans('themes.linkPlaceholder')) }}
                                        <div class="input-group-append">
                                            <label for="link" class="input-group-text">
                                                <i class="fa fa-fw fa-link fa-rotate-90" aria-hidden="true"></i>
                                            </label>
                                        </div>
                                    </div>
                                    @if ($errors->has('link'))
                                        <span class="help-block">
                                            <strong>{{ $errors->first('link') }}</strong>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mb-3 has-feedback row {{ $errors->has('notes') ? 'has-error' : '' }}">
                                <label for="notes" class="col-md-3 control-label">{{ trans('themes.notesLabel') }}</label>
                                <div class="col-md-9">
                                    <div class="input-group">
                                        {{ html()->textarea('notes', old('notes'))->id('notes')->class('form-control')->placeholder(trans('themes.notesPlaceholder')) }}
                                        <div class="input-group-append">
                                            <label for="notes" class="input-group-text">
                                                <i class="fa fa-fw fa-pencil" aria-hidden="true"></i>
                                            </label>
                                        </div>
                                    </div>
                                    @if ($errors->has('notes'))
                                        <span class="help-block">
                                            <strong>{{ $errors->first('notes') }}</strong>
                                        </span>
                                    @endif
                                </div>
                            </div>

                        </div>

                        <div class="card-footer">
                            <div class="row">
                                <div class="col-sm-6 offset-sm-6">
                                    {{ html()->button('<i class="fa fa-plus" aria-hidden="true"></i>&nbsp;' . trans('themes.btnAddTheme'))
                                        ->class('btn btn-success w-100 mb-0')
                                        ->type('submit') }}
                                </div>
                            </div>
                        </div>

                    {{ html()->form()->close() }}

                </div>
            </div>
        </div>
    </div>

@endsection

@section('footer_scripts')

  @include('scripts.toggleStatus')

@endsection
