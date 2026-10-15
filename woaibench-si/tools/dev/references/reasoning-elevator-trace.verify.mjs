// DEV ONLY — ground truth for reasoning-elevator-trace: runs the exact program from the prompt in a
// fresh VM context with console.log captured, so the answer is what JavaScript really prints.
import vm from 'node:vm';
export const PROGRAM = `function elevator(requests, start) {
  let floor = start, dir = -1, time = 0;
  const pending = new Set(requests);
  const served = [];
  while (pending.size > 0) {
    if (pending.has(floor)) {
      pending.delete(floor);
      served.push(floor);
      time += 3;
    }
    const ahead = [...pending].some((f) => (f - floor) * dir > 0);
    if (!ahead) dir = -dir;
    floor += dir;
    time += 1;
  }
  return served.join('>') + ' t=' + time + ' end=' + floor;
}

const report = elevator([12, 3, 7, 10, 3, 0, 9], 5);
const labels = report.split(' ')[0].split('>').sort();
console.log(report + ' | ' + labels.join(','));`;
const lines = [];
vm.runInContext(PROGRAM, vm.createContext({ console: { log: (...a) => lines.push(a.join(' ')) } }), { timeout: 5000 });
if (lines.length !== 1) throw new Error('expected exactly one printed line');
console.log(lines[0]);
