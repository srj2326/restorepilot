"""
Writes the split-up plugin from the single file.

Method bodies are copied verbatim -- not reformatted, not reindented, not
touched. The only new text is the file headers and the trait/class scaffolding
around them, so the refactor cannot quietly change behaviour.
"""

import os, re, sys, json
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from split_tool import load, find_class, leading_comment, method_end, SIG
from trait_map import METHOD_TRAIT, TRAIT_TITLES, MAP

SRC = sys.argv[1]
OUT = sys.argv[2]

lines = load(SRC)
cls_start, cls_end = find_class(lines)

# --- carve the file into its existing top-level pieces -----------------------
def top_class_line(prefix):
    return next(i for i, l in enumerate(lines) if l.startswith(prefix))

zip_w = top_class_line('final class RestorePilot_Backup_Zip_Writer')
vol_w = top_class_line('final class RestorePilot_Backup_Volume_Writer')
arch  = top_class_line('final class RestorePilot_Backup_Archive')
exc   = next(i for i, l in enumerate(lines) if l.startswith('class RestorePilot_Backup_Cancelled_Exception'))

def block_end(start):
    """End of a top-level class: the next line that is exactly '}'."""
    for n in range(start, len(lines)):
        if lines[n] == '}':
            return n
    raise RuntimeError('no close for block at %d' % start)

# Header/bootstrap is everything before the first exception class.
header      = lines[:exc]
exceptions  = lines[exc:zip_w]
zip_block   = lines[zip_w:block_end(zip_w) + 1]
vol_block   = lines[vol_w:block_end(vol_w) + 1]
arch_block  = lines[arch:block_end(arch) + 1]

# --- pull the class apart ----------------------------------------------------
sigs = [(m.group(1), i) for i, l in enumerate(lines)
        if cls_start < i < cls_end and (m := SIG.match(l))]
units = [(n, leading_comment(lines, i), method_end(lines, i)) for n, i in sigs]

covered = set()
for _, a, b in units:
    covered.update(range(a, b + 1))
# Class preamble: the docblock, `final class ... {`, consts and static props.
preamble = [lines[i] for i in range(cls_start, cls_end) if i not in covered]

os.makedirs(os.path.join(OUT, 'includes'), exist_ok=True)

BANNER = ("<?php\n"
          "/**\n"
          " * %s\n"
          " *\n"
          " * @package RestorePilot_Backup_Migration\n"
          " */\n"
          "\n"
          "if (!defined('ABSPATH')) {\n"
          "  exit;\n"
          "}\n\n")

def write(path, text):
    with open(os.path.join(OUT, path), 'w', encoding='utf-8') as fh:
        fh.write(text)

# --- helper classes into their own files ------------------------------------
write('includes/exceptions.php',
      BANNER % 'Exceptions used to unwind a cancelled or time-limited chunk.'
      + '\n'.join(exceptions).strip() + '\n')

write('includes/class-backup-zip-writer.php',
      BANNER % 'Writes a backup archive, one entry at a time.'
      + '\n'.join(zip_block).strip() + '\n')

write('includes/class-backup-volume-writer.php',
      BANNER % 'Splits an archive across volumes so no single file grows too large.'
      + '\n'.join(vol_block).strip() + '\n')

write('includes/class-backup-archive.php',
      BANNER % 'Reads a backup archive, transparently spanning its volumes.'
      + '\n'.join(arch_block).strip() + '\n')

# --- traits ------------------------------------------------------------------
by_trait = {t: [] for t in MAP}
for name, a, b in units:
    by_trait[METHOD_TRAIT[name]].append((a, b))

trait_names = {}
for trait, spans in by_trait.items():
    spans.sort()
    php_name = 'RestorePilot_' + ''.join(p.capitalize() for p in trait.split('-'))
    trait_names[trait] = php_name
    body = []
    for a, b in spans:
        body.extend(lines[a:b + 1])
        body.append('')
    while body and body[-1] == '':
        body.pop()
    write('includes/trait-%s.php' % trait,
          BANNER % TRAIT_TITLES[trait]
          + 'trait %s {\n' % php_name
          + '\n'.join(body) + '\n}\n')

# --- the class itself --------------------------------------------------------
uses = '\n'.join('  use %s;' % trait_names[t] for t in MAP)
pre = '\n'.join(preamble).rstrip()
# Slot the `use` statements in right after the opening brace of the class.
open_brace = pre.index('{') + 1
class_text = (BANNER % 'The plugin\'s single entry-point class; its behaviour lives in the traits it uses.'
              + pre[:open_brace]
              + '\n' + uses + '\n'
              + pre[open_brace:] + '\n}\n')
write('includes/class-restorepilot-backup-migration.php', class_text)

# --- the root bootstrap file -------------------------------------------------
requires = [
  'exceptions.php',
  'class-backup-zip-writer.php',
  'class-backup-volume-writer.php',
  'class-backup-archive.php',
] + ['trait-%s.php' % t for t in MAP] + [
  'class-restorepilot-backup-migration.php',
]
req_text = '\n'.join(
  "require_once __DIR__ . '/includes/%s';" % f for f in requires)

hdr = '\n'.join(header).rstrip()
# The header currently ends with hook registrations that reference the class.
# Requires have to come first so the traits are loaded before the class is.
marker = "if (!defined('ABSPATH')) {"
idx = hdr.index(marker)
guard_end = hdr.index('}', idx) + 1
bootstrap = (hdr[:guard_end]
             + "\n\n// Behaviour lives in includes/: the helper classes, and the traits the\n"
               "// main class is assembled from. Loaded before the hooks below, which name\n"
               "// its methods.\n"
             + req_text + '\n'
             + hdr[guard_end:] + '\n')
write('restorepilot-backup-migration.php', bootstrap)

print('wrote %d files' % (len(requires) + 1))
print('traits: %d, methods moved: %d' % (len(MAP), len(units)))
