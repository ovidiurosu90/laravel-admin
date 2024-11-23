@if(Session::has('message'))
    <div class="alert alert-{{ Session::get('status') }} status-box">
        <div>{{ Session::get('message') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

