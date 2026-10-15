// MUTANT: computes pair counts with 32-bit integer arithmetic (overflows above 2^31).
// mutant of the reference solution — algo-rank-agreement
// Knight's O(n log n) algorithm: sort by (x, y), count tie groups, count discordant pairs as the
// number of strict inversions of y (merge sort), derive concordant pairs by subtraction.

function rankAgreement(x, y) {
  if (!Array.isArray(x) || !Array.isArray(y) || x.length !== y.length) {
    throw new RangeError('x and y must be arrays of the same length');
  }
  const n = x.length;
  const order = Array.from({ length: n }, (_, i) => i).sort((i, j) => x[i] - x[j] || y[i] - y[j]);
  const ys = new Float64Array(n);
  for (let i = 0; i < n; i++) ys[i] = y[order[i]];

  const pairs = (t) => ((t * (t - 1)) / 2) | 0;
  let tiedX = 0; // pairs tied on x (including those tied on both)
  let tiedBoth = 0;
  for (let i = 0; i < n;) {
    let j = i;
    while (j < n && x[order[j]] === x[order[i]]) j++;
    tiedX += pairs(j - i);
    for (let k = i; k < j;) {
      let l = k;
      while (l < j && ys[l] === ys[k]) l++;
      tiedBoth += pairs(l - k);
      k = l;
    }
    i = j;
  }

  const discordant = countInversions(ys);
  const sortedY = ys; // countInversions sorts in place
  let tiedY = 0;
  for (let i = 0; i < n;) {
    let j = i;
    while (j < n && sortedY[j] === sortedY[i]) j++;
    tiedY += pairs(j - i);
    i = j;
  }

  const total = pairs(n);
  const concordant = total - tiedX - tiedY + tiedBoth - discordant;
  const onlyX = tiedX - tiedBoth;
  const onlyY = tiedY - tiedBoth;
  const denom = (concordant + discordant + onlyY) * (concordant + discordant + onlyX);
  return {
    concordant,
    discordant,
    tiesX: onlyX,
    tiesY: onlyY,
    tiesBoth: tiedBoth,
    tauB: denom === 0 ? null : (concordant - discordant) / Math.sqrt(denom),
  };
}

/** Bottom-up merge sort of `arr` (in place); returns the number of pairs i < j with arr[i] > arr[j]. */
function countInversions(arr) {
  const n = arr.length;
  let src = arr;
  let dst = new Float64Array(n);
  let inv = 0;
  for (let width = 1; width < n; width *= 2) {
    for (let lo = 0; lo < n; lo += 2 * width) {
      const mid = Math.min(lo + width, n);
      const hi = Math.min(lo + 2 * width, n);
      let i = lo;
      let j = mid;
      let k = lo;
      while (i < mid && j < hi) {
        if (src[j] < src[i]) { inv += mid - i; dst[k++] = src[j++]; } else dst[k++] = src[i++];
      }
      while (i < mid) dst[k++] = src[i++];
      while (j < hi) dst[k++] = src[j++];
    }
    [src, dst] = [dst, src];
  }
  if (src !== arr) arr.set(src);
  return inv;
}
