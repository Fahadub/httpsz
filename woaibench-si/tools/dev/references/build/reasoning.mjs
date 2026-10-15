// DEV ONLY — builds sql/seed/tasks/reasoning.json.   node tools/dev/references/build/reasoning.mjs
// Every answer is produced by tools/dev/references/<slug>.verify.mjs (brute force / two independent
// methods) and stored in <slug>.answer.txt; this builder asserts all three agree before writing.
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { REF, writeTrack, assert } from './common.mjs';
import { checkAnswer } from '../../validate-tasks.mjs';

const verified = (slug) => {
  const out = spawnSync(process.execPath, [REF(slug + '.verify.mjs')], { encoding: 'utf8' });
  assert(out.status === 0, `${slug} verify failed: ${out.stderr}`);
  const v = out.stdout.trim().split('\n').pop();
  const a = readFileSync(REF(slug + '.answer.txt'), 'utf8').trim();
  assert(v === a, `${slug}: verify printed ${v}, answer.txt has ${a}`);
  return a;
};
const ANSWER_RULES = (format, example) => `## Answer format
Work it out as carefully as you need, then give the final answer exactly once, inside <answer></answer> tags: ${format}
Format illustration only (not the answer): \`${example}\``;

function task(slug, meta, prompt, answerSpec) {
  const ans = verified(slug);
  assert(checkAnswer(answerSpec, `<answer>${ans}</answer>`), `${slug}: spec rejects its own answer`);
  return {
    slug, track: 'reasoning', ...meta, prompt,
    output_type: 'answer', visibility: 'public', auto_weight: 1, rubric: [],
    checks: { answer: answerSpec },
  };
}

const program = readFileSync(REF('reasoning-elevator-trace.verify.mjs'), 'utf8').match(/export const PROGRAM = `([\s\S]*?)`;/)[1];

