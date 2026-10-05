export const escape = (value) =>
  String(value ?? "").replace(
    /[&<>"']/g,
    (char) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        char
      ],
  );
export const money = (cents) =>
  cents == null || cents === 0
    ? "Free"
    : new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: "USD",
      }).format(cents / 100);
export const date = (value) =>
  value
    ? new Intl.DateTimeFormat(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(new Date(value))
    : "—";
export const terminal = (status) =>
  ["succeeded", "failed", "declined"].includes(status);
// getRandomValues also works on local HTTP origins where randomUUID is unavailable.
export const requestKey = () =>
  "campus-" +
  Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) =>
    byte.toString(16).padStart(2, "0"),
  ).join("");
export const localDateInput = (value) => {
  const d = new Date(value);
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
};
export const bytes = (size) =>
  size >= 1000000
    ? `${(size / 1000000).toFixed(2)} MB`
    : `${Math.max(1, Math.ceil(size / 1000))} KB`;
