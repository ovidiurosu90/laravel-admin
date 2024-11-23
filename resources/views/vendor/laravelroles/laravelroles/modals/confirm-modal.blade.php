@php
    if (!isset($actionBtnIcon)) {
        $actionBtnIcon = null;
    } else {
        $actionBtnIcon = $actionBtnIcon . ' fa-fw';
    }
    if (!isset($modalClass)) {
        $modalClass = null;
    }
    if (!isset($btnSubmitText)) {
        $btnSubmitText = trans('laravelroles::laravelroles.modals.btnConfirm');
    }
@endphp
<div class="modal fade modal-{{ $modalClass }}" id="{{ $formTrigger }}" role="dialog" aria-labelledby="{{ $formTrigger }}-title" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header {{$modalClass}}">
                <h4 class="modal-title" id="{{ $formTrigger }}-title">
                    Confirm
                </h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>
                    Are you sure?
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline pull-left float-left" type="button" data-bs-dismiss="modal" >
                    <i class="fa fa-fw fa-close" aria-hidden="true"></i>
                    {!! trans('laravelroles::laravelroles.modals.btnCancel') !!}
                </button>
                <button class="btn btn-{{ $modalClass }} pull-right" id="confirm" type="button" >
                    <i class="fa {{  $actionBtnIcon  }}" aria-hidden="true"></i>
                    {{ $btnSubmitText }}
                </button>
            </div>
        </div>
    </div>
</div>

