import { test, afterEach } from "node:test";
import assert from "node:assert/strict";
import {
  request,
  collection,
  setToken,
  ApiError,
} from "../../public/ui/api.js";
import { escape, money, terminal, requestKey } from "../../public/ui/format.js";

afterEach(() => setToken(""));

test("API client carries authentication, patch content type, and idempotency without retrying writes", async () => {
  let calls = 0;
  setToken("test-token");
  global.fetch = async (path, options) => {
    calls++;
    assert.equal(path, "/api/courses/2");
    assert.equal(options.headers.Authorization, "Bearer test-token");
    assert.equal(
      options.headers["Content-Type"],
      "application/merge-patch+json",
    );
    assert.equal(options.headers["Idempotency-Key"], "same-request");
    assert.deepEqual(JSON.parse(options.body), { price: null });
    return new Response(
      JSON.stringify({ detail: "Course has an active purchase" }),
      { status: 409 },
    );
  };
  await assert.rejects(
    request("/api/courses/2", {
      method: "PATCH",
      body: { price: null },
      headers: { "Idempotency-Key": "same-request" },
    }),
    (error) =>
      error instanceof ApiError &&
      error.status === 409 &&
      error.message.includes("active purchase"),
  );
  assert.equal(calls, 1);
});

test("collections fetch every 20-item page, including an empty last page", async () => {
  const paths = [];
  global.fetch = async (path) => {
    paths.push(path);
    return new Response(
      JSON.stringify(
        path.endsWith("page=1")
          ? Array.from({ length: 20 }, (_, id) => ({ id }))
          : [],
      ),
    );
  };
  assert.equal((await collection("/api/purchases")).length, 20);
  assert.deepEqual(paths, ["/api/purchases?page=1", "/api/purchases?page=2"]);
});

test("uploads preserve browser multipart boundaries and send one file only", async () => {
  const body = new FormData();
  body.append("file", new Blob(["content"]), "work.any");
  global.fetch = async (path, options) => {
    assert.equal(options.body, body);
    assert.equal(options.headers["Content-Type"], undefined);
    assert.deepEqual([...options.body.keys()], ["file"]);
    return new Response("{}", { status: 201 });
  };
  await request("/api/assignments/1/submit", { method: "POST", body });
});

test("safe output and HTTP-compatible request keys", () => {
  assert.equal(
    escape('<img src=x onerror="bad">'),
    "&lt;img src=x onerror=&quot;bad&quot;&gt;",
  );
  assert.equal(money(null), "Free");
  assert.equal(money(1250), "$12.50");
  assert.equal(terminal("retrying"), false);
  assert.equal(terminal("succeeded"), true);
  const keys = new Set(Array.from({ length: 50 }, requestKey));
  assert.equal(keys.size, 50);
  assert.ok([...keys].every((key) => /^campus-[a-f0-9]{32}$/.test(key)));
});
