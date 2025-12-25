{!! form()->open([
    'route' => ['laravelblocker::blocker.destroy', $item->id],
    'method' => 'DELETE',
    'accept-charset' => 'UTF-8',
    'data-bs-toggle' => 'tooltip',
    'title' => trans('laravelblocker::laravelblocker.tooltips.delete')
]) !!}
    {!! form()->hidden("_method", "DELETE") !!}
    {!! csrf_field() !!}
    <button class="btn btn-danger w-100 edit-form-delete" type="button" style="width: 100%;" data-bs-toggle="modal" data-bs-target="#confirmDelete" data-title="{{ trans('laravelblocker::laravelblocker.modals.delete_blocked_title') }}" data-message="{!! trans("laravelblocker::laravelblocker.modals.delete_blocked_message", ["blocked" => $item->value]) !!}">
        {!! trans("laravelblocker::laravelblocker.buttons.delete-larger") !!}
    </button>
{!! form()->close() !!}
