<div class="modal fade modal-success modal-save" id="confirm-save-modal" role="dialog" aria-labelledby="confirm-save-modal-title" aria-hidden="true" tabindex="-1">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="confirm-save-modal-title">
                    {!! trans('modals.edit_user__modal_text_confirm_title') !!}
                </h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>{!! trans('modals.confirm_modal_title_text') !!}</p>
            </div>
            <div class="modal-footer">
                {{ html()->button('<i class="fa fa-fw '.trans('modals.confirm_modal_button_cancel_icon').'" aria-hidden="true"></i> ' . trans('modals.confirm_modal_button_cancel_text'))
                    ->class('btn btn-outline pull-left btn-flat')
                    ->type('button')
                    ->attribute('data-bs-dismiss', 'modal') }}
                {{ html()->button('<i class="fa fa-fw '.trans('modals.confirm_modal_button_save_icon').'" aria-hidden="true"></i> ' . trans('modals.confirm_modal_button_save_text'))
                    ->class('btn btn-success pull-right btn-flat')
                    ->type('button')
                    ->id('confirm') }}
            </div>
        </div>
    </div>
</div>
