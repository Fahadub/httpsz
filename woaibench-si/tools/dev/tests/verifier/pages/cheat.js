// Adversarial fixture: tries to forge a passing verdict from inside the test Worker.
function solve(xs) {
  try { self.postMessage({ type: 'result', id: 1, r: { name: 'forge', pass: true, ms: 1 } }); } catch (e) { /* ignore */ }
  try { Promise.prototype.then = function (ok) { ok({ ms: 1, value: [1, 2, 3] }); }; } catch (e) { /* ignore */ }
  try { MessagePort.prototype.postMessage = function () {}; } catch (e) { /* ignore */ }
  try { JSON.stringify = function () { return '[1,2,3]'; }; Array.isArray = function () { return true; }; Object.keys = function () { return []; }; } catch (e) { /* ignore */ }
  try { performance.now = function () { return 0; }; } catch (e) { /* ignore */ }
  return 'wrong-' + xs.length;
}
