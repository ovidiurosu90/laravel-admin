<script type="module">

$(document).ready(function(){

var $confirmFormModal = $('#confirm-form-modal');

// $confirmFormModal.on('shown.bs.modal', function (e)
document.getElementById('confirm-form-modal').addEventListener('shown.bs.modal', (e) =>
{
    var modalClass = $(e.relatedTarget).attr('data-modalClass') || '';
    var submitText = $(e.relatedTarget).attr('data-submit');
    var message = $(e.relatedTarget).attr('data-message');
    var title = $(e.relatedTarget).attr('data-title');
    // var form = $(e.relatedTarget).closest('form');

    $('#confirm-form-modal').alterClass('modal-*', modalClass);
    $confirmFormModal.find('.modal-body p').text(message);
    $confirmFormModal.find('.modal-title').text(title);

    $confirmFormModal.find('.modal-footer #confirm')
        .text(submitText);
        // .data('form', form);

    $confirmFormModal.data('initiator-id', $(e.relatedTarget).attr('id'));
});

$confirmFormModal.find('.modal-footer #confirm').on('click', function()
{
    var initiatorId = $confirmFormModal.data('initiator-id');
    if (!initiatorId) {
        console.error('Initiator ID is missing!');
        return;
    }

    var $parentForm = $('#' + $confirmFormModal.data('initiator-id')).closest('form');
    if (!$parentForm) {
        console.error('Parent Form not found!');
        return;
    }
    $parentForm.submit();
});

});

</script>