const tasks = [
  task('reasoning-elevator-trace', {
    title_en: 'Trace the Elevator Program', title_ar: 'تتبّع برنامج المصعد',
    summary_en: 'Predict the exact console output of a short elevator-scheduling program — loop termination, Set semantics and default sort order included.',
    summary_ar: 'توقّع المخرجات الحرفية لبرنامج قصير يجدول حركة مصعد، مع مراعاة شرط انتهاء الحلقة ودلالات Set وترتيب الفرز الافتراضي.',
    difficulty: 3,
  }, `# Trace the Elevator Program

What exactly does this JavaScript program print? Assume standard semantics of a modern browser or Node.js. Do not run it — trace it.

\`\`\`js
${program}
\`\`\`

${ANSWER_RULES('the single line printed by `console.log`, character for character.', '1>2 t=9 end=4 | 1,2')}`,
  { type: 'exact', value: readFileSync(REF('reasoning-elevator-trace.answer.txt'), 'utf8').trim() }),

  task('reasoning-judge-assignment', {
    title_en: 'Blind Judge Assignment Odds', title_ar: 'احتمالات توزيع المحكّمين المعمّى',
    summary_en: 'A conditional probability over random judge assignments with vendor conflicts — answer as an exact reduced fraction.',
    summary_ar: 'احتمال شرطي في توزيع عشوائي للمحكّمين مع تعارض الجهات المطوِّرة — والإجابة كسر دقيق في أبسط صورة.',
    difficulty: 3,
  }, `# Blind Judge Assignment Odds

A benchmark grades nine anonymised submissions with three judges. Four submissions come from models of vendor A, three from vendor B and two from vendor C (all nine submissions are distinct). Judge A is built by vendor A, judge B by vendor B and judge C by vendor C.

The submissions are dealt out uniformly at random so that **each judge receives exactly three submissions** (every such split is equally likely). A judge has a *conflict* when it receives a submission from its own vendor.

An auditor is told only that **judge C has no conflict**. Given this, what is the probability that **no judge** has a conflict?

${ANSWER_RULES('a fraction a/b in lowest terms, with no spaces and no other text.', '3/8')}`,
  { type: 'exact', value: readFileSync(REF('reasoning-judge-assignment.answer.txt'), 'utf8').trim() }),

  task('reasoning-ci-schedule', {
    title_en: 'Shortest CI Pipeline Schedule', title_ar: 'أقصر جدولة لخط التكامل المستمر',
    summary_en: 'Schedule nine dependent build jobs on two runners with an exclusive licence server and find the minimum total time.',
    summary_ar: 'جدولة تسع مهام بناء مترابطة على مُشغّلَين مع خادم ترخيص لا يتسع إلا لمهمة واحدة، وإيجاد أقل زمن إجمالي ممكن.',
    difficulty: 4,
  }, `# Shortest CI Pipeline Schedule

A release pipeline has nine jobs. Durations are in minutes.

| job | what it does | duration | may start only after |
|---|---|---|---|
| A | lint | 1 | — |
| B | generate API client | 2 | A |
| C | build web UI | 5 | — |
| D | build API server | 5 | A, B |
| E | UI tests | 3 | A, C |
| F | train ranking model | 6 | — |
| G | API contract tests | 2 | C, D |
| H | write model card | 1 | A, F |
| I | deploy preview | 4 | F, G |

Rules:
- There are **two identical runners**. Each job runs on one runner, without interruption, from start to finish; a runner runs one job at a time.
- A job may start only when all jobs in its "may start only after" column have **finished**.
- Jobs **D** and **F** both need the single build-licence server, so they can never run at the same time (one may start at the moment the other finishes).
- Runners may stay idle, and everything starts at time 0.

What is the **minimum** number of minutes after which all nine jobs can be finished?

${ANSWER_RULES('a single integer (minutes), with no units.', '42')}`,
  { type: 'number', value: Number(readFileSync(REF('reasoning-ci-schedule.answer.txt'), 'utf8')), tolerance: 0 }),

  task('reasoning-flaky-reruns', {
    title_en: 'Flaky-Test Rerun Estimate', title_ar: 'تقدير إعادات تشغيل الاختبارات المتقلّبة',
    summary_en: 'Expected number of random reruns until each of 8 tests has run twice — a numeric answer graded within a ±0.05 band.',
    summary_ar: 'العدد المتوقع لإعادات التشغيل العشوائية حتى يُشغَّل كل اختبار من ثمانية اختبارات مرتين على الأقل — إجابة رقمية تُقبل ضمن هامش ±0.05.',
    difficulty: 4,
  }, `# Flaky-Test Rerun Estimate

A flaky-test detector works on a suite of **8** distinct tests. In each round it picks one test uniformly at random (independently of earlier rounds, so the same test can be picked again) and runs it. It stops as soon as **every one of the 8 tests has been run at least twice**.

What is the **expected number of rounds** until it stops?

Give the value rounded to two decimal places. Answers within ±0.05 of the exact expectation are accepted.

${ANSWER_RULES('a decimal number only (no units, no "≈").', '12.34')}`,
  { type: 'number', value: Number(readFileSync(REF('reasoning-flaky-reruns.answer.txt'), 'utf8')), tolerance: 0.05 }),

  task('reasoning-die-path', {
    title_en: 'Rolling Die Path', title_ar: 'مسار النرد المتدحرج',
    summary_en: 'Track a die through fourteen rolls on a grid — sum of the top faces plus the final orientation.',
    summary_ar: 'تتبّع نرد عبر أربع عشرة دحرجة على شبكة — مجموع الأوجه العلوية مع الاتجاه النهائي للنرد.',
    difficulty: 3,
  }, `# Rolling Die Path

A die whose opposite faces sum to 7 sits on a square grid with **1 on top, 2 facing north and 3 facing east** (so 6 is at the bottom, 5 faces south and 4 faces west).

Rolling the die one cell **north** tips it over its bottom northern edge: the face that pointed north ends up at the bottom, the top face now points north, the south face comes to the top and the bottom face now points south. Rolls to the south, east and west work the same way in their directions.

The die makes these 14 rolls, in this order:

\`N, N, E, S, E, E, N, W, W, S, E, N, N, E\`

After **each** roll, write down the number on the top face (14 numbers in total).

Report three numbers: the **sum** of the 14 numbers you wrote down, the number on **top** after the last roll, and the number facing **north** after the last roll.

${ANSWER_RULES('three integers separated by commas, in the order sum,top,north.', '30,2,6')}`,
  { type: 'regex', pattern: '^\\s*' + readFileSync(REF('reasoning-die-path.answer.txt'), 'utf8').trim().split(',').join('\\s*,\\s*') + '\\s*$' }),
];
writeTrack('reasoning', tasks);
