<script type="module">
$(document).ready(function() {
    checkChanged(); // Initialize button state on page load
});

$('.btn-change-pw').click(function(event)
{
    var $changePassword = $('input[name="change_password"]');
    console.log($changePassword.val());
    if ($changePassword.val() == '1') {
        $changePassword.val('0');
    } else {
        $changePassword.val('1');
    }

    var pwInput = $('#password');
    var pwInputConf = $('#password_confirmation');
    pwInput.val('').prop('disabled', true);
    pwInputConf.val('').prop('disabled', true);
    $('.pw-change-container').slideToggle(100, function() {
        pwInput.prop('disabled', function () {
             return ! pwInput.prop('disabled');
        });
        pwInputConf.prop('disabled', function () {
             return ! pwInputConf.prop('disabled');
        });
    });
});
$("input").keyup(function()
{
    checkChanged();
});
$("select").change(function()
{
    checkChanged();
});
function checkChanged()
{
    var saveBtn = $(".btn-save");
    // Check if any non-hidden input field has a value
    var hasValue = false;
    $('input:not([type="hidden"])').each(function() {
        if($(this).val()) {
            hasValue = true;
            return false; // break early
        }
    });

    if(hasValue) {
        saveBtn.show();
    }
    else {
        saveBtn.hide();
    }
}
</script>

