"use strict";

function queryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
    };
}

function announcementQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
    };
}

function classQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        medium_id: $('#filter_medium_id').val(),
        shift_id: $('#filter_shift_id').val(),
        educational_program_id: $('#filter_educational_program_id').val()
    };
}

function ExamClassQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        class_id: $('#filter_class_name').val(),
        exam_id: $('#filter_exam_name').val()

    };
}

function gradesQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
    };
}

function getExamResult(p) {
    var selectedOption = $('#filter_class_id option:selected');
    var class_section_id = selectedOption.data('class-section-id');
    var class_id = $('#filter_class_id').val();
    var exam_id = $('#filter_exam_id').val();

    // Validate required parameters
    if (!class_id || class_id === '') {
        showErrorToast('Please select a class first.');
        return {
            limit: p.limit,
            sort: p.sort,
            order: p.order,
            offset: p.offset,
            search: p.search,
            class_section_id: '',
            class_id: '',
            exam_id: ''
        };
    }

    if (!exam_id || exam_id === '') {
        showErrorToast('Please select an exam first.');
        return {
            limit: p.limit,
            sort: p.sort,
            order: p.order,
            offset: p.offset,
            search: p.search,
            class_section_id: '',
            class_id: '',
            exam_id: ''
        };
    }

    if (!class_section_id) {
        showErrorToast('Invalid class selection. Please try again.');
        return {
            limit: p.limit,
            sort: p.sort,
            order: p.order,
            offset: p.offset,
            search: p.search,
            class_section_id: '',
            class_id: '',
            exam_id: ''
        };
    }

    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        class_section_id: class_section_id,
        class_id: class_id,
        exam_id: exam_id
    };
}
function SubjectQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        medium_id: $('#filter_subject_id').val()
    };
}

function AssignclassQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        medium_id: $('#filter_medium_id').val(),
    };

}

function AssignTeacherQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        class_id: $('#filter_class_id').val(),
    };
}

function AssignSubjectTeacherQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        class_id: $('#filter_class_section_id').val(),
        teacher_id: $('#filter_teacher_id').val(),
        subject_id: $('#filter_subject_id').val(),
    };
}

function StudentDetailQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        class_id: $('#filter_class_section_id').val(),

    };
}


function AssignmentSubmissionQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        subject_id: $('#filter_subject_id').val(),

    };
}

function AttendanceQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        'class_section_id': $('#timetable_class_section').val(),
        'date': $('#date').val(),
    };
}

function CreateAssignmentSubmissionQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        subject_id: $('#filter_subject_id').val(),
        class_id: $('#filter_class_section_id').val(),

    };
}

function CreateLessionQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        subject_id: $('#filter_subject_id').val(),
        class_id: $('#filter_class_section_id').val(),
        lesson_id: $('#filter_lesson_id').val()
    };
}

function CreateTopicQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        subject_id: $('#filter_subject_id').val(),
        class_id: $('#filter_class_section_id').val(),
        lesson_id: $('#filter_lesson_id').val()
    };
}

function gradesQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search
    };
}

function uploadMarksqueryParams(p) {

    var selectedOption = $('#class_id option:selected');
    var class_section_id = selectedOption.data('class-section-id');
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        'class_section_id': class_section_id,
        'class_id': $('#class_id').val(),
        'exam_id': $('#exam_id').val(),
        'subject_id': $('#subject_id').val(),
    };
}
function feesTypeQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search
    };
}
function feesClassQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
    };

}
function feesPaidListQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        class_id: $('#filter_class_id').val(),
        session_year_id: $('#filter_session_year_id').val(),
        mode: $('#filter_mode').val(),
    };
}
function feesPaymentTransactionQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        class_id: $('#filter_class_id').val(),
        session_year_id: $('#filter_session_year_id').val(),
        payment_status: $('#filter_payment_status').val(),
    };
}
function studentRollNumberQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        'class_section_id': $('#filter_roll_number_class_section_id').val(),
        'sort_by': $('#sort_by').val(),
    };
}
function userPermissionQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
    };
}
function onlineExamQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        'class_id': $('#filter-online-exam-class-id').val(),
        'subject_id': $('#filter-online-exam-subject-id').val(),
    };
}

function onlineExamQuestionsQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        'class_id': $('#filter-view-question-class-id').val(),
        'subject_id': $('#filter-question-subject-id').val(),
    };
}
function teacherQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search
    };
}

function onlineExamResultQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search
    };
}
function studentDetailsqueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        class_id: $('#filter_class_section_id').val()

    };
}
function sessionYearQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search
    };
}

function resetPasswordQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search
    };
}

function formFieldQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search
    };
}

function notificationQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search
    };
}
function leaveQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search,
        session_year_id: $('#filter_session_year_id').val(),
        filter_upcoming: $('#filter_upcoming').val(),
        month_id: $('#filter_month_id').val(),
    };
}

function semesterQueryParams(p) {
    return {
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        offset: p.offset,
        search: p.search
    };
}
