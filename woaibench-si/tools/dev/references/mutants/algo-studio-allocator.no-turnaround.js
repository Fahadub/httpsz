// MUTANT: forgets the turnaround time.
function allocateStudios(capacities, bookings, turnaround) {
  const freeAt = new Array(capacities.length).fill(-Infinity);
  const order = bookings.map((_, j) => j).sort((a, b) => bookings[a].start - bookings[b].start || a - b);
  const res = new Array(bookings.length).fill(-1);
  for (const j of order) {
    const b = bookings[j];
    let best = -1;
    for (let i = 0; i < capacities.length; i++) {
      if (freeAt[i] <= b.start && capacities[i] >= b.size && (best === -1 || capacities[i] < capacities[best])) best = i;
    }
    if (best !== -1) { res[j] = best; freeAt[best] = b.end; }
  }
  return res;
}
