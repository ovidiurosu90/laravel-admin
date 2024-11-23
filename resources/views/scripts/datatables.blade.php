{{-- <!-- Commented out as the resources were added in bootstrap.js -->
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap4.min.css">
<script type="text/javascript" src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/1.13.2/js/dataTables.bootstrap4.min.js"></script>
--}}

{{-- FYI: Datatables do not support colspan or rowpan --}}
<script type="module">
$(document).ready(function()
{
    // $.noConflict();
    var dt = $('.data-table').dataTable({
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": true,
        "dom": 'T<"clear">lfrtip',
        "sPaginationType": "full_numbers",
        'aoColumnDefs': [{
            'bSortable': false,
            'searchable': false,
            'aTargets': ['no-search'],
            'bTargets': ['no-sort']
        }]
    });
});
</script>

