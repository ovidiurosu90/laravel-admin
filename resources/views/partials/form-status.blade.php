@if (session('message'))
  <div class="alert alert-{{ Session::get('status') }} status-box alert-dismissible fade show" role="alert">
    <div>{{ session('message') }}</div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
@endif

@if (session('success'))
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <h4><i class="icon fa fa-check fa-fw" aria-hidden="true"></i> Success</h4>
    <div>{{ session('success') }}</div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
@endif

@if(session()->has('status'))
    @if(session()->get('status') == 'wrong')
        <div class="alert alert-danger status-box alert-dismissible fade show" role="alert">
            <div>{{ session('message') }}</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
@endif

@if (session('error'))
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <h4><i class="icon fa fa-warning fa-fw" aria-hidden="true"></i> Error</h4>
    <div>{{ session('error') }}</div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
@endif

@if (session('errors') && count($errors) > 0)
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <h4>
      <i class="icon fa fa-warning fa-fw" aria-hidden="true"></i>
      <strong>{{ Lang::get('auth.whoops') }}</strong> {{ Lang::get('auth.someProblems') }}
    </h4>
    <ul>
      @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
      @endforeach
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
@endif

