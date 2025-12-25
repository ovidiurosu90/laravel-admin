<div class="mb-3 has-feedback row">
    <label for="note" class="col-md-3 control-label">{{ trans('laravelblocker::laravelblocker.forms.blockedNoteLabel') }}</label>
    <div class="col-md-9">
        <div class="input-group">
            @isset($item)
                {!! form()->textarea('note', $item->note, array('id' => 'note', 'class' => $errors->has('note') ? 'form-control is-invalid ' : 'form-control', 'placeholder' => trans('laravelblocker::laravelblocker.forms.blockedNotePH'))) !!}
            @else
                {!! form()->textarea('note', NULL, array('id' => 'note', 'class' => $errors->has('note') ? 'form-control is-invalid ' : 'form-control', 'placeholder' => trans('laravelblocker::laravelblocker.forms.blockedNotePH'))) !!}
            @endisset
            <div class="input-group-append">
                <label class="input-group-text" for="note">
                    <i class="fa fa-fw fa-pencil" aria-hidden="true"></i>
                </label>
            </div>
        </div>
        @if ($errors->has('note'))
            <span class="help-block">
                <strong>{{ $errors->first('note') }}</strong>
            </span>
        @endif
    </div>
</div>
