// MUTANT: first free room by index (first-fit) instead of best fit.
function allocateStudios(capacities, bookings, turnaround) {
  const freeAt = new Array(capacities.length).fill(-Infinity);
  const order = bookings.map((_, j) => j).sort((a, b) => bookings[a].start - bookings[b].start || a - b);
  const res = new Array(bookings.length).fill(-1);
  for (const j of order) {
    const b = bookings[j];
    let best = -1;
    for (let i = 0; i < capacities.length; i++) {
      if (freeAt[i] <= b.start && capacities[i] >= b.size) { best = i; break; }
    }
    if (best !== -1) { res[j] = best; freeAt[best] = b.end + turnaround; }
  }
  return res;
}
