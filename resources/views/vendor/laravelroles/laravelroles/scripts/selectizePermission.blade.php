<script type="module">
$(document).ready(function ()
{
    $("#model").selectize({
        placeholder: ' {{ trans("laravelroles::laravelroles.forms.permissions-form.permission-model.placeholder") }} ',
        allowClear: true,
        create: true,
        highlight: true,
        diacritics: true
    });
});
</script>

