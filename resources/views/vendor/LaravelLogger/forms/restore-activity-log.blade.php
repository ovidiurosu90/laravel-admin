<form action="{{ route('restore-activity') }}" method="POST" class="mb-0">
    @csrf
    <button id="restore-activity-log" type="button" class="text-success dropdown-item" data-bs-toggle="modal" data-bs-target="#confirm-restore-modal" data-initiator-id="restore-activity-log" data-title="{{ trans('LaravelLogger::laravel-logger.modals.restoreLog.title') }}" data-message="{{ trans('LaravelLogger::laravel-logger.modals.restoreLog.message') }}">
        <i class="fa fa-fw fa-history" aria-hidden="true"></i>{{ trans('LaravelLogger::laravel-logger.dashboardCleared.menu.restoreAll') }}
    </button>
</form>

