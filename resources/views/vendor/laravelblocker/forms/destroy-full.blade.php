{{ html()->form('POST', route('laravelblocker::blocker-item-destroy', $item->id))
    ->attribute('accept-charset', 'UTF-8')
    ->attribute('data-bs-toggle', 'tooltip')
    ->attribute('title', trans('laravelblocker::laravelblocker.tooltips.'
                               . 'destroy_blocked_tooltip'))
    ->open() }}
    @csrf
    @method('DELETE')
    <button class="btn btn-danger btn-block edit-form-destroy" type="button"
        style="width: 100%;" data-bs-toggle="modal"
        data-bs-target="#confirm-delete-modal"
        data-title="{{ trans('laravelblocker::laravelblocker.modals.'
                             . 'destroy_blocked_title') }}"
        data-message="{!! trans('laravelblocker::laravelblocker.modals.'
                            . 'destroy_blocked_message', ['blocked' => $item->value]) !!}">
        {!! trans('laravelblocker::laravelblocker.buttons.destroy-larger') !!}
    </button>
{{ html()->form()->close() }}

