<div class="modal fade" id="confirm-form-modal" role="dialog" aria-labelledby="confirm-form-modal-title" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title" id="confirm-form-modal-title">
          {{ trans('modals.form_modal_default_title') }}
        </h4>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>{{ trans('modals.form_modal_default_message') }}</p>
      </div>
      <div class="modal-footer">
        {!! Form::button('<i class="fa fa-fw fa-close" aria-hidden="true"></i> ' . trans('modals.form_modal_default_btn_cancel'), array('class' => 'btn btn-secondary', 'type' => 'button', 'data-bs-dismiss' => 'modal' )) !!}
        {!! Form::button('<i class="fa fa-fw fa-check" aria-hidden="true"></i> ' . trans('modals.form_modal_default_btn_submit'), array('class' => 'btn btn-primary', 'type' => 'button', 'id' => 'confirm' )) !!}
      </div>
    </div>
  </div>
</div>
