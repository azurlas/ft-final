import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import { JSDOM } from "jsdom";
import { pageHref, returnTarget } from "../../public/ui/navigation.js";

test("navigation uses physical HTML pages and rejects unsafe return destinations", () => {
  assert.equal(
    pageHref("submit", { assignment: 7 }),
    "/ui/submit.html?assignment=7",
  );
  for (const next of [
    "https://evil.test/ui/courses.html",
    "//evil.test",
    "javascript:alert(1)",
    "/api/me",
    "/ui/login.html",
    "http://[",
  ]) {
    assert.equal(
      returnTarget("?next=" + encodeURIComponent(next), "http://campus.test"),
      "/ui/courses.html",
    );
  }
  assert.equal(
    returnTarget(
      "?next=%2Fui%2Fsubmit.html%3Fassignment%3D7",
      "http://campus.test",
    ),
    "/ui/submit.html?assignment=7",
  );
});

test("separate pages handle uploads, admin forms and checkout replay despite a shadowed form.id", async (t) => {
  let dom, instance, nextHref, poll;
  const load = async (href) => {
    const saved = dom ? Object.entries(dom.window.sessionStorage) : [];
    instance?.destroy();
    dom?.window.close();
    const url = new URL(href, "http://campus.test");
    dom = new JSDOM(
      await fs.readFile(
        new URL("../../public" + url.pathname, import.meta.url),
        "utf8",
      ),
      { url: url.href, pretendToBeVisual: true },
    );
    global.document = dom.window.document;
    global.sessionStorage = dom.window.sessionStorage;
    global.FormData = dom.window.FormData;
    for (const [key, value] of saved) sessionStorage.setItem(key, value);
    const modal = document.querySelector("#modal");
    modal.showModal = () => {
      modal.open = true;
    };
    modal.close = () => {
      modal.open = false;
      modal.dispatchEvent(new dom.window.Event("close"));
    };
    nextHref = null;
    const { mount } = await import("../../public/ui/app.js");
    instance = mount({
      document,
      navigate: (href) => {
        nextHref = href;
      },
      schedule: (callback) => {
        poll = callback;
        return 0;
      },
    });
    await instance.ready;
  };
  t.after(() => {
    instance?.destroy();
    dom?.window.close();
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
      "Page did not reach expected state: " +
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
    document.querySelector(`[name="${name}"]`).value = value;
  };
  const submit = async (formId) => {
    const f = document.getElementById(formId);
    assert.ok(f.reportValidity(), `Invalid ${formId}`);
    f.dispatchEvent(
      new dom.window.Event("submit", { bubbles: true, cancelable: true }),
    );
    await new Promise((resolve) => setTimeout(resolve, 0));
  };
  const shadowId = (form) => {
    assert.equal(
      form.querySelector('[name="id"]'),
      null,
      "Entity fields must not collide with form.id",
    );
    // Model browsers' LegacyOverrideBuiltIns behavior, which jsdom doesn't implement.
    Object.defineProperty(form, "id", {
      value: form.querySelector('[name="entityId"]'),
    });
    assert.equal(typeof form.id, "object");
  };
  await load("/ui/courses.html");
  assert.equal(document.querySelectorAll(".course-card").length, 2);
  assert.equal(document.querySelector(".course-card img"), null);
  assert.equal(
    document.querySelector('[data-page-link="assignments"]').tagName,
    "A",
  );
  assert.equal(document.querySelector("[data-view]"), null);
  await load("/ui/submit.html?assignment=1");
  assert.match(nextHref, /^\/ui\/login.html\?next=/);
  await load(nextHref);
  fill("login", "student");
  fill("password", "StudentPass123!");
  await submit("login-form");
  await wait(() => nextHref);
  assert.equal(nextHref, "/ui/submit.html?assignment=1");
  await load("/ui/courses.html");
  click("enroll", 1);
  await wait(() =>
    document.querySelector('a[href="/ui/assignments.html?course=1"]'),
  );
  await load("/ui/assignments.html?course=1");
  assert.equal(document.querySelector(".description script"), null);
  assert.ok(document.querySelector('a[href="/ui/submit.html?assignment=1"]'));
  await load("/ui/submit.html?assignment=1");
  let f = document.getElementById("upload-form");
  shadowId(f);
  const input = f.querySelector('[name="file"]');
  // jsdom cannot populate a file picker; inject selected File and browser validity.
  Object.defineProperty(input, "files", {
    configurable: true,
    value: [new dom.window.File([new Uint8Array(10000001)], "too-big.any")],
  });
  f.reportValidity = () => true;
  await submit("upload-form");
  await wait(() => !f.querySelector(".form-error").hidden);
  assert.match(f.querySelector(".form-error").textContent, /10,000,000/);
  assert.equal(calls.filter((c) => c.path.endsWith("/submit")).length, 0);
  Object.defineProperty(input, "files", { configurable: true, value: [] });
  await submit("upload-form");
  await wait(() =>
    /exactly one/.test(f.querySelector(".form-error").textContent),
  );
  Object.defineProperty(input, "files", {
    configurable: true,
    value: [new dom.window.File(["work"], "work.any")],
  });
  await submit("upload-form");
  await wait(() => nextHref);
  assert.equal(nextHref, "/ui/submissions.html");
  const upload = calls.find((c) => c.path.endsWith("/submit"));
  assert.equal(upload.body.get("file").name, "work.any");
  assert.equal(upload.body.get("file").size, 4);
  assert.equal(
    upload.headers["Content-Type"],
    undefined,
    "Browser must generate multipart boundary",
  );
  await load(nextHref);
  assert.match(document.querySelector("#main").textContent, /work.any/);
  await load("/ui/submit.html?assignment=1");
  assert.equal(document.getElementById("upload-form"), null);
  assert.match(
    document.querySelector("#main").textContent,
    /Already submitted/,
  );
  await load("/ui/checkout.html?course=2");
  document.getElementById("payment-scenario").value = "9900000000000036";
  document
    .getElementById("payment-scenario")
    .dispatchEvent(new dom.window.Event("change", { bubbles: true }));
  await submit("purchase-form");
  assert.match(
    document.querySelector(".form-error").textContent,
    /Cannot connect/,
  );
  assert.equal(nextHref, null);
  await poll();
  assert.ok(
    document.getElementById("purchase-form"),
    "Poll must preserve checkout input",
  );
  await submit("purchase-form");
  await wait(() => nextHref);
  assert.equal(nextHref, "/ui/purchases.html");
  await load(nextHref);
  assert.equal(document.querySelector(".panel .badge").textContent, "pending");
  records.purchases[0].status = "succeeded";
  records.purchases[0].attempts = [
    { attempt_number: 1, status: "transient" },
    { attempt_number: 2, status: "transient" },
    { attempt_number: 3, status: "succeeded" },
  ];
  records.enrollments.push({ id: 2, userId: 2, courseId: 2 });
  await poll();
  assert.equal(
    document.querySelector(".panel .badge").textContent,
    "succeeded",
  );
  await load("/ui/courses.html");
  const search = document.getElementById("course-search");
  search.focus();
  search.value = "img";
  search.dispatchEvent(new dom.window.Event("input", { bubbles: true }));
  await poll();
  assert.equal(document.activeElement, search);
  assert.equal(document.querySelectorAll(".course-card").length, 1);
  click("logout");
  assert.equal(sessionStorage.getItem("campus.token"), null);
  await load("/ui/login.html");
  fill("login", "admin");
  fill("password", "AdminPass123!");
  await submit("login-form");
  await wait(() => nextHref);
  await load(nextHref);
  click("new-course");
  fill("name", "New course");
  fill("price", "12.50");
  shadowId(document.getElementById("course-form"));
  await submit("course-form");
  await wait(
    () =>
      records.courses.length === 3 && !document.querySelector("#modal").open,
  );
  assert.equal(records.courses[2].price, 1250);
  await load("/ui/assignments.html?course=3");
  click("new-assignment", 3);
  fill("name", "New assignment");
  fill("description", "Instructions");
  shadowId(document.getElementById("assignment-form"));
  await submit("assignment-form");
  await wait(
    () =>
      records.assignments.length === 2 &&
      !document.querySelector("#modal").open,
  );
  assert.match(records.assignments[1].deadline, /Z$/);
  await load("/ui/submissions.html");
  click("grade", 1);
  fill("grade", "91");
  fill("comment", "<strong>Good work</strong>");
  shadowId(document.getElementById("grade-form"));
  await submit("grade-form");
  await wait(
    () => document.querySelector(".grade-number")?.textContent === "91",
  );
  assert.equal(document.querySelector("td strong"), null);
  click("grade", 1);
  fill("grade", "95");
  shadowId(document.getElementById("grade-form"));
  await submit("grade-form");
  await wait(
    () => document.querySelector(".grade-number")?.textContent === "95",
  );
  await load("/ui/grades.html");
  assert.equal(document.querySelector(".grade-number").textContent, "95");
  await load("/ui/submissions.html");
  click("delete-grade", 1);
  click("confirm-grade", 1);
  await wait(
    () => records.grades.length === 0 && !document.querySelector("#modal").open,
  );
  click("logout");
  await load("/ui/register.html");
  fill("login", "new-student");
  fill("username", "New Student");
  fill("password", "StudentPass123!");
  await submit("register-form");
  await wait(() => nextHref);
  await load(nextHref);
  assert.ok(document.querySelector('[data-action="logout"]'));
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
