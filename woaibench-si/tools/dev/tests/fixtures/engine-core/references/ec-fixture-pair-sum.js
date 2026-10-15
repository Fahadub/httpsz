// WB-ORACLE reference solution — ec-fixture-pair-sum (engine-core test fixture)
function pairSum(nums, target) {
  const seen = new Map();
  let count = 0;
  for (const x of nums) {
    count += seen.get(target - x) || 0;
    seen.set(x, (seen.get(x) || 0) + 1);
  }
  return count;
}
