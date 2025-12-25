{!! form()->open([
    'route' => 'laravelblocker::blocker-deleted-restore-all',
    'method' => 'POST',
    'accept-charset' => 'UTF-8'
]) !!}
    {!! csrf_field() !!}
    {!! form()->button('
        <i class="fa fa-fw fa-history" aria-hidden="true"></i> ' . trans_choice('laravelblocker::laravelblocker.buttons.restore-all-blocked', 1, ['count' => $blocked->count()]),
        [
            'type' => 'button',
            'class' => 'btn dropdown-item',
            'data-bs-toggle' => 'modal',
            'data-bs-target' => '#confirm-restore-modal',
            'data-title' => trans('laravelblocker::laravelblocker.modals.resotreAllBlockedTitle'),
            'data-message' => trans('laravelblocker::laravelblocker.modals.resotreAllBlockedMessage')
        ]) !!}
{!! form()->close() !!}
