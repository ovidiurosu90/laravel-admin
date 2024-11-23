@if ($user->profile && $user->profile->location)

<script type="module">

async function google_maps_geocode_and_map()
{

    const { Geocoder } = await google.maps.importLibrary("geocoding");
    const { AdvancedMarkerElement, PinElement } = await google.maps.importLibrary("marker");

    var geocoder = new Geocoder();
    var address = '{{$user->profile->location}}';

    geocoder.geocode( { 'address': address}, function(results, status)
    {
        if (status == google.maps.GeocoderStatus.OK) {

            var latitude = results[0].geometry.location.lat();
            var longitude = results[0].geometry.location.lng();

            // SHOW LATITUDE AND LONGITUDE
            document.getElementById('latitude').innerHTML += latitude;
            document.getElementById('longitude').innerHTML += longitude;

            function getMap()
            {
                // Coordinates to center the map
                const position = {lat: latitude, lng: longitude}

                var mapOptions = {
                    scrollwheel: false,
                    disableDefaultUI: false,
                    draggable: false,
                    zoom: 14,
                    center: position,
                    mapTypeId: google.maps.MapTypeId.TERRAIN,
                    mapId: '{{ config("settings.googleMapsMapId") }}'
                };

                const map = new google.maps.Map(document.getElementById("map-canvas"), mapOptions);

                // MARKER
                const marker = new AdvancedMarkerElement({
                    map,
                    // icon: "",
                    position: map.getCenter(),
                    title: '<strong>{{$user->first_name}}</strong> <br />  {{$user->email}}',
                });

                // INFO WINDOW
                var infowindow = new google.maps.InfoWindow();
                infowindow.setContent('<strong>{{$user->first_name}}</strong> <br />  {{$user->email}}');

                infowindow.open(map, marker);
                google.maps.event.addListener(marker, 'click', function() {
                    infowindow.open(map, marker);
                });

            }

            // ATTACH MAP TO DOM HTML ELEMENT
            // google.maps.event.addDomListener(window, 'load', getMap);
            $('#map-canvas').ready(function() {
                getMap();
            });

        } else {
            console.error('Status not OK: ' + status);
        }
    });
}

$(document).ready(function()
{
    google_maps_geocode_and_map();
});

</script>

@endif
