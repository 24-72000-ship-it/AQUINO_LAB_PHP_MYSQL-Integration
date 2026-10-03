# Studyroom Study Planner

A responsive study planner built with PHP, MySQL, HTML, CSS, and vanilla JavaScript. MySQL stores planner records; uploaded photos are kept in the project's `uploads/` folder. This is a single-user local setup, not a multi-account service.

## Project Structure

```text
valen_mysql/
├── index.php          # App entry point
├── planner.php        # PHP views, MySQL actions, and forms
├── planner.css        # Responsive light/dark interface
├── planner.js         # Calendar, filters, previews, and Pomodoro timer
├── config.php         # MySQL connection settings
├── student_app.sql    # Database schema and first-run sample records
├── README.md          # Setup and feature testing guide
├── create.php         # Legacy URL redirect
├── update.php         # Legacy URL redirect
└── delete.php         # Legacy URL redirect
```

## Features

- Dashboard totals, completion percentage, deadlines, today's schedule, and reminders
- Task create, edit, delete, completion, search, filters, deadline sort, and priority sort
- Subject management and links between subjects, tasks, notes, and sessions
- Monthly and weekly calendar for tasks and study sessions
- Study-session planning with overlap warnings and completion tracking
- Searchable notes with optional image attachments
- Pomodoro focus and break timer; completed focus blocks are recorded in MySQL
- Progress by subject, completed study time, and a seven-day activity chart
- Student name, theme, and timer preferences saved in MySQL
- Responsive desktop and mobile layout

## XAMPP Setup

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Keep this folder in `C:\xampp\htdocs\valen_mysql`.
3. Open phpMyAdmin at `http://localhost/phpmyadmin/` and import `student_app.sql`. The script uses the existing `valen_mysql` database, creates planner tables, and adds example subjects and tasks. Existing student rows are retained.
4. Check `config.php` if your MySQL username or password differs from the default XAMPP values (`root` with an empty password).
5. Open `http://localhost/valen_mysql/`.

The app creates `uploads/` on the first image upload. Ensure PHP can write to the project directory. Images must be JPG, PNG, or WEBP and no larger than 2 MB.

## Test the Main Flows

- Dashboard: verify task totals, completion percentage, upcoming deadlines, reminders, and today's sessions.
- Tasks: add a task with a subject and due date, search it, filter by subject/status/priority/date, change its status, edit it, then delete it.
- Subjects: add and edit a subject; link a task, note, and session to it; delete it and confirm the related records remain unassigned.
- Calendar: navigate months, switch to week view, select a date with an activity, and add a task or study session on that date.
- Study plan: add overlapping sessions on the same date and confirm the warning appears; mark a session done and verify progress updates.
- Notes and images: save a note, search/filter it, preview an allowed image before saving, open the saved image, replace it, remove it, and try an unsupported or >2 MB file.
- Pomodoro: set a short focus duration for testing, start and pause it, reset it, then complete a focus block and verify the session counter and recorded study minutes update.
- Progress and settings: compare subject progress to task statuses, inspect the seven-day chart, change the student name/theme/timer values, refresh, and verify the preferences persist.
- Responsive layout: test at desktop width and a narrow mobile viewport; open and close the navigation drawer.

## Photo Attachments

The browser checks file type and the 2 MB limit, then uses an object URL to preview the selected image before submission. PHP checks the uploaded MIME type and size again and stores a randomly named file in `uploads/`; the file path is associated with its task or note in MySQL. Removing/replacing a saved image deletes the previous file. Keep uploads protected from public access if you deploy the app beyond a trusted local environment.

## Storage and Limitations

Unlike a localStorage-only prototype, this version stores records in the configured MySQL database, so data is available to browsers that connect to this same server. Images are stored on the server filesystem. It does not include user accounts, permissions, cloud hosting, backup automation, email reminders, or notifications that run while the site is closed. For a public multi-user deployment, add authentication, authorization, CSRF/session hardening, upload isolation, backups, and scheduled notification infrastructure.
