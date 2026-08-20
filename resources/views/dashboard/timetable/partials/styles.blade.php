<style>
    .school-timetable-page .timetable-scroll {
        overflow: auto;
        max-height: 75vh;
    }

    .school-timetable-page .timetable-grid {
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 0;
    }

    .school-timetable-page .timetable-lesson-col {
        width: 64px;
        min-width: 64px;
    }

    .school-timetable-page .timetable-day-col,
    .school-timetable-page .timetable-cell {
        width: 190px;
        min-width: 190px;
    }

    .school-timetable-page .timetable-grid thead th {
        position: sticky;
        top: 0;
        z-index: 1;
        background-color: #f8f9fa;
    }

    .school-timetable-page .timetable-cell {
        vertical-align: top;
        padding: 6px;
    }

    .school-timetable-page .timetable-cell-conflict {
        background: #fff7ed;
    }

    .school-timetable-page .lesson-card {
        display: block;
        border: 1px solid #c7d2fe;
        border-radius: .5rem;
        padding: .5rem .6rem;
        background: #eef2ff;
    }

    .school-timetable-page .lesson-card + .lesson-card {
        margin-top: 6px;
    }

    .school-timetable-page .lesson-card-conflict {
        border-color: #dc2626;
        background: #fef2f2;
    }

    .school-timetable-page .lesson-card-subject,
    .school-timetable-page .lesson-card-teacher {
        display: block;
        overflow-wrap: anywhere;
    }

    .school-timetable-page .lesson-card-subject {
        color: #1e1b4b;
        font-size: .85rem;
        font-weight: 600;
    }

    .school-timetable-page .lesson-card-teacher {
        margin-top: 2px;
        color: #4f46e5;
        font-size: .75rem;
        line-height: 1.25;
    }

    .school-timetable-page .lesson-card-conflicts {
        display: flex;
        flex-wrap: wrap;
        gap: 3px;
        margin-top: 6px;
    }

    .school-timetable-page .lesson-card-conflicts:empty {
        display: none;
    }

    .school-timetable-page .timetable-empty-cell {
        color: #cbd5e1;
        font-size: 1rem;
    }
</style>
