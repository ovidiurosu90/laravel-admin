<form action="{{ route('clear-activity') }}" method="POST">
    @csrf
    @method('DELETE')
    <button id="clear-activity-log" type="button" data-bs-toggle="modal" data-bs-target="#confirm-delete-modal" data-initiator-id="clear-activity-log"  data-title="{{ trans('LaravelLogger::laravel-logger.modals.clearLog.title') }}" data-message="{{ trans('LaravelLogger::laravel-logger.modals.clearLog.message') }}" class="dropdown-item">
        <i class="fa fa-fw fa-trash" aria-hidden="true"></i>{{ trans('LaravelLogger::laravel-logger.dashboard.menu.clear') }}
    </button>
</form>

