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
    'Find events': 'Rechercher des événements',
    'Search title or status': 'Rechercher par titre ou statut',
    'Calendar synchronization': 'Synchronisation du calendrier',
    'Create a private, revocable subscription link. Add it by URL in your calendar app; updates are pulled automatically when that app refreshes. Changes made in the calendar app will not modify WordPress bookings.': 'Créez un lien d’abonnement privé et révocable. Ajoutez-le par URL dans votre application de calendrier : les événements seront actualisés lors des rafraîchissements de celle-ci. Les modifications effectuées dans cette application ne changeront pas les réservations WordPress.',
    'Treat the link as a password. Anyone holding it can read the selected event titles and dates. Only HTTPS sites can create links.': 'Traitez ce lien comme un mot de passe. Toute personne qui le possède peut consulter les titres et dates des événements sélectionnés. Un site HTTPS est nécessaire pour créer des liens.',
    'Subscription name (e.g. personal calendar)': 'Nom de l’abonnement (ex. calendrier personnel)',
    'My calendar': 'Mon calendrier',
    'Sources to synchronize': 'Sources à synchroniser',
    'Create subscription link': 'Créer un lien d’abonnement',
    'Revoke link': 'Révoquer le lien',
    'The full link is displayed only once after creation. If it is lost, revoke it and create a new one.': 'Le lien complet ne s’affiche qu’une fois après sa création. S’il est perdu, révoquez-le et créez-en un autre.',
    'You cannot manage calendar subscriptions.': 'Vous ne pouvez pas gérer les abonnements du calendrier.',
    'HTTPS is required for private calendar subscriptions.': 'Le protocole HTTPS est obligatoire pour les abonnements privés.',
    'Select at least one calendar source.': 'Sélectionnez au moins une source de calendrier.',
    'Maximum number of subscriptions reached. Revoke an old link first.': 'Nombre maximal d’abonnements atteint. Révoquez d’abord un ancien lien.',
    'Unable to generate a private link.': 'Impossible de générer un lien privé.',
    'Subscription not found.': 'Abonnement introuvable.',
    'Revoke this subscription? Connected calendars will stop updating.': 'Révoquer cet abonnement ? Les calendriers connectés cesseront de recevoir ses mises à jour.',
    'Subscription created. Copy the URL now; it will not be shown again.': 'Abonnement créé. Copiez l’URL maintenant : elle ne sera plus affichée.',
    'Copy link': 'Copier le lien',
    'Link copied.': 'Lien copié.',
    'Select and copy the link manually.': 'Sélectionnez et copiez le lien manuellement.',
    'Subscription revoked.': 'Abonnement révoqué.',
    'Unable to update the subscription.': 'Impossible de mettre à jour l’abonnement.',
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
          '"Project-Id-Version: Dendrila Calendar 0.0.1\\n"\n'
          '"Content-Type: text/plain; charset=UTF-8\\n"\n'
          '"Language: fr_FR\\n"\n'
          '"Plural-Forms: nplurals=2; plural=n>1;\\n"\n')
pot = ('msgid ""\nmsgstr ""\n'
       '"Project-Id-Version: Dendrila Calendar 0.0.1\\n"\n'
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
    "    'project-id-version' => 'Dendrila Calendar 0.0.1',",
    "    'language' => 'fr_FR',",
    "    'plural-forms' => 'nplurals=2; plural=n>1;',",
    "    'messages' => array(",
]
for source in sorted(strings):
    lines.append('        ' + php_quote(source) + ' => ' + php_quote(entries[source]) + ',')
lines.extend(['    ),', ');', ''])
(language_dir / 'dendrila-calendar-fr_FR.l10n.php').write_text('\n'.join(lines), encoding='utf-8')
print(f'{len(strings)} translatable messages, {len(entries)} French translations, no missing keys.')
