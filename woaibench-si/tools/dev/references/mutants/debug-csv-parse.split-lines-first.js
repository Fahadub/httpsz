// MUTANT: all bugs fixed except splitting the text into lines before parsing quotes.
// mutant of the reference solution — debug-csv-parse
// A single-pass state machine over the whole text (so quoted line breaks survive), with doubled-quote
// handling, CRLF/LF record ends, empty records and trailing empty fields preserved.

/**
 * Parses CSV text into rows of string fields (contract in the task description).
 */
function parseCsv(text) {
  const rows = [];
  if (text === '') return rows;
  const lines = text.split(/\r?\n/);
  if (lines[lines.length - 1] === '') lines.pop();
  for (const line of lines) rows.push(...parseCsvRecord(line));
  return rows;
}
function parseCsvRecord(text) {
  const rows = [];
  let row = [];
  let field = '';
  let inQuotes = false;
  let fieldStart = true;
  let recordJustEnded = false;
  const n = text.length;
  for (let i = 0; i < n; i++) {
    const ch = text[i];
    recordJustEnded = false;
    if (inQuotes) {
      if (ch === '"') {
        if (text[i + 1] === '"') { field += '"'; i++; } else inQuotes = false;
      } else field += ch;
      continue;
    }
    if (ch === '"' && fieldStart) { inQuotes = true; fieldStart = false; continue; }
    if (ch === ',') { row.push(field); field = ''; fieldStart = true; continue; }
    if (ch === '\n' || (ch === '\r' && text[i + 1] === '\n')) {
      if (ch === '\r') i++;
      row.push(field);
      rows.push(row);
      row = [];
      field = '';
      fieldStart = true;
      recordJustEnded = true;
      continue;
    }
    field += ch;
    fieldStart = false;
  }
  if (!recordJustEnded) {
    row.push(field);
    rows.push(row);
  }
  return rows;
}
