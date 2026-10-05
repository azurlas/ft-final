export function submissionBody(form) {
  const files = form.querySelector('input[type="file"]').files;
  if (!files || files.length !== 1) throw new Error("Choose exactly one file.");
  const file = files[0];
  if (file.size > 10000000)
    throw new Error("Your file exceeds the 10 MB limit (10,000,000 bytes).");
  const body = new FormData();
  body.append("file", file, file.name);
  return body;
}
