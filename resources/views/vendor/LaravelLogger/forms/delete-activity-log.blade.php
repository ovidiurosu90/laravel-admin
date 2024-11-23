<form action="{{ route('destroy-activity') }}" method="POST" class="mb-0">
    @csrf
    @method('DELETE')
    <button id="delete-activity-log" type="button" class="text-danger dropdown-item" data-bs-toggle="modal" data-bs-target="#confirm-delete-modal" data-initiator-id="delete-activity-log" data-title="{{ trans('LaravelLogger::laravel-logger.modals.deleteLog.title') }}" data-message="{{ trans('LaravelLogger::laravel-logger.modals.deleteLog.message') }}">
        <i class="fa fa-fw fa-eraser" aria-hidden="true"></i>{{ trans('LaravelLogger::laravel-logger.dashboardCleared.menu.deleteAll') }}
    </button>
</form>

