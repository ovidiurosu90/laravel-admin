<script type="module">
window.numbersAndLettersOnly = function()
{
    var ek = event.keyCode;
    // console.log(ek);
    if (48 <= ek && ek <= 57) { // 0 - 9
        return true;
    }
    if(65 <= ek && ek <= 90) { // A - Z
        return true;
    }
    if(97 <= ek && ek <= 122) { // a - z
        return true;
    }
    if (ek == 46) { // period '.'
        return true;
    }
    return false;
};

$(document).ready(function()
{
    // Normalize permission JSON values: replace " with ' to avoid console errors,
    // then restore " before form submission

    // Handle both edit and store role forms
    ['edit_role_form', 'store_role_form'].forEach(formId =>
    {
        var myform = document.getElementById(formId);
        if (!myform) {
            return;
        }
        myform.onsubmit = function(e)
        {
            var $permissions = $('#permissions');
            const selectElement = $permissions[0];

            // Get current selected values from the actual select element
            const values = Array.from(selectElement.selectedOptions).map(
                opt => opt.value);

            if (values && values.length > 0) {
                // Replace single quotes with double quotes in each value
                const newValues = values.map(value => value.replace(/\'/g, "\""));

                // Remove any existing hidden permission inputs
                $('input[name="permissions[]"]').remove();

                // Create hidden inputs with the corrected values
                newValues.forEach(value => {
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'permissions[]';
                    hiddenInput.value = value;
                    myform.appendChild(hiddenInput);
                });

                // Disable the original select element so it won't be submitted
                selectElement.disabled = true;
            }

            return true;
        };
    });
});
</script>

