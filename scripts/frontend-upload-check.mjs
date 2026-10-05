#!/usr/bin/env node
// Exercise the deployed page's real JS submit handler using jsdom and live HTTPS.
// jsdom needs a file-picker/validity adapter; this does not verify browser rendering.
import assert from "node:assert/strict";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { createHash, randomUUID } from "node:crypto";
import { JSDOM } from "jsdom";

const base = process.argv[2] || "https://midterm.k8s.orb.local";
const nativeFetch = global.fetch;
const NativeFormData = global.FormData;
const tag = randomUUID().slice(0, 8);
let dom, instance, adminToken, course, assignment;
async function api(path, { token, body, method = "GET" } = {}) {
  const response = await nativeFetch(base + path, {
    method,
    headers: {
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(body ? { "Content-Type": "application/json" } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  assert.ok(
    response.ok,
    `${method} ${path}: ${response.status} ${response.ok ? "" : await response.text()}`,
  );
  return response.status === 204 ? null : response.json();
}
async function wait(predicate) {
  for (let i = 0; i < 500; i++) {
    if (predicate()) return;
    await new Promise((resolve) => setTimeout(resolve, 50));
  }
  assert.fail(
    "UI did not finish submission: " +
      dom.window.document.querySelector("#main").textContent,
  );
}
try {
  const servedSources = [
    "submit.html",
    "main.js",
    "app.js",
    "api.js",
    "format.js",
    "navigation.js",
    "submission.js",
  ];
  for (const name of servedSources) {
    const response = await nativeFetch(base + "/ui/" + name);
    assert.equal(response.status, 200);
    assert.equal(
      await response.text(),
      await readFile(new URL("../public/ui/" + name, import.meta.url), "utf8"),
      `${name}: deployed code differs from tested code`,
    );
  }
  adminToken = (
    await api("/api/login", {
      method: "POST",
      body: { login: "admin", password: "AdminPass123!" },
    })
  ).token;
  course = await api("/api/courses", {
    token: adminToken,
    method: "POST",
    body: { name: "UI upload check " + tag, price: null },
  });
  assignment = await api("/api/assignments", {
    token: adminToken,
    method: "POST",
    body: {
      courseId: course.id,
      name: "One-file UI check",
      description: "Exact upload limit",
      deadline: new Date(Date.now() + 86400000).toISOString(),
    },
  });
  const login = "upload-ui-" + tag;
  await api("/api/register", {
    method: "POST",
    body: { login, username: "UI Upload Student", password: "StudentPass123!" },
  });
  const studentToken = (
    await api("/api/login", {
      method: "POST",
      body: { login, password: "StudentPass123!" },
    })
  ).token;
  await api(`/api/courses/${course.id}/enroll`, {
    token: studentToken,
    method: "POST",
  });
  const path = `/ui/submit.html?assignment=${assignment.id}`;
  const html = await (await nativeFetch(base + path)).text();
  dom = new JSDOM(html, { url: base + path, pretendToBeVisual: true });
  global.document = dom.window.document;
  global.sessionStorage = dom.window.sessionStorage;
  global.FormData = dom.window.FormData;
  sessionStorage.setItem("campus.token", studentToken);
  let nextHref,
    uploads = 0;
  global.fetch = async (url, options = {}) => {
    if (options.body instanceof dom.window.FormData) {
      const outgoing = new NativeFormData();
      assert.deepEqual([...options.body.keys()], ["file"]);
      assert.equal(options.headers["Content-Type"], undefined);
      for (const [name, value] of options.body) {
        const bytes = await new Promise((resolve, reject) => {
          const reader = new dom.window.FileReader();
          reader.onload = () => resolve(reader.result);
          reader.onerror = () => reject(reader.error);
          reader.readAsArrayBuffer(value);
        });
        outgoing.append(
          name,
          new Blob([bytes], { type: value.type }),
          value.name,
        );
      }
      options = { ...options, body: outgoing };
      uploads++;
    }
    return nativeFetch(new URL(url, base), options);
  };
  const { mount } = await import("../public/ui/app.js");
  instance = mount({
    document,
    navigate: (href) => {
      nextHref = href;
    },
    schedule: () => 0,
  });
  await instance.ready;
  const form = document.getElementById("upload-form");
  assert.ok(form, "Enrolled student must have an upload form");
  assert.equal(form.querySelector('[name="id"]'), null);
  // Simulate browser named-property shadowing, absent from jsdom itself.
  Object.defineProperty(form, "id", {
    value: form.querySelector('[name="entityId"]'),
  });
  form.reportValidity = () => true;
  const input = form.querySelector('[type="file"]');
  const choose = (file) =>
    Object.defineProperty(input, "files", {
      configurable: true,
      value: [file],
    });
  const submit = () =>
    form.dispatchEvent(
      new dom.window.Event("submit", { bubbles: true, cancelable: true }),
    );
  choose(new dom.window.File([new Uint8Array(10000001)], "oversize.any"));
  submit();
  await wait(() => !form.querySelector(".form-error").hidden);
  assert.match(form.querySelector(".form-error").textContent, /10,000,000/);
  assert.equal(uploads, 0);
  const content = new Uint8Array(10000000).fill(37);
  const checksum = createHash("sha256").update(content).digest("hex");
  choose(
    new dom.window.File([content], "ui-limit.arbitrary", {
      type: "application/octet-stream",
    }),
  );
  submit();
  await wait(() => nextHref || !form.querySelector(".form-error").hidden);
  assert.equal(
    nextHref,
    "/ui/submissions.html",
    form.querySelector(".form-error").textContent,
  );
  assert.equal(uploads, 1);
  const records = await api("/api/submissions", { token: studentToken });
  const record = records.find((s) => s.assignmentId === assignment.id);
  assert.equal(record.sizeBytes, 10000000);
  assert.equal(record.originalFilename, "ui-limit.arbitrary");
  assert.equal(record.sha256, checksum);
  const response = await nativeFetch(
    base + `/api/submissions/${record.id}/download`,
    { headers: { Authorization: `Bearer ${studentToken}` } },
  );
  assert.equal(response.status, 200);
  assert.equal(
    createHash("sha256")
      .update(new Uint8Array(await response.arrayBuffer()))
      .digest("hex"),
    checksum,
  );
  instance.destroy();
  instance = mount({
    document,
    navigate: (href) => {
      nextHref = href;
    },
    schedule: () => 0,
  });
  await instance.ready;
  assert.equal(document.getElementById("upload-form"), null);
  assert.match(
    document.querySelector("#main").textContent,
    /Already submitted/,
  );
  const report = {
    checkedAt: new Date().toISOString(),
    base,
    deployedSourcesMatched: servedSources,
    domHandler: "passed",
    browserRenderingVerified: false,
    namedFormIdShadowRegression: true,
    oversizedFileBlockedBeforeRequest: true,
    uploadedBytes: record.sizeBytes,
    multipartFileParts: 1,
    filename: record.originalFilename,
    submissionId: record.id,
    sha256: checksum,
    privateDownloadChecksumVerified: true,
    successNavigation: nextHref,
    duplicateFormHiddenAfterReload: true,
  };
  await mkdir("output/evaluation", { recursive: true });
  await writeFile(
    "output/evaluation/frontend-upload.json",
    JSON.stringify(report, null, 2) + "\n",
  );
  console.log(JSON.stringify(report, null, 2));
} finally {
  instance?.destroy();
  dom?.window.close();
  if (assignment)
    await api(`/api/assignments/${assignment.id}`, {
      method: "DELETE",
      token: adminToken,
    });
  if (course)
    await api(`/api/courses/${course.id}`, {
      method: "DELETE",
      token: adminToken,
    });
}
