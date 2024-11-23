<script type="module">

$(document).ready(function()
{
    var $confirmSaveModal = $('#confirm-save-modal');

    // $confirmSaveModal.on('shown.bs.modal', function (e)
    document.getElementById('confirm-save-modal').addEventListener('shown.bs.modal', (e) =>
    {
        var message = $(e.relatedTarget).attr('data-message');
        var title = $(e.relatedTarget).attr('data-title');
        // var form = $(e.relatedTarget).closest('form');
        $confirmSaveModal.find('.modal-body p').text(message);
        $confirmSaveModal.find('.modal-title').text(title);
        // $confirmSaveModal.find('.modal-footer #confirm').data('form', form);

        $confirmSaveModal.data('initiator-id', $(e.relatedTarget).attr('id'));
    });

    $confirmSaveModal.find('.modal-footer #confirm').on('click', function()
    {
        var initiatorId = $confirmSaveModal.data('initiator-id');
        if (!initiatorId) {
            console.error('Initiator ID is missing!');
            return;
        }

        var $parentForm = $('#' + $confirmSaveModal.data('initiator-id')).closest('form');
        if (!$parentForm) {
            console.error('Parent Form not found!');
            return;
        }
        $parentForm.submit();
    });

});

</script>

