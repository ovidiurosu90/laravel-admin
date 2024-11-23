<script type="module">

$(document).ready(function()
{

    var $confirmDeleteModal = $('#confirm-delete-modal');

    // $confirmDeleteModal.on('shown.bs.modal', function (e)
    document.getElementById('confirm-delete-modal').addEventListener('shown.bs.modal', (e) =>
    {
        var message = $(e.relatedTarget).attr('data-message');
        var title = $(e.relatedTarget).attr('data-title');
        // var form = $(e.relatedTarget).closest('form');
        $confirmDeleteModal.find('.modal-body p').text(message);
        $confirmDeleteModal.find('.modal-title').text(title);
        // $confirmDeleteModal.find('.modal-footer #confirm').data('form', form);

        $confirmDeleteModal.data('initiator-id', $(e.relatedTarget).attr('id'));
    });

    $confirmDeleteModal.find('.modal-footer #confirm').on('click', function()
    {
        var initiatorId = $confirmDeleteModal.data('initiator-id');
        if (!initiatorId) {
            console.error('Initiator ID is missing!');
            return;
        }

        var $initiator = $('#' + $confirmDeleteModal.data('initiator-id'));
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

