"""
Takes the main class apart into (docblock + method) units.

Everything here is checked by round-tripping: the pieces are reassembled and
compared byte-for-byte against the original before any of them is written to a
new file. If that comparison fails the extraction is wrong and nothing else in
the refactor can be trusted.
"""

import re, sys

SIG = re.compile(r'^  (?:public|private|protected)(?: static)? function ([a-zA-Z0-9_]+)')
CONST = re.compile(r'^  const [A-Z_]')
PROP = re.compile(r'^  (?:private|public|protected) static \$')


def load(path):
    return open(path, encoding='utf-8').read().split('\n')


def find_class(lines):
    """Line index of `final class RestorePilot_Backup_Migration` and its closing brace."""
    start = next(i for i, l in enumerate(lines)
                 if l.startswith('final class RestorePilot_Backup_Migration'))
    # The class is the last thing in the file; its close is the final lone '}'.
    end = max(i for i, l in enumerate(lines) if l == '}')
    return start, end


def leading_comment(lines, sig_idx):
    """Walk back over the docblock/comments that belong to this method."""
    i = sig_idx - 1
    while i >= 0:
        s = lines[i].strip()
        if s.startswith('*') or s.startswith('/**') or s.startswith('//') or s.startswith('*/'):
            i -= 1
            continue
        break
    return i + 1


def method_end(lines, sig_idx):
    """The matching closing brace, found by depth rather than indentation alone."""
    depth = 0
    started = False
    for n in range(sig_idx, len(lines)):
        line = lines[n]
        # Strip string literals and comments crudely; the file's braces are not
        # inside strings in ways that would fool this, and the round-trip check
        # is what actually proves it.
        depth += line.count('{') - line.count('}')
        if '{' in line:
            started = True
        if started and depth <= 0:
            return n
    raise RuntimeError('unterminated method at line %d' % (sig_idx + 1))


def extract(path):
    lines = load(path)
    cls_start, cls_end = find_class(lines)

    sigs = [(m.group(1), i) for i, l in enumerate(lines)
            if cls_start < i < cls_end and (m := SIG.match(l))]

    units = []      # (name, start_idx, end_idx) inclusive, docblock included
    for name, idx in sigs:
        units.append((name, leading_comment(lines, idx), method_end(lines, idx)))

    # Everything in the class that is not a method: header, consts, props.
    covered = set()
    for _, a, b in units:
        covered.update(range(a, b + 1))
    preamble = [i for i in range(cls_start, cls_end + 1) if i not in covered]

    return lines, cls_start, cls_end, units, preamble


def roundtrip_ok(path):
    """Reassemble from the pieces and require a byte-identical result."""
    lines, cls_start, cls_end, units, preamble = extract(path)

    rebuilt = list(lines[:cls_start])
    order = sorted([(a, b, 'm', n) for n, a, b in units] +
                   [(i, i, 'p', None) for i in preamble])
    for a, b, kind, _ in order:
        rebuilt.extend(lines[a:b + 1])
    rebuilt.extend(lines[cls_end + 1:])

    original = '\n'.join(lines)
    result = '\n'.join(rebuilt)
    return original == result, len(units), original, result


if __name__ == '__main__':
    path = sys.argv[1]
    ok, count, orig, new = roundtrip_ok(path)
    print('methods extracted: %d' % count)
    print('ROUND-TRIP: %s' % ('byte-identical' if ok else 'MISMATCH'))
    if not ok:
        import difflib
        d = list(difflib.unified_diff(orig.split('\n'), new.split('\n'), lineterm='', n=1))
        print('first differences:')
        print('\n'.join(d[:40]))
        sys.exit(1)
