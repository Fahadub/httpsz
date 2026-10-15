// WB-ORACLE reference solution — debug-async-pool
// Results are stored by index; a job that throws synchronously or rejects marks the pool as failed
// at once (no further launches); plain values are normalised with Promise.resolve; empty input
// resolves immediately; the limit is validated up front.

/**
 * Runs asynchronous jobs with a concurrency limit (contract in the task description).
 */
async function runPool(jobs, limit) {
  if (!Number.isInteger(limit) || limit < 1) throw new RangeError('limit must be a positive integer');
  const results = new Array(jobs.length);
  if (jobs.length === 0) return results;
  return new Promise((resolve, reject) => {
    let next = 0;
    let active = 0;
    let finished = 0;
    let failed = false;
    const fail = (err) => {
      if (failed) return;
      failed = true;
      reject(err);
    };
    const launch = () => {
      while (!failed && active < limit && next < jobs.length) {
        const i = next++;
        let pending;
        try {
          pending = Promise.resolve(jobs[i]());
        } catch (err) {
          fail(err);
          return;
        }
        active++;
        pending.then((value) => {
          active--;
          if (failed) return;
          results[i] = value;
          finished++;
          if (finished === jobs.length) resolve(results);
          else launch();
        }, (err) => {
          active--;
          fail(err);
        });
      }
    };
    launch();
  });
}
