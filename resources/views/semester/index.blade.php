@extends('layouts.master')

@section('title')
    {{ __('semester') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('manage_semester') }}
            </h3>
        </div>
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('create') . ' ' . __('new') . ' ' . __('semester') }}
                        </h4>
                        <form class="pt-3 section-create-form" id="create-form" action="{{ route('semester.store') }}"
                            method="POST" novalidate="novalidate">
                            <div class="row">
                                <div class="form-group col-sm-6 col-md-4">
                                    <label>{{ __('name') }} <span class="text-danger">*</span></label>
                                    <input name="name" type="text" placeholder="{{ __('name') }}"
                                        class="form-control" required />
                                </div>
                                <div class="form-group col-sm-6 col-md-4">
                                    <label>{{ __('start_date') }} <span class="text-danger">*</span></label>
                                    <input type="text" name="start_date"
                                        class="datepicker-popup form-control current-date"
                                        placeholder="{{ __('start_date') }}" autocomplete="off" required>
                                </div>
                                <div class="form-group col-sm-6 col-md-4">
                                    <label>{{ __('end_date') }} <span class="text-danger">*</span></label>
                                    <input type="text" name="end_date" class="datepicker-popup form-control current-date"
                                        placeholder="{{ __('end_date') }}" autocomplete="off" required>
                                </div>
                            </div>
                            <input class="btn btn-theme" id="create-btn" type="submit" value={{ __('submit') }}>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('list') . ' ' . __('semester') }}
                        </h4>
                        <table aria-describedby="mydesc" class='table' id='table_list' data-toggle="table"
                            data-url="{{ url('semester/show') }}" data-click-to-select="true" data-side-pagination="server"
                            data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true"
                            data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                            data-fixed-columns="true" data-trim-on-search="false" data-mobile-responsive="true"
                            data-sort-name="id" data-sort-order="desc" data-maintain-selected="true"
                            data-query-params="semesterQueryParams" data-show-export="true"
                            data-export-options='{"fileName": "semester-list-<?= date('d-m-y') ?>","ignoreColumn":
                            ["operate", "order"]}'>
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false">
                                        {{ __('id') }}</th>
                                    <th scope="col" data-field="no" data-sortable="false">{{ __('no.') }}</th>
                                    <th scope="col" data-field="name" data-sortable="false">{{ __('name') }}</th>
                                    <th scope="col" data-field="start_date" data-sortable="false"
                                        data-formatter="formatDate">
                                        {{ __('start_date') }}</th>
                                    <th scope="col" data-field="end_date" data-sortable="false"
                                        data-formatter="formatDate">
                                        {{ __('end_date') }}</th>
                                    <th scope="col" data-field="status" data-sortable="false"
                                        data-formatter="semesterStatusFormatter">{{ __('status') }}</th>
                                    <th scope="col" data-field="created_at" data-sortable="true" data-visible="false">
                                        {{ __('created_at') }}</th>
                                    <th scope="col" data-field="updated_at" data-sortable="true" data-visible="false">
                                        {{ __('updated_at') }}</th>

                                    <th scope="col" data-field="operate" data-sortable="false"
                                        data-events="semesterEvents">{{ __('action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
            <!-- Modal -->
            <div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
                aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="exampleModalLabel">{{ __('edit') . ' ' . __('semester') }}</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <form class="pt-3 section-edit-form" id="edit-form" action="{{ url('semester') }}"
                            novalidate="novalidate">
                            <input type="hidden" name="edit_id" id="edit_id" value="" />
                            <div class="modal-body">
                                <div class="row">
                                    <div class="form-group col-sm-12 col-md-12">
                                        <label>{{ __('name') }} <span class="text-danger">*</span></label>
                                        <input name="edit_name" id="edit_name" type="text"
                                            placeholder="{{ __('name') }}" class="form-control" required />
                                    </div>
                                    <div class="form-group col-sm-6 col-md-4">
                                        <label>{{ __('start_date') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="edit_start_date" id="edit_start_date"
                                            class="datepicker-popup form-control current-date"
                                            placeholder="{{ __('start_date') }}" autocomplete="off" required>
                                    </div>
                                    <div class="form-group col-sm-6 col-md-4">
                                        <label>{{ __('end_date') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="edit_end_date" id="edit_end_date"
                                            class="datepicker-popup form-control current-date"
                                            placeholder="{{ __('end_date') }}" autocomplete="off" required>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary"
                                    data-dismiss="modal">{{ __('close') }}</button>
                                <input class="btn btn-theme" type="submit" value={{ __('edit') }} />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
