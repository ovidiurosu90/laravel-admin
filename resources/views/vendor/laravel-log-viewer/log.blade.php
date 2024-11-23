@extends('layouts.app')

@section('template_title'){{ 'Log Information' }}@endsection

@section('template_linked_css')

  <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.16/css/dataTables.bootstrap4.min.css">

@endsection

@section('content')

  <div class="container-fluid logs-container">
    <div class="row">

      <div class="col-sm-3 col-md-2 sidebar">
        <h4><span class="fa fa-fw fa-file-code-o" aria-hidden="true"></span> Log Files</h4>
        <div class="list-group">
          @foreach($files as $file)
            <a href="logs?l={{ \Illuminate\Support\Facades\Crypt::encrypt($file) }}" class="list-group-item @if ($current_file == $file) llv-active @endif">
              {{$file}}
              @if ($current_file == $file)
                <span class="badge pull-right">
                  {{ count($logs) }}
                </span>
              @endif
            </a>
          @endforeach
        </div>
      </div>

      <div class="col-sm-9 col-md-10 table-container">
        @if ($logs === null)
          <div>
            Log file >50M, please download it.
          </div>
        @else
        <table id="table-log" class="table table-sm table-striped">
          <thead>
            <tr>
              <th>Level</th>
              <th>Context</th>
              <th>Date</th>
              <th>Content</th>
            </tr>
          </thead>
          <tbody>
            @foreach($logs as $key => $log)
            <tr>
              <td class="text-{{{$log['level_class']}}}"><span class="glyphicon glyphicon-{{{$log['level_img']}}}-sign" aria-hidden="true"></span> &nbsp;{{$log['level']}}</td>
              <td class="text">{{$log['context']}}</td>
              <td class="date">{{{$log['date']}}}</td>
              <td class="text">
                @if ($log['stack']) <a class="pull-right expand btn btn-default btn-xs" data-bs-display="stack{{{$key}}}"><span class="glyphicon glyphicon-search"></span></a>@endif
                {{{$log['text']}}}
                @if (isset($log['in_file'])) <br />{{{$log['in_file']}}}@endif
                @if ($log['stack']) <div class="stack" id="stack{{{$key}}}" style="display: none; white-space: pre-wrap;">{{{ trim($log['stack']) }}}</div>@endif
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
        @endif
        <div>
          @if($current_file)
            <a href="logs?dl={{ \Illuminate\Support\Facades\Crypt::encrypt($current_file) }}" class="btn btn-link">
              <i class="fa fa-download" aria-hidden="true"></i>
              Download file
            </a>
            -
            <a id="log-delete-confirm" data-bs-toggle="modal" data-bs-target="#confirm-delete-modal" data-href="logs?del={{ \Illuminate\Support\Facades\Crypt::encrypt($current_file) }}" data-initiator-id="log-delete-confirm" data-title="Delete Log File" data-message="Are you sure you want to delete log file?" class="btn btn-link">
              <i class="fa fa-trash-o" aria-hidden="true"></i>
              Delete file
            </a>
            @if(count($files) > 1)
              -

              <a id="all-logs-delete-confirm" data-bs-toggle="modal" data-bs-target="#confirm-delete-modal" data-href="logs?delall=true" data-initiator-id="all-logs-delete-confirm" data-title="Delete All Log Files" data-message="Are you sure you want to delete all log files?" class="btn btn-link">
                <i class="fa fa-trash" aria-hidden="true"></i>
                Delete all files
              </a>

            @endif
          @endif
        </div>
      </div>

    </div>
  </div>

  @include('modals.modal-delete')

@endsection

@section('footer_scripts')
    @include('scripts.delete-modal-script')

    {{-- @include('scripts.datatables') --}}

    {{-- <!-- Commented out as the resources were added in bootstrap.js -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap4.min.css">
    <script type="text/javascript" src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.13.2/js/dataTables.bootstrap4.min.js"></script>
    --}}

    <script type="module">
    $(document).ready(function()
    {
        // $.noConflict();
        var table = $('#table-log').DataTable({
            "order": [ 1, 'desc' ],
            "stateSave": true,
            "stateSaveCallback": function (settings, data) {
                window.localStorage.setItem("datatable", JSON.stringify(data));
            },
            "stateLoadCallback": function (settings) {
                var data = JSON.parse(window.localStorage.getItem("datatable"));
                if (data) data.start = 0;
                return data;
            }
        });

        $('.table-container').on('click', '.expand', function()
        {
            $('#' + $(this).data('display')).toggle();
        });
    });
    </script>
@endsection

