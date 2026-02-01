@extends('layouts.master')

@section('title')
    {{ __('attendance') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{  __('attendance_report')  }}
            </h3>
        </div>
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('view').' '. __('attendance_report') }}
                        </h4>
                        <div class="row" id="toolbar">
                            <div class="form-group col-sm-12 col-md-6">
                                <label for="" class="filter-menu">{{ __('session_year') }}</label>
                                <select name="session_year_id" class="form-control" id="filter_session_year_id">
                                    @foreach ($sessionYears as $sessionYear)
                                    <option value="{{ $sessionYear->id }}" {{ $sessionYear->id == $currentSessionYearId ? 'selected' : '' }}>
                                        {{ $sessionYear->name }}
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('class') }} {{ __('section') }} <span class="text-danger">*</span></label>
                                <select required name="class_section_id" id="filter_class_section_id" class="form-control select2" style="width:100%;" tabindex="-1" aria-hidden="true">
                                    <option value="">{{__('select')}}</option>
                                    @foreach($class_sections as $section)
                                        <option value="{{$section->id}}" data-class="{{$section->class->id}}">{{$section->class->name}} - {{$section->section->name}} {{$section->class->medium->name}} {{$section->class->streams->name ?? ''}}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="show_attendance_student_list">
                            <table aria-describedby="mydesc" class='table student_table' id='table_list'
                                   data-toggle="table" data-url="{{ url('student-attendance-report') }}" data-click-to-select="true"
                                   data-side-pagination="server" data-pagination="true"
                                   data-page-list="[5, 10, 20, 50, 100, 200,All]" data-search="true" data-toolbar="#toolbar"
                                   data-show-columns="true" data-show-refresh="true" data-fixed-columns="true"
                                   data-trim-on-search="false"
                                   data-mobile-responsive="true" data-sort-name="id" data-sort-order="desc"
                                   data-maintain-selected="true" data-export-types='["txt","excel"]' data-show-export="true"
                                   data-export-options='{ "fileName": "view-attendance-list-<?= date('d-m-y') ?>" ,"ignoreColumn": ["operate"]}'
                                   data-query-params="queryParams" data-escape="true">
                                <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false">{{__('id')}}</th>
                                    <th scope="col" data-field="no" data-sortable="false">{{__('no.')}}</th>
                                    <th scope="col" data-field="user_id" data-sortable="true" data-visible="false">{{__('user_id')}}</th>
                                    <th scope="col" data-field="student_id" data-escape="false"  data-sortable="true" data-visible="true">{{__('student_id')}}</th>
                                    <th scope="col" data-field="admission_no" data-sortable="true">{{__('admission_no')}}</th>
                                    <th scope="col" data-field="roll_no" data-sortable="true">{{__('roll_no')}}</th>
                                    <th scope="col" data-field="name" data-sortable="false">{{__('name')}}</th>
                                    <th scope="col" data-field="total_days" data-sortable="false">{{__('total_days')}}</th>
                                    <th scope="col" data-field="present_days" data-sortable="false">{{__('present_days')}}</th>
                                    <th scope="col" data-field="absent_days" data-sortable="false">{{__('absent_days')}}</th>
                                    <th scope="col" data-field="percentage" data-sortable="false">{{__('percentage')}}</th>
                                </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        function queryParams(p) {
            return {
                limit: p.limit,
                sort: p.sort,
                order: p.order,
                offset: p.offset,
                search: p.search,
                'session_year_id': $('#filter_session_year_id').val(),
                'class_section_id': $('#filter_class_section_id').val(),
            };
        }
    </script>
@endsection
