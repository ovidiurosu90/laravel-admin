<script type="module">
$(function()
{
    $(".clickable-row").click(function()
    {
        window.location = $(this).data("href");
    });
});
</script>

