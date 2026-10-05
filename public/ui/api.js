const TOKEN_KEY = "campus.token";
let token = "";
try {
  token = sessionStorage.getItem(TOKEN_KEY) || "";
} catch {
  /* Storage can be disabled. */
}

export function setToken(value) {
  token = value || "";
  try {
    value
      ? sessionStorage.setItem(TOKEN_KEY, value)
      : sessionStorage.removeItem(TOKEN_KEY);
  } catch {
    /* Memory still works. */
  }
}

export function hasToken() {
  return Boolean(token);
}

export class ApiError extends Error {
  constructor(message, status = 0) {
    super(message);
    this.status = status;
  }
}

export async function request(
  path,
  { method = "GET", body, headers = {}, binary = false } = {},
) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 20000);
  const outgoing = { Accept: "application/json", ...headers };
  if (token) outgoing.Authorization = `Bearer ${token}`;
  if (body !== undefined && !(body instanceof FormData)) {
    outgoing["Content-Type"] =
      method === "PATCH" ? "application/merge-patch+json" : "application/json";
    body = JSON.stringify(body);
  }
  try {
    const response = await fetch(path, {
      method,
      headers: outgoing,
      body,
      signal: controller.signal,
      cache: "no-store",
    });
    if (!response.ok) {
      let problem;
      try {
        problem = await response.json();
      } catch {
        /* Proxy errors may not be JSON. */
      }
      throw new ApiError(
        problem?.detail ||
          problem?.message ||
          problem?.title ||
          `Request failed (${response.status}).`,
        response.status,
      );
    }
    if (binary) return response.blob();
    return response.status === 204 ? null : response.json();
  } catch (error) {
    if (error instanceof ApiError) throw error;
    throw new ApiError(
      error.name === "AbortError"
        ? "The request timed out. Refresh to check its outcome before retrying."
        : "Cannot connect. Check your connection and try again.",
    );
  } finally {
    clearTimeout(timeout);
  }
}

export async function collection(path) {
  const rows = [];
  for (let page = 1; ; page++) {
    const items = await request(
      `${path}${path.includes("?") ? "&" : "?"}page=${page}`,
    );
    if (!Array.isArray(items))
      throw new ApiError("Unexpected collection response.");
    rows.push(...items);
    if (items.length < 20) return rows;
  }
}

export async function download(path, filename) {
  const blob = await request(path, { binary: true });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
