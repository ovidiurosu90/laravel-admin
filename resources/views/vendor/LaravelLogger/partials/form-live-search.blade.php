    <div class="row mb-3">
      <div class="col-12 col-sm-4 col-lg-6 mb-2">
        <label for="live_search_email" class="col-form-label">
          User Live Search <small>(use the search button before selecting the dropdown to search for a specific user)</small>
        </label>
      </div>
      <div class="col-12 col-sm-4 col-lg-2 mb-2">
        <input type="text" id='live_search_email' name='live_search_email' class="form-control" placeholder='Email'>
      </div>
      <div class="col-12 col-sm-4 col-lg-2 mb-2">
        <input type="text" id='live_search_userid' name='live_search_userid' class="form-control" placeholder='UserId'>
      </div>
      <div class="col-12 col-sm-4 col-lg-2 mb-2">
        <input id="live_search_button" class="btn btn-primary btn-block" value="{{ trans('LaravelLogger::laravel-logger.dashboard.search.search') }}">
      </div>
    </div>
