<div class="modal fade modal-danger" id="confirm-delete-modal" role="dialog" aria-labelledby="confirm-delete-modal-title" aria-hidden="true" tabindex="-1">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title" id="confirm-delete-modal-title">
          Confirm Delete
        </h4>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Delete this user?</p>
      </div>
      <div class="modal-footer">
        {!! Form::button('<i class="fa fa-fw fa-close" aria-hidden="true"></i> Cancel', array('class' => 'btn btn-outline pull-left btn-light', 'type' => 'button', 'data-bs-dismiss' => 'modal' )) !!}
        {!! Form::button('<i class="fa fa-fw fa-trash-o" aria-hidden="true"></i> Confirm Delete', array('class' => 'btn btn-danger pull-right', 'type' => 'button', 'id' => 'confirm' )) !!}
      </div>
    </div>
  </div>
</div>
