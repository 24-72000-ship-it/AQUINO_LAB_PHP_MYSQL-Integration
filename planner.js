(() => {
    const data = window.PLANNER_DATA;
    const sections = [...document.querySelectorAll("[data-section]")];
    const viewNames = {
        dashboard: "Overview", tasks: "Tasks", subjects: "Subjects", calendar: "Calendar",
        schedule: "Study plan", notes: "Notes", progress: "Progress", settings: "Settings"
    };
    const currentView = new URLSearchParams(window.location.search).get("view") || "dashboard";
    sections.forEach((section) => { section.hidden = section.dataset.section !== currentView; });
    document.querySelectorAll("[data-view]").forEach((link) => {
        const active = link.dataset.view === currentView;
        link.classList.toggle("active", active);
        if (active) link.setAttribute("aria-current", "page");
    });
    document.getElementById("pageName").textContent = viewNames[currentView] || "Overview";
    document.getElementById("headingTitle").textContent = viewNames[currentView] || "Overview";

    const sidebar = document.getElementById("sidebar");
    document.getElementById("menuToggle").addEventListener("click", () => sidebar.classList.toggle("open"));
    document.addEventListener("click", (event) => {
        if (window.innerWidth <= 850 && sidebar.classList.contains("open") && !sidebar.contains(event.target) && !event.target.closest("#menuToggle")) {
            sidebar.classList.remove("open");
        }
    });

    document.querySelectorAll("[data-open]").forEach((button) => {
        button.addEventListener("click", () => document.getElementById(button.dataset.open).showModal());
    });
    document.querySelectorAll("[data-close]").forEach((button) => {
        button.addEventListener("click", () => button.closest("dialog").close());
    });
    document.querySelectorAll("dialog").forEach((dialog) => {
        dialog.addEventListener("click", (event) => {
            if (event.target === dialog) dialog.close();
        });
    });

    function resetDialog(form, title) {
        form.reset();
        form.elements.id.value = "";
        const preview = form.querySelector(".image-preview");
        if (preview) { preview.src = ""; preview.hidden = true; }
        const filename = form.querySelector(".file-name");
        if (filename) filename.textContent = "";
        const removeCheckbox = form.querySelector('[name="remove_attachment"]');
        if (removeCheckbox) removeCheckbox.checked = false;
        const heading = form.closest("dialog").querySelector(".dialog-head h2");
        if (heading) heading.textContent = title;
    }

    const taskForm = document.getElementById("taskForm");
    const sessionForm = document.getElementById("sessionForm");
    const defaultTaskOpen = document.querySelector('[data-open="taskDialog"]');
    document.querySelectorAll(".edit-task").forEach((button) => button.addEventListener("click", () => {
        const task = data.tasks.find((item) => Number(item.id) === Number(button.dataset.id));
        if (!task) return;
        resetDialog(taskForm, "Edit task");
        taskForm.elements.id.value = task.id;
        taskForm.elements.title.value = task.title;
        taskForm.elements.subject_id.value = task.subject_id || "";
        taskForm.elements.type.value = task.task_type;
        taskForm.elements.priority.value = task.priority;
        taskForm.elements.status.value = task.status;
        taskForm.elements.due_at.value = task.due_at.replace(" ", "T").slice(0, 16);
        taskForm.elements.description.value = task.description;
        if (task.attachment) {
            const preview = taskForm.querySelector(".image-preview");
            preview.src = task.attachment;
            preview.hidden = false;
            taskForm.querySelector(".file-name").textContent = `Saved image: ${task.attachment.split("/").pop()}`;
        }
        document.getElementById("taskDialog").showModal();
    }));
    defaultTaskOpen?.addEventListener("click", () => resetDialog(taskForm, "Add a task"));

    document.querySelectorAll(".edit-subject").forEach((button) => button.addEventListener("click", () => {
        const subject = data.subjects.find((item) => Number(item.id) === Number(button.dataset.id));
        if (!subject) return;
        const form = document.querySelector("#subjectDialog form");
        resetDialog(form, "Edit subject");
        form.elements.id.value = subject.id;
        for (const key of ["name", "code", "instructor", "schedule_text", "color", "description"]) form.elements[key].value = subject[key] || "";
        document.getElementById("subjectDialog").showModal();
    }));

    document.querySelectorAll(".edit-session").forEach((button) => button.addEventListener("click", () => {
        const session = data.sessions.find((item) => Number(item.id) === Number(button.dataset.id));
        if (!session) return;
        resetDialog(sessionForm, "Edit study session");
        sessionForm.elements.id.value = session.id;
        sessionForm.elements.subject_id.value = session.subject_id || "";
        sessionForm.elements.topic.value = session.topic;
        sessionForm.elements.study_date.value = session.start_at.slice(0, 10);
        sessionForm.elements.start_time.value = session.start_at.slice(11, 16);
        sessionForm.elements.end_time.value = session.end_at.slice(11, 16);
        sessionForm.elements.goal.value = session.goal || "";
        sessionForm.elements.notes.value = session.notes || "";
        checkSessionOverlap();
        document.getElementById("sessionDialog").showModal();
    }));
    document.querySelector('[data-open="sessionDialog"]')?.addEventListener("click", () => {
        resetDialog(sessionForm, "Schedule a session");
        sessionForm.elements.study_date.value = localDateString(new Date());
        sessionForm.elements.start_time.value = "09:00";
        sessionForm.elements.end_time.value = "10:00";
        checkSessionOverlap();
    });

    document.querySelectorAll(".edit-note").forEach((button) => button.addEventListener("click", () => {
        const note = data.notes.find((item) => Number(item.id) === Number(button.dataset.id));
        if (!note) return;
        const form = document.querySelector("#noteDialog form");
        resetDialog(form, "Edit study note");
        form.elements.id.value = note.id;
        form.elements.subject_id.value = note.subject_id || "";
        form.elements.title.value = note.title;
        form.elements.body.value = note.body;
        if (note.attachment) {
            const preview = form.querySelector(".image-preview");
            preview.src = note.attachment;
            preview.hidden = false;
            form.querySelector(".file-name").textContent = `Saved image: ${note.attachment.split("/").pop()}`;
        }
        document.getElementById("noteDialog").showModal();
    }));
    document.querySelector('[data-open="noteDialog"]')?.addEventListener("click", () => resetDialog(document.querySelector("#noteDialog form"), "Add a study note"));

    document.querySelectorAll(".file-input").forEach((input) => input.addEventListener("change", () => {
        const form = input.closest("form");
        const preview = form.querySelector(".image-preview");
        const filename = form.querySelector(".file-name");
        const file = input.files[0];
        if (!file) { filename.textContent = ""; preview.hidden = true; return; }
        const allowed = ["image/jpeg", "image/png", "image/webp"];
        if (!allowed.includes(file.type) || file.size > 2 * 1024 * 1024) {
            alert("Choose a JPG, PNG, or WEBP image no larger than 2 MB.");
            input.value = "";
            filename.textContent = "";
            preview.hidden = true;
            return;
        }
        filename.textContent = `${file.name} · ${(file.size / 1024).toFixed(0)} KB`;
        preview.src = URL.createObjectURL(file);
        preview.hidden = false;
    }));

    const taskSearch = document.getElementById("taskSearch");
    if (taskSearch) {
        const taskRows = [...document.querySelectorAll(".task-row")];
        const taskFilters = ["taskSubject", "taskStatus", "taskPriority", "taskDue", "taskSort"].map((id) => document.getElementById(id));
        const applyTaskFilters = () => {
            const term = taskSearch.value.trim().toLowerCase();
            const subject = document.getElementById("taskSubject").value;
            const status = document.getElementById("taskStatus").value;
            const priority = document.getElementById("taskPriority").value;
            const dueDate = document.getElementById("taskDue").value;
            let visible = 0;
            taskRows.forEach((row) => {
                const show = row.dataset.title.includes(term) && (!subject || row.dataset.subject === subject) && (!status || row.dataset.status === status) && (!priority || row.dataset.priority === priority) && (!dueDate || row.dataset.deadline.startsWith(dueDate));
                row.hidden = !show;
                if (show) visible++;
            });
            const sortBy = document.getElementById("taskSort").value;
            const priorityRank = { High: 0, Medium: 1, Low: 2 };
            [...taskRows].sort((a, b) => sortBy === "priority" ? priorityRank[a.dataset.priority] - priorityRank[b.dataset.priority] || a.dataset.deadline.localeCompare(b.dataset.deadline) : a.dataset.deadline.localeCompare(b.dataset.deadline))
                .forEach((row) => document.getElementById("taskList").insertBefore(row, document.getElementById("noTasks")));
            document.getElementById("noTasks").hidden = visible > 0;
        };
        taskSearch.addEventListener("input", applyTaskFilters);
        taskFilters.forEach((filter) => filter.addEventListener("change", applyTaskFilters));
    }

    const noteSearch = document.getElementById("noteSearch");
    if (noteSearch) {
        const cards = [...document.querySelectorAll(".note-card")];
        const subjectFilter = document.getElementById("noteSubject");
        const applyNoteFilters = () => {
            const term = noteSearch.value.toLowerCase().trim();
            let visible = 0;
            cards.forEach((card) => {
                const show = card.dataset.title.includes(term) && (!subjectFilter.value || card.dataset.subject === subjectFilter.value);
                card.hidden = !show;
                if (show) visible++;
            });
            document.getElementById("noNotes").hidden = visible > 0;
        };
        noteSearch.addEventListener("input", applyNoteFilters);
        subjectFilter.addEventListener("change", applyNoteFilters);
    }

    function localDateString(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, "0");
        const day = String(date.getDate()).padStart(2, "0");
        return `${year}-${month}-${day}`;
    }

    const calendarGrid = document.getElementById("calendarGrid");
    if (calendarGrid) {
        let cursor = new Date();
        let selected = localDateString(cursor);
        let mode = "month";
        const dayNames = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
        const title = document.getElementById("calendarTitle");
        const selectedLabel = document.getElementById("selectedDateLabel");
        const eventContainer = document.getElementById("selectedEvents");
        const renderSelected = () => {
            const events = data.calendar.filter((item) => item.date === selected).sort((a, b) => a.time.localeCompare(b.time));
            selectedLabel.textContent = new Date(`${selected}T12:00:00`).toLocaleDateString(undefined, { weekday: "long", month: "long", day: "numeric", year: "numeric" });
            eventContainer.replaceChildren();
            if (!events.length) {
                const empty = document.createElement("div"); empty.className = "empty-inline"; empty.textContent = "Nothing scheduled for this day."; eventContainer.append(empty); return;
            }
            events.forEach((item) => {
                const row = document.createElement("div"); row.className = "selected-event";
                const dot = document.createElement("span"); dot.className = "subject-dot"; dot.style.setProperty("--subject", item.color);
                const label = document.createElement("b"); label.textContent = item.title;
                const kind = document.createElement("small"); kind.textContent = `${item.time} · ${item.subject} · ${item.kind}`;
                row.append(dot, label, kind); eventContainer.append(row);
            });
        };
        const renderCalendar = () => {
            calendarGrid.replaceChildren();
            dayNames.forEach((day) => { const label = document.createElement("div"); label.className = "calendar-day-name"; label.textContent = day; calendarGrid.append(label); });
            const start = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
            const gridStart = new Date(start);
            if (mode === "month") gridStart.setDate(1 - start.getDay());
            else {
                const weekday = cursor.getDay();
                gridStart.setDate(cursor.getDate() - weekday);
            }
            const count = mode === "month" ? 42 : 7;
            for (let offset = 0; offset < count; offset++) {
                const date = new Date(gridStart); date.setDate(gridStart.getDate() + offset);
                const key = localDateString(date);
                const cell = document.createElement("div");
                cell.className = `calendar-cell${date.getMonth() !== cursor.getMonth() && mode === "month" ? " outside" : ""}${key === localDateString(new Date()) ? " today" : ""}${key === selected ? " selected" : ""}`;
                const number = document.createElement("span"); number.className = "calendar-day-number"; number.textContent = date.getDate(); cell.append(number);
                const items = data.calendar.filter((item) => item.date === key);
                items.slice(0, 2).forEach((item) => {
                    const event = document.createElement("div"); event.className = `calendar-event${item.kind === "study" ? " study" : ""}`; event.style.setProperty("--event-color", item.color); event.textContent = `${item.time} ${item.title}`; cell.append(event);
                });
                if (items.length > 2) { const more = document.createElement("small"); more.className = "calendar-more"; more.textContent = `+${items.length - 2} more`; cell.append(more); }
                cell.addEventListener("click", () => { selected = key; renderCalendar(); renderSelected(); });
                calendarGrid.append(cell);
            }
            title.textContent = mode === "month" ? cursor.toLocaleDateString(undefined, { month: "long", year: "numeric" }) : `Week of ${gridStart.toLocaleDateString(undefined, { month: "short", day: "numeric" })}`;
        };
        document.getElementById("calendarPrev").addEventListener("click", () => {
            cursor = mode === "month" ? new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1) : new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() - 7);
            selected = localDateString(cursor); renderCalendar(); renderSelected();
        });
        document.getElementById("calendarNext").addEventListener("click", () => {
            cursor = mode === "month" ? new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1) : new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + 7);
            selected = localDateString(cursor); renderCalendar(); renderSelected();
        });
        document.getElementById("calendarToday").addEventListener("click", () => { cursor = new Date(); selected = localDateString(cursor); renderCalendar(); renderSelected(); });
        document.querySelectorAll("[data-calendar-mode]").forEach((button) => button.addEventListener("click", () => {
            mode = button.dataset.calendarMode;
            document.querySelectorAll("[data-calendar-mode]").forEach((item) => item.classList.toggle("active", item === button));
            renderCalendar();
        }));
        document.getElementById("addOnDate").addEventListener("click", () => {
            resetDialog(taskForm, "Add a task");
            taskForm.elements.due_at.value = `${selected}T09:00`;
            document.getElementById("taskDialog").showModal();
        });
        document.getElementById("addSessionOnDate").addEventListener("click", () => {
            resetDialog(sessionForm, "Schedule a session");
            sessionForm.elements.study_date.value = selected;
            sessionForm.elements.start_time.value = "09:00";
            sessionForm.elements.end_time.value = "10:00";
            checkSessionOverlap();
            document.getElementById("sessionDialog").showModal();
        });
        renderCalendar(); renderSelected();
    }

    const overlapWarning = document.getElementById("overlapWarning");
    function checkSessionOverlap() {
        if (!sessionForm || !overlapWarning) return;
        const date = sessionForm.elements.study_date.value;
        const start = sessionForm.elements.start_time.value;
        const end = sessionForm.elements.end_time.value;
        const ownId = Number(sessionForm.elements.id.value || 0);
        const overlaps = data.sessions.some((item) => Number(item.id) !== ownId && item.start_at.slice(0, 10) === date && start < item.end_at.slice(11, 16) && end > item.start_at.slice(11, 16));
        overlapWarning.hidden = !overlaps;
    }
    if (sessionForm) ["study_date", "start_time", "end_time"].forEach((key) => sessionForm.elements[key].addEventListener("input", checkSessionOverlap));

    const timerDisplay = document.getElementById("timerDisplay");
    if (timerDisplay) {
        const durations = {
            focus: Number(document.getElementById("focusSetting").value) || 25,
            short: Number(document.getElementById("shortSetting").value) || 5,
            long: Number(document.getElementById("longSetting").value) || 15
        };
        let timerMode = "focus";
        let remaining = durations.focus * 60;
        let finishAt = 0;
        let interval = null;
        let completedCount = Number(document.getElementById("focusCount").textContent) || 0;
        const startButton = document.getElementById("timerStart");
        const timerLabel = document.getElementById("timerLabel");
        const drawTimer = () => {
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            timerDisplay.textContent = `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
            document.title = "Studyroom";
        };
        const stopTimer = () => { clearInterval(interval); interval = null; startButton.textContent = "Start"; };
        const startTimer = () => {
            if (interval) { stopTimer(); timerLabel.textContent = "PAUSED"; return; }
            finishAt = Date.now() + remaining * 1000;
            startButton.textContent = "Pause";
            timerLabel.textContent = timerMode === "focus" ? "FOCUSING" : "TAKE A BREATH";
            interval = setInterval(() => {
                remaining = Math.max(0, Math.ceil((finishAt - Date.now()) / 1000)); drawTimer();
                if (remaining === 0) finishSession();
            }, 250);
        };
        const finishSession = async () => {
            stopTimer();
            const wasFocus = timerMode === "focus";
            timerLabel.textContent = wasFocus ? "FOCUS COMPLETE" : "BREAK COMPLETE";
            if (wasFocus) {
                completedCount++;
                document.getElementById("focusCount").textContent = completedCount;
                const body = new URLSearchParams({ action: "focus_complete", csrf_token: data.csrf, subject_id: document.getElementById("timerSubject").value, minutes: String(durations.focus) });
                try { await fetch("index.php?view=schedule", { method: "POST", body, credentials: "same-origin" }); } catch (error) { console.error("Could not save completed focus session.", error); }
                if ("Notification" in window && Notification.permission === "granted") new Notification("Focus session complete", { body: "Nice work. Time for a short break?" });
                timerMode = "short";
                document.querySelectorAll("[data-timer-mode]").forEach((button) => button.classList.toggle("active", button.dataset.timerMode === "short"));
                remaining = durations.short * 60;
            } else {
                timerMode = "focus";
                document.querySelectorAll("[data-timer-mode]").forEach((button) => button.classList.toggle("active", button.dataset.timerMode === "focus"));
                remaining = durations.focus * 60;
            }
            drawTimer();
        };
        startButton.addEventListener("click", startTimer);
        document.getElementById("timerReset").addEventListener("click", () => { stopTimer(); remaining = durations[timerMode] * 60; timerLabel.textContent = "READY"; drawTimer(); });
        document.querySelectorAll("[data-timer-mode]").forEach((button) => button.addEventListener("click", () => {
            stopTimer(); timerMode = button.dataset.timerMode;
            document.querySelectorAll("[data-timer-mode]").forEach((item) => item.classList.toggle("active", item === button));
            remaining = durations[timerMode] * 60; timerLabel.textContent = "READY"; drawTimer();
        }));
        [["focusSetting", "focus"], ["shortSetting", "short"], ["longSetting", "long"]].forEach(([id, mode]) => {
            document.getElementById(id).addEventListener("change", (event) => {
                const min = mode === "focus" ? 180 : mode === "short" ? 60 : 90;
                durations[mode] = Math.max(1, Math.min(min, Number(event.target.value) || 1));
                event.target.value = durations[mode];
                if (!interval && timerMode === mode) { remaining = durations[mode] * 60; drawTimer(); }
            });
        });
        drawTimer();
    }

    const weeklyChart = document.getElementById("weeklyChart");
    if (weeklyChart) {
        const today = new Date();
        const days = Array.from({ length: 7 }, (_, index) => {
            const date = new Date(today); date.setDate(today.getDate() - (6 - index));
            const key = localDateString(date);
            const minutes = data.sessions.filter((session) => Number(session.completed) === 1 && session.start_at.slice(0, 10) === key)
                .reduce((sum, session) => sum + Math.max(0, (new Date(session.end_at.replace(" ", "T")) - new Date(session.start_at.replace(" ", "T"))) / 60000), 0);
            return { date, minutes };
        });
        const max = Math.max(60, ...days.map((day) => day.minutes));
        days.forEach(({ date, minutes }) => {
            const column = document.createElement("div"); column.className = "chart-day";
            const bar = document.createElement("div"); bar.className = "chart-bar"; bar.style.height = `${Math.max(3, minutes / max * 125)}px`; bar.title = `${Math.round(minutes)} study minutes`;
            const label = document.createElement("small"); label.textContent = date.toLocaleDateString(undefined, { weekday: "short" });
            column.append(bar, label); weeklyChart.append(column);
        });
    }
})();
