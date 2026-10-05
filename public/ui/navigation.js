export const pageNames = [
  "courses",
  "assignments",
  "submissions",
  "grades",
  "purchases",
  "login",
  "register",
  "submit",
  "checkout",
];

export function pageHref(page, params = {}) {
  if (!pageNames.includes(page)) throw new Error("Unknown page");
  const query = new URLSearchParams(params).toString();
  return `/ui/${page}.html${query ? "?" + query : ""}`;
}

export function returnTarget(search, origin) {
  const next = new URLSearchParams(search).get("next");
  if (!next) return pageHref("courses");
  let url;
  try {
    url = new URL(next, origin);
  } catch {
    return pageHref("courses");
  }
  if (
    url.origin !== origin ||
    !pageNames.some(
      (page) =>
        url.pathname === `/ui/${page}.html` &&
        !["login", "register"].includes(page),
    )
  )
    return pageHref("courses");
  return url.pathname + url.search;
}

export function loginHref(path) {
  return pageHref("login", { next: path });
}
