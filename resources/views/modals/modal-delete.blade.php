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
        {{ html()->button('<i class="fa fa-fw fa-close" aria-hidden="true"></i> Cancel')
            ->class('btn btn-outline pull-left btn-light')
            ->type('button')
            ->attribute('data-bs-dismiss', 'modal') }}
        {{ html()->button('<i class="fa fa-fw fa-trash-o" aria-hidden="true"></i> Confirm Delete')
            ->class('btn btn-danger pull-right')
            ->type('button')
            ->id('confirm') }}
      </div>
    </div>
  </div>
</div>
