import { request, collection, download, setToken, hasToken } from "./api.js";
import { pageHref, loginHref, returnTarget } from "./navigation.js";
import { submissionBody } from "./submission.js";
import {
  escape as e,
  money,
  date,
  terminal,
  localDateInput,
  bytes,
  requestKey,
} from "./format.js";

export function mount(options = {}) {
  const doc = options.document || document;
  const win = doc.defaultView;
  const navigate = options.navigate || ((href) => win.location.assign(href));
  const page = doc.body.dataset.page || "courses";
  const params = new URLSearchParams(win.location.search);
  const courseId = Number(params.get("course")) || null;
  const $ = (selector) => doc.querySelector(selector);
  const link = (label, href, style = "secondary") =>
    `<a class="button small ${style}" href="${e(href)}">${label}</a>`;
  function go(href, message) {
    if (message) {
      try {
        win.sessionStorage.setItem("campus.flash", message);
      } catch {}
    }
    navigate(href);
  }
  const signInHref = () =>
    loginHref(win.location.pathname + win.location.search);
  const main = $("#main");
  const modal = $("#modal");
  const state = {
    user: null,
    courses: [],
    enrollments: [],
    submissions: [],
    grades: [],
    purchases: [],
    assignments: [],
    view: page,
    courseId,
    filter: "all",
    query: "",
    busy: false,
  };
  const checkoutKeys = new Map();
  let noticeTimer;
  let epoch = 0;
  const courseName = (id) =>
    state.courses.find((c) => c.id === id)?.name || `Course #${id} (archived)`;
  const assignmentName = (id) =>
    state.assignments.find((a) => a.id === id)?.name || `Assignment #${id}`;
  const button = (label, action, id = "", style = "secondary") =>
    `<button type="button" class="button small ${style}" data-action="${action}" data-id="${e(id)}">${label}</button>`;
  const badge = (status) =>
    `<span class="badge ${status === "succeeded" || status === "enrolled" ? "green" : status === "failed" || status === "declined" ? "red" : "blue"}">${e(status)}</span>`;

  function notice(message, error = false) {
    clearTimeout(noticeTimer);
    const box = $("#notice");
    box.textContent = message;
    box.className = error ? "error" : "";
    box.hidden = false;
    noticeTimer = setTimeout(
      () => {
        box.hidden = true;
      },
      error ? 9000 : 4500,
    );
  }

  function resetSession() {
    epoch++;
    setToken("");
    checkoutKeys.clear();
    Object.assign(state, {
      user: null,
      enrollments: [],
      submissions: [],
      grades: [],
      purchases: [],
      assignments: [],
      filter: "all",
    });
  }

  function failure(error) {
    if (error.status === 401 && state.user) {
      resetSession();
      if (modal.open) modal.close();
      go(signInHref(), "Your session expired. Sign in again.");
    } else notice(error.message, true);
  }

  async function refresh() {
    const version = epoch;
    const courses = await collection("/api/courses");
    let records = [[], [], [], []];
    if (state.user)
      records = await Promise.all(
        [
          "/api/enrollments",
          "/api/submissions",
          "/api/grades",
          "/api/purchases",
        ].map(collection),
      );
    if (version !== epoch) return;
    const allowed = state.user?.isAdmin
      ? courses
      : courses.filter((c) =>
          records[0].some(
            (r) => r.courseId === c.id && r.userId === state.user.id,
          ),
        );
    const assignments = state.user
      ? (
          await Promise.all(
            allowed.map((c) => collection(`/api/courses/${c.id}/assignments`)),
          )
        ).flat()
      : [];
    if (version !== epoch) return;
    Object.assign(state, {
      courses,
      enrollments: records[0],
      submissions: records[1],
      grades: records[2],
      purchases: records[3],
      assignments,
    });
  }

  function render() {
    main.setAttribute("aria-busy", "false");
    const admin = Boolean(state.user?.isAdmin);
    const titles = {
      courses: "Courses",
      assignments: "Assignments",
      grades: "Grades & feedback",
      purchases: "Purchases",
      submissions: "Submissions",
      login: "Sign in",
      register: "Create account",
      submit: "Submit assignment",
      checkout: "Purchase course",
    };
    $("#breadcrumb").textContent = `Workspace / ${titles[state.view]}`;
    doc.querySelectorAll("[data-auth]").forEach((node) => {
      node.hidden = !state.user;
    });
    doc.querySelectorAll("[data-page-link]").forEach((node) => {
      node.classList.toggle("active", node.dataset.pageLink === state.view);
      node.setAttribute(
        "aria-current",
        node.dataset.pageLink === state.view ? "page" : "false",
      );
    });
    $("#session-actions").innerHTML = state.user
      ? `<span class="role-label">${admin ? "Administrator" : "Student"} workspace</span>${button("Sign out", "logout")}`
      : link("Sign in", signInHref(), "");
    $("#account").innerHTML =
      `<span class="avatar">${e((state.user?.username || "Guest").slice(0, 1).toUpperCase())}</span><div><div class="account-name">${e(state.user?.username || "Welcome to Campus")}</div><small>${state.user ? e(state.user.login) : "Sign in to start learning"}</small></div>`;
    const views = {
      courses: coursesView,
      assignments: assignmentsView,
      submissions: gradesView,
      grades: gradesOnlyView,
      purchases: purchasesView,
      login: () => authView(false),
      register: () => authView(true),
      submit: submissionView,
      checkout: checkoutView,
    };
    main.innerHTML = views[page]();
  }

  function heading(title, subtitle, actions = "") {
    return `<div class="page-heading"><div><p class="eyebrow">${state.user?.isAdmin ? "Manage your classroom" : "Make room for learning"}</p><h1>${title}</h1><p class="subtitle">${subtitle}</p></div><div class="heading-actions">${actions}${button("Refresh", "refresh")}</div></div>`;
  }

  function empty(title, text) {
    return `<div class="empty-state"><h2>${title}</h2>${text}</div>`;
  }

  function courseCards() {
    const mine = (id) =>
      state.enrollments.some(
        (r) => r.courseId === id && r.userId === state.user?.id,
      );
    const courses = state.courses.filter(
      (c) =>
        (!state.query ||
          c.name.toLowerCase().includes(state.query.toLowerCase())) &&
        (state.filter !== "mine" || mine(c.id)) &&
        (state.filter !== "free" || !c.price),
    );
    return `<p class="count-label">${courses.length} ${courses.length === 1 ? "course" : "courses"} to explore</p><div class="course-grid">${courses
      .map((c, index) => {
        const enrolled = mine(c.id);
        const pending = state.purchases.find(
          (p) =>
            p.courseId === c.id &&
            p.userId === state.user?.id &&
            !terminal(p.status),
        );
        const primary =
          state.user?.isAdmin || enrolled
            ? link(
                "View assignments →",
                pageHref("assignments", { course: c.id }),
                "ghost",
              )
            : pending
              ? link("Payment pending", pageHref("purchases"), "ghost")
              : c.price
                ? link(
                    "Purchase →",
                    state.user
                      ? pageHref("checkout", { course: c.id })
                      : loginHref(pageHref("checkout", { course: c.id })),
                    "",
                  )
                : button("Enroll →", "enroll", c.id, "");
        return `<article class="course-card"><div class="course-art tone-${index % 4}"><span class="course-code">CAMPUS / ${String(c.id).padStart(3, "0")}</span><span class="motif" aria-hidden="true">${["✳", "↗", "⌘", "◈"][index % 4]}</span></div><div class="course-content"><div class="course-meta"><span>${c.price ? "Paid course" : "Open learning"}</span>${enrolled ? badge("enrolled") : ""}</div><h3>${e(c.name)}</h3><div class="course-bottom"><span class="price ${c.price ? "" : "free"}">${money(c.price)}</span>${primary}</div>${state.user?.isAdmin ? `<div class="card-actions">${button("Edit", "edit-course", c.id)}${button("Archive", "archive-course", c.id, "danger")}${button("PDF", "report", c.id)}</div>` : ""}</div></article>`;
      })
      .join(
        "",
      )}</div>${courses.length ? "" : empty("No courses here yet", "Try another filter, or check back soon.")}`;
  }

  function coursesView() {
    const admin = state.user?.isAdmin;
    return (
      heading(
        admin ? "Your course catalogue" : "Find your next chapter.",
        admin
          ? "Create courses, organize assignments, and follow student progress."
          : "Explore something new. Build on what you already know.",
        admin ? button("+ New course", "new-course", "", "") : "",
      ) +
      `<section class="welcome"><div><h2>${state.user ? `Good to see you, ${e(state.user.username)}.` : "Curiosity starts here."}</h2><p>${admin ? "A clear view of your classroom. Choose a course to manage its assignments, or head to submissions to grade student work." : "Join a course, take on an assignment, and keep track of your progress. One small step can open a new direction."}</p></div><span class="welcome-art" aria-hidden="true">↗</span></section>
    <div class="toolbar"><div class="filters" aria-label="Course filters"><button class="filter ${state.filter === "all" ? "active" : ""}" data-filter="all">All courses</button><button class="filter ${state.filter === "free" ? "active" : ""}" data-filter="free">Free courses</button>${state.user ? `<button class="filter ${state.filter === "mine" ? "active" : ""}" data-filter="mine">My courses</button>` : ""}</div><input class="search" id="course-search" type="search" placeholder="Search courses…" aria-label="Search courses" value="${e(state.query)}"></div><div id="course-results">${courseCards()}</div>`
    );
  }

  function assignmentsView() {
    const admin = state.user?.isAdmin;
    const selected = state.courseId;
    const rows = state.assignments.filter(
      (a) => !selected || a.courseId === selected,
    );
    return (
      heading(
        selected ? e(courseName(selected)) : "Your assignments",
        admin
          ? "Manage deadlines and review the work ahead."
          : "A place for your next step. Deadlines appear in your local time.",
        admin && selected
          ? button("+ New assignment", "new-assignment", selected, "") +
              button("Export PDF", "report", selected)
          : "",
      ) +
      (selected
        ? `<p>${link("← All assignments", pageHref("assignments"))}</p>`
        : "") +
      `<div class="stack">${rows
        .map((a) => {
          const submission = state.submissions.find(
            (s) => s.assignmentId === a.id && s.userId === state.user.id,
          );
          const grade = state.grades.find(
            (g) => g.assignmentId === a.id && g.userId === state.user.id,
          );
          const expired = Date.now() > new Date(a.deadline).getTime();
          return `<article class="panel"><div class="panel-top"><div><p class="eyebrow">${e(courseName(a.courseId))}</p><h2>${e(a.name)}</h2></div>${submission ? badge("submitted") : `<span class="badge ${expired ? "red" : "orange"}">${expired ? "Deadline passed" : "To do"}</span>`}</div><p class="description">${e(a.description || "No description provided.")}</p><div class="detail-row"><span>Due ${date(a.deadline)}</span><span>One file · Any format · Up to 10 MB</span>${grade ? `<span>Grade: <strong>${grade.grade}/100</strong></span>` : ""}</div><div class="panel-footer">${admin ? button("Edit assignment", "edit-assignment", a.id) + button("Archive", "archive-assignment", a.id, "danger") : submission ? button("Download submission", "download", submission.id) : !expired ? link("Submit assignment", pageHref("submit", { assignment: a.id }), "") : '<span class="subtitle">Submissions are closed.</span>'}${submission ? `<span class="subtitle">${e(submission.originalFilename)} · ${bytes(submission.sizeBytes)}</span>` : ""}</div></article>`;
        })
        .join(
          "",
        )}</div>${rows.length ? "" : empty("No assignments yet", admin ? "Open a course to create its first assignment." : "Enroll in a course to see its assignments here.")}`
    );
  }

  function gradesView() {
    const admin = state.user?.isAdmin;
    return (
      heading(
        admin ? "Student work & grades" : "Your work, your progress",
        admin
          ? "Download submissions and give students a grade and feedback."
          : "Your submitted files and feedback, together in one place.",
      ) +
      (state.submissions.length
        ? `<div class="table-wrap"><table><thead><tr><th>Assignment / file</th>${admin ? "<th>Student</th>" : ""}<th>Submitted</th><th>Grade</th><th>Actions</th></tr></thead><tbody>${state.submissions
            .map((s) => {
              const grade = state.grades.find(
                (g) =>
                  g.assignmentId === s.assignmentId && g.userId === s.userId,
              );
              return `<tr><td><div class="table-title">${e(assignmentName(s.assignmentId))}</div><div class="table-sub">${e(s.originalFilename)} · ${bytes(s.sizeBytes)}</div>${grade?.comment ? `<p>${e(grade.comment)}</p>` : ""}</td>${admin ? `<td>Student #${s.userId}</td>` : ""}<td>${date(s.submittedAt)}</td><td>${grade ? `<span class="grade-number">${grade.grade}</span><span class="subtitle"> / 100</span>` : badge("ungraded")}</td><td>${button("Download", "download", s.id)} ${admin ? button(grade ? "Edit grade" : "Grade", "grade", s.id, "ghost") : ""}${admin && grade ? ` ${button("Delete grade", "delete-grade", grade.id, "danger")}` : ""}</td></tr>`;
            })
            .join("")}</tbody></table></div>`
        : empty(
            "Your story is still starting",
            admin
              ? "Student submissions will appear here."
              : "Submit your first assignment to see it here.",
          ))
    );
  }

  function purchasesView() {
    return (
      heading(
        state.user?.isAdmin ? "Course purchases" : "Your purchases",
        "Payments process in the background. Pending purchases refresh automatically.",
      ) +
      `<div class="stack">${[...state.purchases]
        .reverse()
        .map(
          (p) =>
            `<article class="panel"><div class="panel-top"><div><p class="eyebrow">PURCHASE #${p.id}${state.user.isAdmin ? ` · STUDENT #${p.userId}` : ""}</p><h2>${e(courseName(p.courseId))}</h2></div>${badge(p.status)}</div><div class="detail-row"><span>${money(p.amount)}</span><span>${date(p.createdAt)}</span><span>${p.attempts.length} of 3 possible attempts</span></div><div class="attempts">${p.attempts.map((a) => `<span class="attempt">Attempt ${e(a.attempt_number)} · ${e(a.status)}${a.error_code ? ` · ${e(a.error_code)}` : ""}</span>`).join("")}</div>${p.status === "succeeded" && p.userId === state.user.id ? `<div class="panel-footer">${link("Go to assignments →", pageHref("assignments", { course: p.courseId }), "ghost")}</div>` : ""}</article>`,
        )
        .join(
          "",
        )}</div>${state.purchases.length ? "" : empty("No purchases yet", "Free courses need no payment. Explore the catalogue to find a paid course.")}`
    );
  }

  function showModal(title, content) {
    $("#modal-content").innerHTML =
      `<div class="modal-head"><h2 id="modal-title">${title}</h2><button class="close" data-action="close" aria-label="Close dialog">×</button></div><div class="modal-body">${content}</div>`;
    if (!modal.open) modal.showModal();
  }

  function form(id, fields, submitLabel = "Save changes", note = "") {
    const standalone = [
      "login-form",
      "register-form",
      "upload-form",
      "purchase-form",
    ].includes(id);
    const cancel = standalone
      ? link(
          "Cancel",
          pageHref(id === "upload-form" ? "assignments" : "courses"),
        )
      : button("Cancel", "close");
    return `<form id="${id}" class="form-grid">${fields}${note}<p class="form-error" role="alert" hidden></p><div class="form-actions">${cancel}<button class="button" type="submit">${submitLabel}</button></div></form>`;
  }

  function field(label, name, value = "", type = "text", extra = "") {
    return `<label class="field">${label}<input name="${name}" type="${type}" value="${e(value)}" ${extra}></label>`;
  }

  function authView(register = false) {
    if (state.user)
      return (
        heading("You’re signed in.", `Welcome, ${e(state.user.username)}.`) +
        `<section class="panel page-form">${link("Continue →", returnTarget(win.location.search, win.location.origin), "")}</section>`
      );
    const next = returnTarget(win.location.search, win.location.origin);
    return (
      heading(
        register ? "Start your next chapter." : "Welcome back.",
        "Sign in to manage your learning.",
      ) +
      `<section class="panel page-form"><div class="auth-tabs"><a class="${register ? "" : "active"}" href="${e(pageHref("login", { next }))}">Sign in</a><a class="${register ? "active" : ""}" href="${e(pageHref("register", { next }))}">Create account</a></div>` +
      form(
        register ? "register-form" : "login-form",
        field(
          "Login",
          "login",
          "",
          "text",
          'required minlength="3" maxlength="64" pattern="[a-zA-Z0-9_.\\-]+" autocomplete="username"',
        ) +
          (register
            ? field(
                "Display name",
                "username",
                "",
                "text",
                'required maxlength="100" autocomplete="name"',
              )
            : "") +
          field(
            "Password",
            "password",
            "",
            "password",
            `required ${register ? 'minlength="12"' : ""} maxlength="128" autocomplete="${register ? "new-password" : "current-password"}"`,
          ),
        register ? "Create account" : "Sign in",
        '<div class="form-note">Local demo: <strong>student</strong> / StudentPass123!<br>Administrator: <strong>admin</strong> / AdminPass123!</div>',
      ) +
      "</section>"
    );
  }

  const fixtures = [
    ["Success", "9900000000000010"],
    ["Success after one retry", "9900000000000028"],
    ["Success after two retries", "9900000000000036"],
    ["Retries exhausted", "9900000000000044"],
    ["Declined", "9900000000000051"],
    ["Provider timeout", "9900000000000069"],
  ];

  function checkoutView() {
    const id = Number(params.get("course"));
    const c = state.courses.find((c) => c.id === id);
    if (!c || !c.price)
      return empty(
        "Choose a paid course",
        link("Explore courses", pageHref("courses")),
      );
    if (
      state.enrollments.some(
        (r) => r.courseId === id && r.userId === state.user.id,
      )
    )
      return empty(
        "You’re already enrolled",
        link("View assignments", pageHref("assignments", { course: id })),
      );
    if (
      state.purchases.some(
        (r) =>
          r.courseId === id &&
          r.userId === state.user.id &&
          !terminal(r.status),
      )
    )
      return empty(
        "Payment is processing",
        link("View purchases", pageHref("purchases")),
      );
    return (
      heading("A new chapter awaits.", `${e(c.name)} · ${money(c.price)}`) +
      '<section class="panel page-form">' +
      form(
        "purchase-form",
        `<input type="hidden" name="courseId" value="${id}"><label class="field">Mock payment scenario<select id="payment-scenario">${fixtures.map(([name, card]) => `<option value="${card}">${name}</option>`).join("")}</select></label>` +
          field(
            "Beneficiary name",
            "beneficiaryName",
            state.user.username,
            "text",
            'required maxlength="100" autocomplete="off"',
          ) +
          field(
            "Synthetic card number",
            "cardNumber",
            fixtures[0][1],
            "text",
            'required pattern="[0-9]{16}" maxlength="16" inputmode="numeric" autocomplete="off"',
          ) +
          `<div class="form-row">${field("Expiry date", "expiryDate", "12/2035", "text", 'required pattern="[0-9]{2}/[0-9]{4}" autocomplete="off"')}${field("CVV", "cvv", "123", "password", 'required pattern="[0-9]{3}" maxlength="3" inputmode="numeric" autocomplete="off"')}</div>`,
        "Purchase course",
        '<div class="form-note">This is a mock checkout. Use the synthetic scenarios above. Payment status and retries will appear in Purchases.</div>',
      ) +
      "</section>"
    );
  }

  function courseDialog(id) {
    const c = state.courses.find((c) => c.id === id);
    showModal(
      c ? "Edit course" : "Create a course",
      form(
        "course-form",
        `<input type="hidden" name="entityId" value="${c?.id || ""}">` +
          field(
            "Course name",
            "name",
            c?.name,
            "text",
            'required maxlength="255"',
          ) +
          field(
            "Price (USD)",
            "price",
            c?.price == null ? "" : c.price / 100,
            "number",
            'min="0" max="21474836.47" step="0.01"',
          ) +
          '<p class="subtitle">Leave blank or enter 0 for a free course.</p>',
        c ? "Save changes" : "Create course",
      ),
    );
  }

  function assignmentDialog(courseId, id) {
    const a = state.assignments.find((a) => a.id === id);
    showModal(
      a ? "Edit assignment" : "Create an assignment",
      form(
        "assignment-form",
        `<input type="hidden" name="entityId" value="${a?.id || ""}"><input type="hidden" name="courseId" value="${a?.courseId || courseId}">` +
          field(
            "Assignment name",
            "name",
            a?.name,
            "text",
            'required maxlength="255"',
          ) +
          field(
            "Deadline (your local time)",
            "deadline",
            localDateInput(a?.deadline || Date.now() + 7 * 86400000),
            "datetime-local",
            "required",
          ) +
          `<label class="field">Description<textarea name="description" maxlength="10000">${e(a?.description)}</textarea></label>`,
        a ? "Save changes" : "Create assignment",
      ),
    );
  }

  function gradeDialog(submissionId) {
    const s = state.submissions.find((s) => s.id === submissionId);
    const g = state.grades.find(
      (g) => g.assignmentId === s.assignmentId && g.userId === s.userId,
    );
    showModal(
      g ? "Edit student grade" : "Grade student work",
      `<p class="subtitle">Student #${s.userId} · ${e(assignmentName(s.assignmentId))}</p>` +
        form(
          "grade-form",
          `<input type="hidden" name="entityId" value="${g?.id || ""}"><input type="hidden" name="userId" value="${s.userId}"><input type="hidden" name="assignmentId" value="${s.assignmentId}">` +
            field(
              "Grade (0–100)",
              "grade",
              g?.grade ?? "",
              "number",
              'required min="0" max="100" step="1"',
            ) +
            `<label class="field">Feedback<textarea name="comment" maxlength="10000">${e(g?.comment)}</textarea></label>`,
          g ? "Save grade" : "Create grade",
        ),
    );
  }

  function submissionView() {
    const id = Number(params.get("assignment"));
    const a = state.assignments.find((a) => a.id === id);
    if (!a)
      return empty(
        "Assignment unavailable",
        "Check that you are enrolled in its course. " +
          link("View assignments", pageHref("assignments")),
      );
    const submitted = state.submissions.find(
      (s) => s.assignmentId === id && s.userId === state.user.id,
    );
    if (submitted)
      return (
        heading("Already submitted", e(a.name)) +
        `<section class="panel page-form"><p>${e(submitted.originalFilename)} · ${bytes(submitted.sizeBytes)}</p>${button("Download submission", "download", submitted.id)} ${link("View submissions", pageHref("submissions"))}</section>`
      );
    if (new Date(a.deadline).getTime() <= Date.now())
      return empty(
        "The deadline has passed",
        link("View assignments", pageHref("assignments")),
      );
    if (
      !state.enrollments.some(
        (r) => r.courseId === a.courseId && r.userId === state.user.id,
      )
    )
      return empty(
        "Enrollment required",
        "Use an enrolled student account to submit this assignment.",
      );
    return (
      heading(
        "Submit your assignment",
        `${e(a.name)} · Due ${date(a.deadline)}`,
      ) +
      '<section class="panel page-form">' +
      form(
        "upload-form",
        `<input type="hidden" name="entityId" value="${id}"><label class="field">Your file<input name="file" type="file" required><small>Any format. Maximum 10,000,000 bytes. A submission cannot be replaced.</small></label>`,
        "Submit assignment",
      ) +
      "</section>"
    );
  }

  function gradesOnlyView() {
    return (
      heading("Grades & feedback", "Your recorded results and feedback.") +
      (state.grades.length
        ? `<div class="table-wrap"><table><thead><tr><th>Assignment</th>${state.user.isAdmin ? "<th>Student</th>" : ""}<th>Grade</th><th>Feedback</th><th>Updated</th></tr></thead><tbody>${state.grades.map((g) => `<tr><td>${e(assignmentName(g.assignmentId))}</td>${state.user.isAdmin ? `<td>Student #${g.userId}</td>` : ""}<td><span class="grade-number">${g.grade}</span> / 100</td><td>${e(g.comment || "—")}</td><td>${date(g.updatedAt)}</td></tr>`).join("")}</tbody></table></div>`
        : empty(
            "No grades yet",
            "Feedback will appear after your work is graded.",
          )) +
      `<p>${link(state.user.isAdmin ? "Grade submitted work →" : "View your submissions →", pageHref("submissions"))}</p>`
    );
  }

  function confirmDialog(title, description, action, id) {
    showModal(
      title,
      `<p class="subtitle">${description}</p><div class="form-actions">${button("Cancel", "close")}${button("Confirm", action, id, "danger")}</div>`,
    );
  }

  async function mutate(path, options, message) {
    await request(path, options);
    modal.close();
    notice(message);
    await refresh();
    render();
  }

  async function action(name, id) {
    if (state.busy && name !== "close") return;
    if (
      [
        "close",
        "new-course",
        "edit-course",
        "new-assignment",
        "edit-assignment",
        "grade",
        "archive-course",
        "archive-assignment",
        "delete-grade",
      ].includes(name)
    ) {
      if (name === "close") return modal.close();
      if (name === "new-course" || name === "edit-course")
        return courseDialog(id);
      if (name === "new-assignment") return assignmentDialog(id);
      if (name === "edit-assignment") return assignmentDialog(null, id);
      if (name === "grade") return gradeDialog(id);
      if (name === "archive-course")
        return confirmDialog(
          "Archive this course?",
          `${e(courseName(id))} will leave the catalogue. Existing student records remain in reports.`,
          "confirm-course",
          id,
        );
      if (name === "archive-assignment")
        return confirmDialog(
          "Archive this assignment?",
          "It will no longer accept submissions. Existing records remain in reports.",
          "confirm-assignment",
          id,
        );
      if (name === "delete-grade")
        return confirmDialog(
          "Delete this grade?",
          "The submitted file will stay available. You can add a new grade later.",
          "confirm-grade",
          id,
        );
    }
    if (name === "logout") {
      resetSession();
      go(pageHref("courses"));
      return;
    }
    if (name === "enroll" && !state.user) {
      go(signInHref());
      return;
    }
    state.busy = true;
    try {
      if (name === "refresh") {
        await refresh();
        render();
        notice("Workspace updated.");
      }
      if (name === "enroll")
        await mutate(
          `/api/courses/${id}/enroll`,
          { method: "POST" },
          "You’re enrolled. Your next chapter starts here.",
        );
      if (name === "download")
        await download(
          `/api/submissions/${id}/download`,
          state.submissions.find((s) => s.id === id).originalFilename,
        );
      if (name === "report")
        await download(`/api/courses/${id}/report`, `course-${id}-report.pdf`);
      if (name.startsWith("confirm-")) {
        const kind = {
          "confirm-course": "courses",
          "confirm-assignment": "assignments",
          "confirm-grade": "grades",
        }[name];
        await mutate(
          `/api/${kind}/${id}`,
          { method: "DELETE" },
          kind === "grades" ? "Grade deleted." : "Archived successfully.",
        );
      }
    } catch (error) {
      failure(error);
    } finally {
      state.busy = false;
    }
  }

  function handleClick(event) {
    const target = event.target.closest("[data-action], [data-filter]");
    if (!target) return;
    event.preventDefault();
    if (target.dataset.filter) {
      state.filter = target.dataset.filter;
      render();
    } else action(target.dataset.action, Number(target.dataset.id));
  }
  doc.addEventListener("click", handleClick);

  function handleInput(event) {
    if (event.target.id === "course-search") {
      state.query = event.target.value;
      $("#course-results").innerHTML = courseCards();
    }
  }

  function handleChange(event) {
    if (event.target.id === "payment-scenario")
      doc.querySelector('[name="cardNumber"]').value = event.target.value;
  }

  async function handleSubmit(event) {
    const f = event.target;
    if (f.tagName !== "FORM") return;
    const formId = f.getAttribute("id");
    event.preventDefault();
    if (state.busy || !f.reportValidity()) return;
    const data = Object.fromEntries(new FormData(f));
    const errorBox = f.querySelector(".form-error");
    errorBox.hidden = true;
    const submit = f.querySelector('[type="submit"]');
    submit.disabled = true;
    state.busy = true;
    try {
      if (formId === "login-form" || formId === "register-form") {
        if (formId === "register-form")
          await request("/api/register", { method: "POST", body: data });
        const login = await request("/api/login", {
          method: "POST",
          body: { login: data.login, password: data.password },
        });
        setToken(login.token);
        const user = await request("/api/me");
        epoch++;
        state.user = user;
        go(
          returnTarget(win.location.search, win.location.origin),
          "Welcome to your workspace.",
        );
      } else if (formId === "course-form") {
        const price =
          data.price === "" ? null : Math.round(Number(data.price) * 100);
        if (
          price !== null &&
          (!Number.isSafeInteger(price) || price < 0 || price > 2147483647)
        ) {
          throw new Error("Enter a price from 0 to 21,474,836.47 USD.");
        }
        await mutate(
          `/api/courses${data.entityId ? "/" + data.entityId : ""}`,
          {
            method: data.entityId ? "PATCH" : "POST",
            body: { name: data.name, price },
          },
          "Course saved.",
        );
      } else if (formId === "assignment-form") {
        await mutate(
          `/api/assignments${data.entityId ? "/" + data.entityId : ""}`,
          {
            method: data.entityId ? "PATCH" : "POST",
            body: {
              name: data.name,
              courseId: Number(data.courseId),
              deadline: new Date(data.deadline).toISOString(),
              description: data.description,
            },
          },
          "Assignment saved.",
        );
      } else if (formId === "grade-form") {
        await mutate(
          `/api/grades${data.entityId ? "/" + data.entityId : ""}`,
          {
            method: data.entityId ? "PATCH" : "POST",
            body: {
              userId: Number(data.userId),
              assignmentId: Number(data.assignmentId),
              grade: Number(data.grade),
              comment: data.comment || null,
            },
          },
          "Grade saved.",
        );
      } else if (formId === "upload-form") {
        const body = submissionBody(f);
        await request(`/api/assignments/${data.entityId}/submit`, {
          method: "POST",
          body,
        });
        go(pageHref("submissions"), "Assignment submitted.");
      } else if (formId === "purchase-form") {
        const { courseId, ...body } = data;
        const fingerprint = JSON.stringify([courseId, body]);
        if (!checkoutKeys.has(fingerprint))
          checkoutKeys.set(fingerprint, requestKey());
        await request(`/api/courses/${courseId}/purchase`, {
          method: "POST",
          body,
          headers: { "Idempotency-Key": checkoutKeys.get(fingerprint) },
        });
        checkoutKeys.delete(fingerprint);
        f.reset();
        go(
          pageHref("purchases"),
          "Purchase accepted. Watch its progress here.",
        );
      }
    } catch (error) {
      if (error.status === 401 && state.user) failure(error);
      else if (f.isConnected) {
        errorBox.textContent = error.message;
        errorBox.hidden = false;
      } else failure(error);
    } finally {
      state.busy = false;
      submit.disabled = false;
    }
  }

  doc.addEventListener("input", handleInput);
  doc.addEventListener("change", handleChange);
  doc.addEventListener("submit", handleSubmit);

  modal.addEventListener("close", () => {
    $("#modal-content").replaceChildren();
  });

  const pollTimer = (options.schedule || setInterval)(async () => {
    if (
      !state.user ||
      state.busy ||
      doc.hidden ||
      modal.open ||
      page !== "purchases" ||
      !state.purchases.some((p) => !terminal(p.status))
    )
      return;
    state.busy = true;
    const version = epoch;
    try {
      const purchases = await collection("/api/purchases");
      if (version !== epoch) return;
      const finished = state.purchases.some(
        (old) =>
          !terminal(old.status) &&
          purchases.some((p) => p.id === old.id && terminal(p.status)),
      );
      state.purchases = purchases;
      if (finished) {
        await refresh();
        notice("Payment status updated.");
      }
      render();
    } catch (error) {
      failure(error);
    } finally {
      state.busy = false;
    }
  }, 3000);

  async function start() {
    try {
      if (hasToken()) {
        try {
          state.user = await request("/api/me");
        } catch (error) {
          if (error.status === 401) resetSession();
          else throw error;
        }
      }
      if (!state.user && !["courses", "login", "register"].includes(page)) {
        go(signInHref());
        return;
      }
      await refresh();
      render();
      try {
        const message = win.sessionStorage.getItem("campus.flash");
        if (message) {
          win.sessionStorage.removeItem("campus.flash");
          notice(message);
        }
      } catch {}
    } catch (error) {
      main.innerHTML = empty(
        "Unable to load this page",
        e(error.message) + " " + button("Try again", "refresh"),
      );
      main.setAttribute("aria-busy", "false");
      failure(error);
    }
  }

  const ready = start();
  return {
    ready,
    destroy() {
      clearInterval(pollTimer);
      clearTimeout(noticeTimer);
      doc.removeEventListener("click", handleClick);
      doc.removeEventListener("input", handleInput);
      doc.removeEventListener("change", handleChange);
      doc.removeEventListener("submit", handleSubmit);
    },
  };
}
