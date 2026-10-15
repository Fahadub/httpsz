# DEV ONLY — derive a mutant from a reference by exact string replacements (each must apply once).
import sys, json
def mutate(src_path, out_path, header, pairs):
    s = open(src_path).read()
    s = s.replace('// WB-ORACLE reference solution', '// mutant of the reference solution')
    for old, new in pairs:
        if s.count(old) != 1:
            raise SystemExit(f'{out_path}: pattern found {s.count(old)} times: {old[:80]!r}')
        s = s.replace(old, new)
    open(out_path, 'w').write('// MUTANT: ' + header + '\n' + s)
    print('wrote', out_path)
if __name__ == '__main__':
    spec = json.load(open(sys.argv[1]))
    for m in spec:
        mutate(m['src'], m['out'], m['header'], m['pairs'])
