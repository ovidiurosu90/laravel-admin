<script type="module">
$(document).ready(function ()
{
    $("#permissions").selectize({
        placeholder: ' {{ trans("laravelroles::laravelroles.forms.roles-form.role-permissions.placeholder") }} ',
        allowClear: true,
        create: false,
        highlight: true,
        diacritics: true
    });
});
</script>

