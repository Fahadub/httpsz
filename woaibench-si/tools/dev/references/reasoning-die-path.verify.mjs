// DEV ONLY — ground truth for reasoning-die-path, computed with face-normal vectors and rotation
// (independent of the face-swapping tables used when the puzzle was designed).
const faces = { 1: [0, 0, 1], 6: [0, 0, -1], 2: [0, 1, 0], 5: [0, -1, 0], 3: [1, 0, 0], 4: [-1, 0, 0] }; // x = east, y = north, z = up
// Rolling north rotates about the east axis so that the top normal (0,0,1) goes to north (0,1,0).
const rot = {
  N: ([x, y, z]) => [x, z, -y], S: ([x, y, z]) => [x, -z, y],
  E: ([x, y, z]) => [z, y, -x], W: ([x, y, z]) => [-z, y, x],
};
const face = (dir) => Number(Object.keys(faces).find((k) => faces[k].join() === dir.join()));
let sum = 0;
for (const step of 'N N E S E E N W W S E N N E'.split(' ')) {
  for (const k of Object.keys(faces)) faces[k] = rot[step](faces[k]);
  sum += face([0, 0, 1]);
}
console.log(`${sum},${face([0, 0, 1])},${face([0, 1, 0])}`);
