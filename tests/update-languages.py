#!/usr/bin/env python3
"""Regenerate POT, French PO and WordPress 6.5+ PHP translations from checked-in PHP sources."""
from pathlib import Path
import re
import json

root = Path(__file__).resolve().parents[1]
language_dir = root / 'languages'
old_po = (language_dir / 'dendrila-calendar-fr_FR.po').read_text(encoding='utf-8')
entries = dict(re.findall(r'^msgid "([^"\\]*(?:\\.[^"\\]*)*)"\nmsgstr "([^"\\]*(?:\\.[^"\\]*)*)"$', old_po, re.M))
entries = {json.loads('"'+k+'"'):json.loads('"'+v+'"') for k,v in entries.items() if k}
entries.update({
    'Select all': 'Tout sélectionner',
    'Select none': 'Tout désélectionner',
    'Appointment': 'Rendez-vous',
    'Event': 'Événement',
    'Reserved day': 'Journée réservée',
    'Approved': 'Approuvé',
    'Pending': 'En attente',
    'Amelia': 'Amelia',
    'Booking Calendar': 'Booking Calendar',
    'Bookly': 'Bookly',
    'Easy Appointments': 'Easy Appointments',
    'Event Organiser': 'Event Organiser',
    'Events Manager': 'Events Manager',
    'FluentBooking': 'FluentBooking',
    'Simply Schedule Appointments': 'Simply Schedule Appointments',
})
source = list(root.glob('*.php')) + list((root / 'includes').glob('*.php'))
strings = set()
pattern = re.compile(r"(?:__|esc_html__|esc_attr__)\s*\(\s*'((?:\\'|[^'])*)'\s*,\s*'dendrila-calendar'")
for p in source:
    strings.update(x.replace("\\'", "'") for x in pattern.findall(p.read_text(encoding='utf-8')))
missing = sorted(x for x in strings if x not in entries)
if missing:
    raise RuntimeError('Untranslated strings: ' + ', '.join(missing))

header = ('msgid ""\nmsgstr ""\n'
          '"Project-Id-Version: Dendrila Calendar 0.2.0-dev\\n"\n'
          '"Content-Type: text/plain; charset=UTF-8\\n"\n'
          '"Language: fr_FR\\n"\n'
          '"Plural-Forms: nplurals=2; plural=n>1;\\n"\n')
pot = ('msgid ""\nmsgstr ""\n'
       '"Project-Id-Version: Dendrila Calendar 0.2.0-dev\\n"\n'
       '"Content-Type: text/plain; charset=UTF-8\\n"\n')
for string in sorted(strings):
    key = json.dumps(string, ensure_ascii=False)
    header += '\nmsgid ' + key + '\nmsgstr ' + json.dumps(entries[string], ensure_ascii=False) + '\n'
    pot += '\nmsgid ' + key + '\nmsgstr ""\n'
(language_dir / 'dendrila-calendar-fr_FR.po').write_text(header, encoding='utf-8')
(language_dir / 'dendrila-calendar.pot').write_text(pot, encoding='utf-8')

def php_quote(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"
lines = [
    '<?php',
    '/** French translations for Dendrila Calendar (WordPress 6.5+). */',
    "if ( ! defined( 'ABSPATH' ) ) { exit; }",
    'return array(',
    "    'project-id-version' => 'Dendrila Calendar 0.2.0-dev',",
    "    'language' => 'fr_FR',",
    "    'plural-forms' => 'nplurals=2; plural=n>1;',",
    "    'messages' => array(",
]
for source in sorted(strings):
    lines.append('        ' + php_quote(source) + ' => ' + php_quote(entries[source]) + ',')
lines.extend(['    ),', ');', ''])
(language_dir / 'dendrila-calendar-fr_FR.l10n.php').write_text('\n'.join(lines), encoding='utf-8')
print(f'{len(strings)} translatable messages, {len(entries)} French translations, no missing keys.')
