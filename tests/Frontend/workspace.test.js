import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import { JSDOM } from "jsdom";

test("workspace handles student and admin forms, pending checkout replay, uploads and safe rendering", async (t) => {
  const dom = new JSDOM(
    await fs.readFile(
      new URL("../../public/ui/index.html", import.meta.url),
      "utf8",
    ),
    { url: "http://campus.test/ui/index.html", pretendToBeVisual: true },
  );
  const { window } = dom;
  global.document = window.document;
  global.sessionStorage = window.sessionStorage;
  global.FormData = window.FormData;
  const modal = document.querySelector("#modal");
  modal.showModal = () => {
    modal.open = true;
  };
  modal.close = () => {
    modal.open = false;
    modal.dispatchEvent(new window.Event("close"));
  };
  let poll;
  const originalInterval = global.setInterval;
  global.setInterval = (callback) => {
    poll = callback;
    return 0;
  };
  t.after(() => {
    global.setInterval = originalInterval;
    dom.window.close();
  });
  const records = {
    courses: [
      { id: 1, name: "Free Computing", price: null },
      { id: 2, name: "<img src=x onerror=alert(1)>", price: 2500 },
    ],
    enrollments: [],
    submissions: [],
    grades: [],
    purchases: [],
    assignments: [],
  };
  const calls = [];
  let user;
  let ambiguousCheckout = true;
  let checkoutKey;
  global.fetch = async (path, options) => {
    const method = options.method;
    const body =
      typeof options.body === "string"
        ? JSON.parse(options.body)
        : options.body;
    const url = new URL(path, "http://campus.test");
    path = url.pathname;
    calls.push({ path, method, body, headers: options.headers });
    const reply = (value, status = 200) =>
      new Response(status === 204 ? null : JSON.stringify(value), { status });
    if (path === "/api/login") {
      user = {
        id: body.login === "admin" ? 1 : 2,
        login: body.login,
        username: body.login === "admin" ? "Administrator" : "Student",
        isAdmin: body.login === "admin",
      };
      return reply({ token: "test-jwt" });
    }
    if (path === "/api/register") return reply({}, 201);
    if (path === "/api/me") return reply(user);
    if (path === "/api/courses/1/enroll") {
      records.enrollments.push({ id: 1, userId: 2, courseId: 1 });
      records.assignments.push({
        id: 1,
        courseId: 1,
        name: "First assignment",
        description: "<script>bad()</script>",
        deadline: new Date(Date.now() + 86400000).toISOString(),
      });
      return reply({}, 201);
    }
    if (/\/api\/courses\/\d+\/assignments/.test(path))
      return reply(
        records.assignments.filter(
          (a) => a.courseId === Number(path.split("/")[3]),
        ),
      );
    if (path === "/api/assignments/1/submit") {
      assert.deepEqual([...body.keys()], ["file"]);
      records.submissions.push({
        id: 1,
        assignmentId: 1,
        userId: 2,
        originalFilename: "work.any",
        sizeBytes: 4,
        submittedAt: new Date().toISOString(),
      });
      return reply({}, 201);
    }
    if (path === "/api/courses/2/purchase") {
      assert.deepEqual(Object.keys(body).sort(), [
        "beneficiaryName",
        "cardNumber",
        "cvv",
        "expiryDate",
      ]);
      assert.equal(body.cardNumber, "9900000000000036");
      if (ambiguousCheckout) {
        checkoutKey = options.headers["Idempotency-Key"];
        records.purchases.push({
          id: 1,
          userId: 2,
          courseId: 2,
          amount: 2500,
          status: "pending",
          createdAt: new Date().toISOString(),
          attempts: [],
        });
        ambiguousCheckout = false;
        throw new TypeError("Network lost after server accepted checkout");
      }
      assert.equal(options.headers["Idempotency-Key"], checkoutKey);
      return reply(records.purchases[0], 201);
    }
    const kind = path.split("/")[2];
    if (method === "GET" && path.split("/").length === 3)
      return reply(records[kind] || []);
    if (method === "DELETE") {
      records[kind] = records[kind].filter(
        (r) => r.id !== Number(path.split("/")[3]),
      );
      return reply(null, 204);
    }
    if (method === "POST") {
      const record = { ...body, id: records[kind].length + 1 };
      records[kind].push(record);
      return reply(record, 201);
    }
    if (method === "PATCH") {
      Object.assign(
        records[kind].find((r) => r.id === Number(path.split("/")[3])),
        body,
      );
      return reply({}, 200);
    }
    throw new Error(`Unexpected ${method} ${path}`);
  };
  const wait = async (predicate) => {
    for (let i = 0; i < 100; i++) {
      if (predicate()) {
        await new Promise((resolve) => setTimeout(resolve, 0));
        return;
      }
      await new Promise((resolve) => setTimeout(resolve, 5));
    }
    assert.fail(
      "Workspace did not reach expected state: " +
        document.querySelector("#main").textContent,
    );
  };
  const click = (action, id) => {
    const node = document.querySelector(
      `[data-action="${action}"]${id === undefined ? "" : `[data-id="${id}"]`}`,
    );
    assert.ok(node, `Missing ${action}`);
    node.click();
  };
  const fill = (name, value) => {
    modal.querySelector(`[name="${name}"]`).value = value;
  };
  const submit = async (formId) => {
    const f = document.getElementById(formId);
    assert.ok(f.reportValidity(), `Invalid ${formId}`);
    f.dispatchEvent(
      new window.Event("submit", { bubbles: true, cancelable: true }),
    );
    await new Promise((resolve) => setTimeout(resolve, 0));
  };
  await import("../../public/ui/app.js");
  await wait(() => document.querySelectorAll(".course-card").length === 2);
  assert.equal(document.querySelector(".course-card img"), null);
  click("login");
  assert.equal(document.querySelector('#login-form [data-action="close"]').type, 'button');
  assert.equal(document.querySelectorAll('#login-form button[type="submit"]').length, 1);
  fill("login", "student");
  fill("password", "StudentPass123!");
  await submit("login-form");
  await wait(() => document.querySelector('[data-action="logout"]'));
  click("enroll", 1);
  await wait(() =>
    document.querySelector('[data-action="course"][data-id="1"]'),
  );
  click("course", 1);
  assert.equal(document.querySelector(".description script"), null);
  click("upload", 1);
  const input = modal.querySelector('[name="file"]');
  const file = new window.File(["work"], "work.any");
  Object.defineProperty(input, "files", { value: [file] });
  // jsdom does not implement file picker population in FormData; preserve actual form fields and inject its selected File.
  const BrowserFormData = window.FormData;
  global.FormData = class extends BrowserFormData {
    constructor(form) {
      super(form);
      if (form?.id === "upload-form") this.set("file", file);
    }
  };
  document.getElementById("upload-form").reportValidity = () => true;
  await submit("upload-form");
  await wait(() => document.querySelector('[data-action="download"]'));
  document.querySelector('[data-view="courses"]').click();
  click("purchase", 2);
  document.getElementById("payment-scenario").value = "9900000000000036";
  document
    .getElementById("payment-scenario")
    .dispatchEvent(new window.Event("change", { bubbles: true }));
  await submit("purchase-form");
  assert.equal(modal.open, true);
  assert.match(
    modal.querySelector(".form-error").textContent,
    /Cannot connect/,
  );
  await submit("purchase-form");
  await wait(
    () => document.querySelector(".panel .badge")?.textContent === "pending",
  );
  assert.equal(sessionStorage.length, 1);
  assert.equal(sessionStorage.getItem("campus.token"), "test-jwt");
  document.querySelector('[data-view="courses"]').click();
  const search = document.getElementById("course-search");
  search.focus();
  search.value = "img";
  search.dispatchEvent(new window.Event("input", { bubbles: true }));
  await poll();
  assert.equal(document.activeElement, search);
  assert.equal(document.querySelectorAll(".course-card").length, 1);
  records.purchases[0].status = "succeeded";
  records.purchases[0].attempts = [
    { attempt_number: 1, status: "transient" },
    { attempt_number: 2, status: "transient" },
    { attempt_number: 3, status: "succeeded" },
  ];
  records.enrollments.push({ id: 2, userId: 2, courseId: 2 });
  await poll();
  assert.equal(document.activeElement, search);
  search.value = "";
  search.dispatchEvent(new window.Event("input", { bubbles: true }));
  document.querySelector('[data-view="purchases"]').click();
  assert.equal(
    document.querySelector(".panel .badge").textContent,
    "succeeded",
  );
  click("logout");
  assert.equal(sessionStorage.length, 0);
  click("login");
  fill("login", "admin");
  fill("password", "AdminPass123!");
  await submit("login-form");
  await wait(() => document.querySelector('[data-action="new-course"]'));
  click("new-course");
  fill("name", "New course");
  fill("price", "12.50");
  await submit("course-form");
  await wait(() => records.courses.length === 3 && !modal.open);
  assert.equal(records.courses[2].price, 1250);
  click("course", 3);
  click("new-assignment", 3);
  fill("name", "New assignment");
  fill("description", "Instructions");
  await submit("assignment-form");
  await wait(() => records.assignments.length === 2 && !modal.open);
  assert.match(records.assignments[1].deadline, /Z$/);
  document.querySelector('[data-view="grades"]').click();
  click("grade", 1);
  fill("grade", "91");
  fill("comment", "<strong>Good work</strong>");
  await submit("grade-form");
  await wait(
    () => document.querySelector(".grade-number")?.textContent === "91",
  );
  assert.equal(document.querySelector("td strong"), null);
  click("grade", 1);
  fill("grade", "95");
  await submit("grade-form");
  await wait(
    () => document.querySelector(".grade-number")?.textContent === "95",
  );
  click("delete-grade", 1);
  click("confirm-grade", 1);
  await wait(() => records.grades.length === 0 && !modal.open);
  click("logout");
  click("login");
  click("register");
  fill("login", "new-student");
  fill("username", "New Student");
  fill("password", "StudentPass123!");
  await submit("register-form");
  await wait(() => document.querySelector('[data-action="logout"]'));
  assert.ok(
    calls.some(
      (c) => c.path === "/api/register" && c.body.username === "New Student",
    ),
  );
  assert.ok(
    calls.some(
      (c) =>
        c.method === "PATCH" &&
        c.headers["Content-Type"] === "application/merge-patch+json",
    ),
  );
});
