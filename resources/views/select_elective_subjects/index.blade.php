@extends('layouts.master')

@section('title')
    {{ __('elective_subjects') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('manage') . ' ' . __('elective_subjects') }}
            </h3>
        </div>

        <div class="row">
            <div class="col-lg-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('list') . ' ' . __('students') }}
                        </h4>

                        <div id="toolbar">
                            <div class="row">
                                <div class="col">
                                    <label>{{ __('class') }}</label>
                                    <select name="filter_class_section_id" id="filter_class_section_id"
                                        class="form-control">
                                        @foreach ($classes as $class)
                                            <option value="{{ $class->id }}" {{ $loop->first ? 'selected' : '' }}>
                                                {{ $class->name . ' ' . $class->medium->name . ' ' . ($class->streams->name ?? '') }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col">
                                    <label>{{ __('elective') }} {{ __('subject') }}</label>
                                    <select name="filter_elective_subject" id="filter_elective_subject" class="form-control"
                                        style="width:100%;" tabindex="-1" aria-hidden="true">
                                        <option value="">{{ __('all') }}</option>
                                    </select>
                                </div>

                                <div class="col">
                                    <label>{{ __('status') }}</label>
                                    <select name="filter_status" id="filter_status" class="form-control">
                                        <option value="">{{ __('all') }}</option>
                                        <option value="not_assigned">{{ __('not_assigned') }}</option>
                                        <option value="incomplete">{{ __('incomplete') }}</option>
                                        <option value="complete">{{ __('complete') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="">
                            <table aria-describedby="mydesc" class='table table-responsive' id='table_list'
                                data-click-to-select="false" data-unique-id="id" data-toggle="table"
                                data-url="{{ url('students-subject-data') }}" data-click-to-select="true"
                                data-side-pagination="server" data-pagination="true"
                                data-page-list="[5, 10, 20, 50, 100, 200, 500]" data-search="true" data-toolbar="#toolbar"
                                data-show-columns="true" data-show-refresh="true" data-fixed-columns="true"
                                data-trim-on-search="false" data-mobile-responsive="true" data-sort-name="id"
                                data-sort-order="desc" data-maintain-selected="true" data-export-types='["txt","excel"]'
                                data-export-options='{ "fileName": "students-list-<?= date('d-m-y') ?>" ,"ignoreColumn":
                                ["operate"]}'
                                data-query-params="studentDetailsqueryParams"
                                data-check-on-init="true" data-escape="true">

                                <thead>
                                    <tr>
                                        <th data-field="state" data-checkbox="true" data-width="5%" data-width-unit="%">
                                        </th>
                                        <th scope="col" data-field="full_name" data-width="20%" data-width-unit="%"
                                            data-formatter="studentNameFormatter" data-escape="false">
                                            {{ __('students') }}
                                        </th>

                                        <th scope="col" data-field="status_badge" data-width="10%" data-width-unit="%"
                                            data-escape="false">{{ __('status') }}</th>
                                        <th scope="col" data-field="selected_subjects" data-width="70%"
                                            data-width-unit="%">{{ __('selected_subjects') }}</th>
                                        <th data-escape="false" data-events="studentEvents" data-width="40%"
                                            data-width-unit="%" scope="col" data-field="operate" data-sortable="false">
                                            {{ __('action') }}</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Elective Modal -->
    <div class="modal fade" id="assignModal" tabindex="-1" role="dialog" aria-labelledby="assignModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content elective-modal-content">

                <div class="modal-header elective-modal-header">
                    <div class="elective-header-text">
                        <h5 class="modal-title elective-modal-title" id="assignModalLabel">
                            {{ __('assign_elective_subjects') }}
                        </h5>
                        <small id="studentDetails" class="elective-student-details"></small>
                    </div>
                    <button type="button" class="close elective-close-btn" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <form id="assignForm" method="POST" action="{{ url('assign-elective-subjects') }}">
                    @csrf
                    <div class="modal-body elective-modal-body">
                        <input type="hidden" name="student_id" id="assign_student_id">

                        <div class="form-group elective-form-group">
                            <label for="elective_group" class="elective-form-label">
                                {{ __('select_elective_group') }}
                            </label>
                            <div class="elective-select-wrapper">
                                <select name="elective_group" id="elective_group" class="form-control elective-select">
                                </select>
                            </div>
                        </div>

                        <div id="selectInfo" class="elective-info-box" style="display:none;">
                            <i class="mdi mdi-information-outline elective-info-icon"></i>
                            <span>You must select <strong id="selectCount"></strong> subjects.</span>
                        </div>

                        <div class="elective-subjects-label">{{ __('available_subjects') }}</div>
                        <div id="subjectsContainer" class="elective-subjects-container"></div>
                    </div>

                    <div class="modal-footer elective-modal-footer">
                        <button type="button" class="btn elective-btn-cancel" data-dismiss="modal">
                            {{ __('cancel') }}
                        </button>
                        <button type="submit" id="saveAssignmentBtn" class="btn elective-btn-save" disabled>
                            {{ __('save_assignment') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bottom Selection Bar -->
    <div id="bottomSelectionBar" class="bottom-selection-bar" style="display: none;">
        <div class="selection-bar-container">
            <div class="selection-bar-content">
                <div class="selection-info">
                    <div class="selected-avatars" id="selectedAvatars">
                        <!-- Avatars will be dynamically added here -->
                    </div>
                    <span class="selection-count" id="selectionCount">0 Students selected</span>
                </div>
                <div class="selection-actions">
                    <button type="button" class="btn btn-assign" id="bulkAssignBtn">
                        Assign
                    </button>
                    <button type="button" class="btn btn-clear" id="clearSelectionBtn">
                        <i class="mdi mdi-close"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection
