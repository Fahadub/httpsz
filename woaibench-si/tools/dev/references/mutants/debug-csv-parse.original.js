// MUTANT: the original buggy code shown in the prompt.
/**
 * Parses CSV text into an array of records, each an array of string fields.
 *
 * - Fields are separated by commas; records end with LF ("\n") or CRLF ("\r\n").
 *   A CR that is not followed by LF is an ordinary character.
 * - A field whose first character is a double quote is quoted: it ends at the next quote that is
 *   not doubled. Inside it, commas, CR and LF are literal and "" stands for one ". Characters after
 *   the closing quote, up to the next comma or record end, are appended to the field as they are.
 *   An unterminated quoted field extends to the end of the text.
 * - A quote anywhere else in an unquoted field is an ordinary character.
 * - Every record has at least one field: an empty line is a record with one empty field, [""].
 * - A line break at the very end of the text does not start another record; "" gives [].
 * Input can be several megabytes; parsing must take linear time.
 */
function parseCsv(text) {
  const rows = [];
  for (const line of text.split('\n')) {
    if (line === '') continue;
    const row = [];
    let field = '';
    let inQuotes = false;
    for (let i = 0; i < line.length; i++) {
      const ch = line[i];
      if (inQuotes) {
        if (ch === '"') inQuotes = false;
        else field += ch;
      } else if (ch === '"') {
        inQuotes = true;
      } else if (ch === ',') {
        row.push(field);
        field = '';
      } else {
        field += ch;
      }
    }
    if (field !== '') row.push(field);
    rows.push(row);
  }
  return rows;
}
