// MUTANT: the original buggy code shown in the prompt.
/**
 * Runs asynchronous jobs with a concurrency limit.
 *
 * jobs  — array of functions (up to 50 000); each returns a promise or a plain value, and may throw.
 * limit — maximum number of jobs running at the same time; must be a positive integer.
 *
 * Jobs are started in array order, and a new job starts as soon as a running one finishes.
 * Resolves to the array of results in the same order as `jobs` ([] for no jobs).
 * If a job throws or its promise rejects, the returned promise rejects with that same error and
 * no further jobs are started (jobs already running are left alone; their outcomes are ignored).
 * A job that throws synchronously fails at that moment, before any later job is started.
 * If `limit` is not a positive integer, the returned promise rejects with a RangeError.
 */
async function runPool(jobs, limit) {
  const results = [];
  let next = 0;
  let active = 0;
  return new Promise((resolve, reject) => {
    const launch = () => {
      while (active <= limit && next < jobs.length) {
        const job = jobs[next++];
        active++;
        job().then((value) => {
          results.push(value);
          active--;
          if (results.length === jobs.length) resolve(results);
          else launch();
        }, reject);
      }
    };
    launch();
  });
}
