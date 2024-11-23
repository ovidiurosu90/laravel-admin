<script type="module">
//FUNCTION TO ASSIST WITH AUTO ADDRESS INPUT USING GOOGLE MAPS API3
//<![CDATA[
async function myAutocomplete()
{
    await google.maps.importLibrary("places");
    var input = document.getElementById('location');
    var options; // = {componentRestrictions: {country: 'us'}};
    var autocomplete = new google.maps.places.Autocomplete(input, options);
}
$('#location').ready(function()
{
    myAutocomplete();
});
//]]>
</script>

