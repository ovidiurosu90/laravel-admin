<script type="module">

$(document).ready(function()
{

    var $confirmRestoreModal = $('#confirm-restore-modal');

    // $confirmRestoreModal.on('shown.bs.modal', function (e)
    document.getElementById('confirm-restore-modal').addEventListener('shown.bs.modal', (e) =>
    {
        var message = $(e.relatedTarget).attr('data-message');
        var title = $(e.relatedTarget).attr('data-title');
        // var form = $(e.relatedTarget).closest('form');
        $confirmRestoreModal.find('.modal-body p').text(message);
        $confirmRestoreModal.find('.modal-title').text(title);
        // $confirmRestoreModal.find('.modal-footer #confirm').data('form', form);

        $confirmRestoreModal.data('initiator-id', $(e.relatedTarget).attr('id'));
    });

    $confirmRestoreModal.find('.modal-footer #confirm').on('click', function()
    {
        var initiatorId = $confirmRestoreModal.data('initiator-id');
        if (!initiatorId) {
            console.error('Initiator ID is missing!');
            return;
        }

        var $initiator = $('#' + $confirmRestoreModal.data('initiator-id'));
        var initiatorType = $initiator.prop('nodeName');

        if (initiatorType.toLowerCase() == 'button') {
            var $parentForm = $initiator.closest('form');
            if (!$parentForm) {
                console.error('Parent Form not found!');
                return;
            }
            $parentForm.submit();
        } else if (initiatorType.toLowerCase() == 'a') {
            window.location = $initiator.data('href');
        } else {
            console.error('Unexpected initiator type: ' + initiatorType);
        }
    });

});

</script>

