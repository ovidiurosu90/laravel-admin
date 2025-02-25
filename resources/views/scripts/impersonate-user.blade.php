<script type="module">
$(document).ready(function ()
{
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    var $impersonateUserSelect = $("#impersonate-user-select").selectize({
        placeholder: '{{ trans('auth.impersonate-user.placeholder') }}',
        create: false,
        closeAfterSelect: true,
        maxItems: 1,
        valueField: 'id',
        labelField: 'name',
        searchField: 'name',
        options: [],
        preload: false,
        onFocus: function()
        {
            $.ajax({
                url:  "{{ url('/api/get-users-to-impersonate') }}",
                type: 'GET',
                error: function (jqXHR, exception)
                {
                    var errorMessage = jqXHR.responseJSON.message
                        ? jqXHR.responseJSON.message : '';
                    if (!errorMessage && jqXHR.responseJSON.error) {
                        errorMessage = jqXHR.responseJSON.error
                    }
                    console.error('Error while trying to get users to impersonate: '
                        + errorMessage);
                    $("#nav-impersonate-user .selectize-control")
                        .addClass('is-invalid');
                },
                success: function (response)
                {
                    // console.log(response);
                    $("#nav-impersonate-user .selectize-control")
                        .removeClass('is-invalid');

                    if (!response || response.constructor !== Array) {
                        console.error('No users to impersonate!');
                        $("#nav-impersonate-user .selectize-control")
                            .addClass('is-invalid');
                        return;
                    }

                    var impersonateUserSelectize = $impersonateUserSelect[0]
                        .selectize;
                    for (const user of response) {
                        impersonateUserSelectize.addOption(user);
                    }
                    impersonateUserSelectize.refreshOptions();
                }
            });
        },
        onChange: function()
        {
            var impersonateUserSelectize = $impersonateUserSelect[0].selectize;
            var userIdToImpersonate = impersonateUserSelectize.getValue();
            if (!userIdToImpersonate) {
                return;
            }

            var impersonateHref = "{{ url('/impersonate/take') }}"
                + "/" + userIdToImpersonate + "/";

            @impersonating
            impersonateHref = "{{ url('/impersonate/leave-and-take') }}"
                + "/" + userIdToImpersonate + "/";
            @endImpersonating

            window.location.href = impersonateHref;
        },
    });


    // Prevents menu from closing when clicked inside
    document.getElementById("nav-impersonate-user")
        .addEventListener('click', function (event)
        {
            event.stopPropagation();
        });

    @impersonating
    $('#navbar-right').prepend($('\
    <li class="navbar-brand">\
        {{ trans('auth.impersonate-user.impersonating') }}\
        <a href="{{ route('impersonate.leave') }}"\
            class="badge bg-danger link-light">Leave</a>\
    </li>'));
    $('.navbar-laravel').addClass('border-bottom border-danger');
    @endImpersonating

});
</script>

