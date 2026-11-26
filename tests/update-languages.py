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
    'Continue': 'Continuer',
    'Back': 'Retour',
    'The calendar service could not be reached securely.': 'La connexion sécurisée au service de calendrier a échoué.',
    'Select at least one appointment source.': 'Sélectionnez au moins une source de rendez-vous.',
    'We will add and remove a temporary event to test the connection.': 'Un événement temporaire sera ajouté puis supprimé pour vérifier la connexion.',
    'The calendar write test failed. Check the application password and make sure you can add and delete events in this calendar.': 'Le test d’écriture a échoué. Vérifiez le mot de passe d’application et vos droits d’ajout et de suppression dans cet agenda.',
    'The calendar connection could not be saved. Please try again.': 'Impossible d’enregistrer la connexion à cet agenda. Réessayez.',

    'The subscription could not be saved. Try again.': 'L’abonnement n’a pas pu être enregistré. Réessayez.',
    'Upcoming appointments (server view)': 'Rendez-vous à venir (vue serveur)',
    'This list is loaded directly by WordPress and remains visible when the interactive calendar is unavailable.': 'Cette liste est chargée directement par WordPress et reste visible si le calendrier interactif est indisponible.',
    'No events returned by the active source connectors for the next 60 days. Check the source diagnostics below.': 'Aucun événement renvoyé par les connecteurs actifs dans les 60 prochains jours. Consultez le diagnostic des sources ci-dessous.',
    'Source': 'Source',
    'Status': 'Statut',
    'This assistant detects active appointment plugins and guides external synchronization. You can view the local calendar before connecting an external destination.': 'Cet assistant détecte les extensions de réservation actives et vous guide pour synchroniser un agenda externe. Vous pouvez consulter le calendrier local avant de connecter une destination.',
    'Open calendar view': 'Ouvrir la vue calendrier',
    'Selecting a destination does not connect it. After saving, you must enter CalDAV credentials and test the connection, or create a private calendar subscription link and add it to your calendar application. Google and Outlook direct OAuth connection is not implemented.': 'Choisir une destination ne la connecte pas. Après l’enregistrement, saisissez les identifiants CalDAV et testez la connexion, ou créez un lien privé d’abonnement à ajouter à votre application de calendrier. La connexion directe OAuth à Google et Outlook n’est pas encore disponible.',
    'No external destination connected yet. Complete the connection on the Synchronization page.': 'Aucune destination externe n’est encore connectée. Terminez la connexion depuis la page Synchronisation.',
    'Finish connecting my calendar': 'Terminer la connexion de mon agenda',
    'Your external calendar is not connected. Selecting an application in the list below is not enough; complete a CalDAV connection or create and subscribe to a private URL.': 'Votre agenda externe n’est pas connecté. Sélectionner une application dans la liste ne suffit pas : terminez une connexion CalDAV ou créez un lien privé et abonnez-vous à ce lien.',
    'Show instructions for this application (not a connection)': 'Afficher les instructions pour cette application (sans connexion)',
    'Choosing an app only changes the instructions. Create an actual private link below and add it in that app.': 'Choisir une application ne change que les instructions. Créez un véritable lien privé ci-dessous et ajoutez-le dans cette application.',
    'Complete setup by connecting and validating a CalDAV destination below.': 'Terminez la configuration en connectant et en validant un agenda CalDAV ci-dessous.',

    'Configure sources and destination': 'Configurer les sources et la destination',
    'Change source and destination settings': 'Modifier les sources et la destination',
    'Dendrila Calendar setup': 'Configuration de Dendrila Calendar',
    'Configuration': 'Configuration',
    'Finish the Dendrila Calendar setup to enable your chosen appointment sources and destination.': 'Terminez la configuration de Dendrila Calendar pour activer les sources et la destination souhaitées.',
    'Open configuration assistant': 'Ouvrir l’assistant de configuration',
    'You are not allowed to configure Dendrila Calendar.': 'Vous ne pouvez pas configurer Dendrila Calendar.',
    'Configure Dendrila Calendar': 'Configurer Dendrila Calendar',
    'This assistant is required before using Dendrila Calendar. It automatically detects your active appointment plugins and helps you choose where bookings go.': 'Cet assistant est obligatoire avant de pouvoir utiliser Dendrila Calendar. Il détecte les extensions de réservation actives et vous aide à choisir la destination des rendez-vous.',
    'Configuration saved.': 'Configuration enregistrée.',
    'Select at least one available source and a valid destination.': 'Choisissez au moins une source disponible et une destination valide.',
    'A connector compatibility request was submitted for review.': 'Une demande de compatibilité de connecteur a été transmise pour examen.',
    'A compatibility request could not be submitted. Check your WordPress email delivery settings.': 'Impossible de transmettre une demande de compatibilité. Vérifiez l’envoi des e-mails WordPress.',
    'Step 1 — Detected appointment sources': 'Étape 1 — Sources de rendez-vous détectées',
    'Compatible active plugins are preselected. You may deselect them. Inactive or unsupported plugins cannot be synchronized.': 'Les extensions actives compatibles sont présélectionnées. Vous pouvez les décocher. Les extensions inactives ou non prises en charge ne peuvent pas être synchronisées.',
    'Dendrila local events': 'Événements locaux Dendrila',
    'No supported appointment plugin was detected. You can start with local events and return to setup later.': 'Aucune extension de réservation compatible n’a été détectée. Vous pouvez commencer avec les événements locaux et revenir ensuite dans l’assistant.',
    'Step 2 — Your destination calendar': 'Étape 2 — Votre agenda de destination',
    'Choose how appointments reach the calendar you already use.': 'Choisissez comment les rendez-vous rejoindront votre agenda habituel.',
    'Direct CalDAV connection (Nextcloud and compatible calendars)': 'Connexion CalDAV directe (Nextcloud et agendas compatibles)',
    'Read-only calendar URL subscription (Google, Apple, Outlook and compatible apps)': 'Abonnement par URL en lecture seule (Google, Apple, Outlook et applications compatibles)',
    'Local calendar only for now': 'Calendrier local uniquement pour le moment',
    'Direct Google Calendar and Outlook authorization is not implemented yet; these services currently support URL subscriptions only.': 'La connexion directe à Google Agenda et Outlook n’est pas encore disponible : ces services proposent actuellement uniquement l’abonnement par URL.',
    'Step 3 — Confirm the setup': 'Étape 3 — Confirmer la configuration',
    'If an active booking plugin is detected but its connector is unusable, saving sends a technical compatibility request for review. This includes only the plugin name, entrypoint, version, reason, WordPress/PHP versions and site URL — never appointments, customer data or passwords. Duplicate requests are suppressed.': 'Si une extension de réservation active est détectée mais que son connecteur est inutilisable, l’enregistrement transmettra une demande technique de compatibilité. Elle contiendra uniquement le nom de l’extension, son fichier principal, sa version, le motif, les versions WordPress/PHP et l’URL du site — jamais les rendez-vous, données clients ou mots de passe. Les demandes identiques sont regroupées.',
    'Save settings and continue': 'Enregistrer et continuer',

    'Synchronize appointments with your external calendar': 'Synchroniser les rendez-vous avec votre agenda externe',
    '%1$d created, %2$d updated, %3$d cancelled, %4$d failed': '%1$d créés, %2$d modifiés, %3$d annulés, %4$d échecs',
    'Appointment sources': 'Sources de rendez-vous',
    'CalDAV app password': 'Mot de passe d’application CalDAV',
    'CalDAV calendar collection URL (HTTPS)': 'Adresse du calendrier CalDAV (HTTPS)',
    'CalDAV username': 'Identifiant CalDAV',
    'Calendar connected. Automatic direct synchronization is enabled.': 'Agenda connecté. La synchronisation directe est activée.',
    'Calendar disconnected. Previously created external events were not deleted.': 'Agenda déconnecté. Les événements déjà créés à l’extérieur sont conservés.',
    'Cannot connect to that CalDAV calendar. Verify its calendar URL and application password.': 'Connexion à cet agenda CalDAV impossible. Vérifiez son adresse et le mot de passe d’application.',
    'Check the calendar address, credentials and selected sources.': 'Vérifiez l’adresse du calendrier, les identifiants et les sources sélectionnées.',
    'Connect a CalDAV calendar such as Nextcloud. New and changed WordPress bookings are pushed automatically approximately every five minutes while WordPress cron runs. This is one-way; the destination is never polled for changes.': 'Connectez un agenda CalDAV, par exemple Nextcloud. Les nouveaux rendez-vous et leurs modifications sont envoyés environ toutes les cinq minutes lorsque les tâches programmées WordPress fonctionnent. La synchronisation est unidirectionnelle.',
    'Connect this calendar': 'Connecter cet agenda',
    'Connection name': 'Nom de la connexion',
    'Direct synchronization into an existing calendar': 'Synchronisation directe avec un agenda existant',
    'Disconnect': 'Déconnecter',
    'Google Calendar and Outlook need OAuth account authorization and are not connected by this CalDAV form. Existing URL subscriptions remain available separately.': 'Google Agenda et Outlook nécessitent une connexion OAuth, indisponible avec ce formulaire CalDAV. Les abonnements par URL restent disponibles séparément.',
    'Never synchronized': 'Jamais synchronisé',
    'Synchronization attempt finished. Review the status below.': 'Tentative de synchronisation terminée. Consultez le bilan ci-dessous.',
    'Synchronize now': 'Synchroniser maintenant',
    'The server cannot securely protect the calendar password.': 'Le serveur ne peut pas protéger de manière sûre le mot de passe du calendrier.',
    'Too many connected calendars.': 'Nombre maximal d’agendas connectés atteint.',
    'You are not allowed to manage calendar connections.': 'Vous ne pouvez pas gérer les connexions des agendas.',
    'Local events': 'Événements locaux',
    'Calendar view': 'Vue calendrier',
    'Choose month': 'Choisir le mois',
    'Open unified calendar': 'Ouvrir le calendrier unifié',
    'This list contains only events created in Dendrila Calendar. Appointments imported from Easy Appointments and other plugins appear in the unified calendar.': 'Cette liste contient uniquement les événements créés dans Dendrila Calendar. Les rendez-vous importés depuis Easy Appointments et les autres extensions apparaissent dans le calendrier unifié.',
    'Your appointments from other plugins are shown here. The Local events submenu lists only events created in Dendrila Calendar.': 'Les rendez-vous des autres extensions s’affichent ici. Le sous-menu « Événements locaux » répertorie uniquement ceux créés dans Dendrila Calendar.',
    'Automatic synchronization': 'Synchronisation automatique',
    'Synchronization': 'Synchronisation',
    'Automatic calendar synchronization': 'Synchronisation automatique des calendriers',
    'Back to calendar': 'Retour au calendrier',
    'Synchronize with Google, Apple, Outlook or another calendar': 'Synchroniser avec Google, Apple, Outlook ou un autre agenda',
    'Subscribe your personal calendar': 'Synchroniser votre agenda personnel',
    'Select your WordPress sources, create a private calendar URL, then subscribe to it in your calendar app. The app refreshes events automatically on its own schedule; this is a read-only subscription, not instant or two-way synchronization.': 'Sélectionnez les sources WordPress, créez une adresse privée puis abonnez-y votre agenda. Les événements s’actualisent automatiquement selon la fréquence choisie par votre application. Cette synchronisation est en lecture seule, non instantanée et non bidirectionnelle.',
    'Where will you use your calendar?': 'Dans quel agenda souhaitez-vous afficher vos événements ?',
    'Calendar application': 'Application de calendrier',
    'Open Apple Calendar subscription': 'Ouvrir l’abonnement dans Calendrier Apple',
    'On a computer, open Google Calendar settings, choose Add calendar > From URL, paste the private HTTPS link, and add the calendar. Google decides when to refresh it. The mobile app cannot add URL subscriptions directly.': 'Depuis un ordinateur, ouvrez les paramètres de Google Agenda, puis Ajouter un agenda > À partir de l’URL. Collez le lien HTTPS privé. Google choisit la fréquence d’actualisation ; l’application mobile ne permet pas d’ajouter directement un abonnement par URL.',
    'On iPhone: Calendar > Calendars > Add Calendar > Add Subscription Calendar. Paste the URL. On Mac: File > New Calendar Subscription. You can also try Open Apple Calendar subscription after creating a link.': 'Sur iPhone : Calendrier > Calendriers > Ajouter un calendrier > Ajouter un calendrier avec abonnement, puis collez l’URL. Sur Mac : Fichier > Nouvel abonnement à un calendrier. Vous pouvez également essayer le bouton Ouvrir l’abonnement dans Calendrier Apple.',
    'In Outlook on the web, open Calendar > Add calendar > Subscribe from web. Paste the link and save. Updates can take more than 24 hours.': 'Dans Outlook sur le web : Calendrier > Ajouter un calendrier > S’abonner à partir du web. Collez le lien et enregistrez. Les mises à jour peuvent demander plus de 24 heures.',
    'In Nextcloud Calendar, choose New calendar > New subscription from link and paste the private URL. It will appear as a read-only subscribed calendar.': 'Dans l’application Agenda de Nextcloud, choisissez Nouvel agenda > Nouvel abonnement depuis un lien, puis collez l’URL privée. Cet agenda sera accessible en lecture seule.',
    'Find Subscribe by URL, Internet calendar, or iCalendar subscription in your calendar application. Paste the link rather than importing a one-time ICS file.': 'Recherchez S’abonner par URL, Agenda Internet ou Abonnement iCalendar dans votre application. Collez le lien au lieu d’importer une seule fois un fichier ICS.',
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
    'Connection method': 'Méthode de connexion',
    'Connect an existing calendar': 'Connecter un agenda existant',
    'Read-only subscription': 'Abonnement en lecture seule',
    '1. Choose your calendar service': '1. Choisissez votre service d’agenda',
    'We will guide you through the connection. You do not need an API key or a technical calendar address.': 'La connexion est guidée. Aucune clé API ni adresse technique de calendrier n’est nécessaire.',
    'Calendar service': 'Service d’agenda',
    'Other CalDAV service': 'Autre service CalDAV',
    '2. Connect your account': '2. Connectez votre compte',
    'Your cloud address': 'Adresse de votre cloud',
    'Account username or email': 'Identifiant ou adresse e-mail',
    'Application password': 'Mot de passe d’application',
    'Use an application-specific password if your service requires one. Your password is encrypted before it is saved.': 'Utilisez un mot de passe spécifique à l’application si le service le demande. Votre mot de passe sera chiffré avant son enregistrement.',
    'Find my calendars': 'Trouver mes calendriers',
    '3. Choose where to add appointments': '3. Choisissez où enregistrer les rendez-vous',
    'Calendar': 'Agenda',
    'Appointments to send': 'Rendez-vous à transmettre',
    'Connect your account': 'Connecter votre compte',
    'Direct account authorization is not available yet for this service. Dendrila cannot connect to your account just by selecting its name.': 'La connexion directe à ce service n’est pas encore disponible. Sélectionner son nom ne suffit pas pour établir une connexion.',
    'Use a read-only calendar subscription instead': 'Utiliser plutôt un abonnement de calendrier en lecture seule',
    'Looking for your calendars…': 'Recherche de vos agendas…',
    'Choose a calendar below, then confirm the connection.': 'Choisissez un agenda ci-dessous, puis confirmez la connexion.',
    'Unable to find calendars. Check your account details and try again.': 'Impossible de trouver les agendas. Vérifiez votre compte puis réessayez.',
    'Enter the web address of your Nextcloud, then your login details.': 'Saisissez l’adresse de votre Nextcloud, puis vos identifiants.',
    'Use your Apple Account email and an app-specific password. Connection may not work on every iCloud account yet.': 'Utilisez votre adresse de compte Apple et un mot de passe spécifique à l’application. La connexion n’est pas encore validée sur tous les comptes iCloud.',
    'Enter your calendar service address and account details.': 'Saisissez l’adresse de votre service d’agenda, puis vos identifiants.',
    'Account connection is not yet available for this service. You can use a calendar subscription instead.': 'La connexion directe n’est pas encore disponible pour ce service. Vous pouvez utiliser un abonnement.',
    'The calendar server redirected to an unsafe address.': 'La connexion a été redirigée vers une adresse non autorisée.',
    'Calendar discovery was redirected to another service.': 'La connexion a été redirigée vers un autre service.',
    'The calendar service did not accept the connection (': 'Le service d’agenda a refusé la connexion (',
    'The calendar service returned an unreadable response. PHP DOM/XML may be unavailable.': 'Réponse illisible du service. Le module XML de PHP peut être manquant.',
    'Too many calendar redirects.': 'Trop de redirections pendant la connexion.',
    'Enter a valid HTTPS address for your calendar service.': 'Saisissez une adresse HTTPS valide pour votre agenda.',
    'Enter your calendar account and application password.': 'Saisissez votre identifiant et votre mot de passe d’application.',
    'This server did not provide a calendar account. Check your service address.': 'Le service n’a pas fourni de compte de calendrier. Vérifiez son adresse.',
    'This account did not expose a calendar collection.': 'Aucun calendrier accessible trouvé pour ce compte.',
    'No calendar was found in this account.': 'Aucun calendrier trouvé dans ce compte.',
    'Not authorized.': 'Accès refusé.',
    'Unsupported calendar service.': 'Service d’agenda non pris en charge.',
    'Too many connection attempts. Please try again later.': 'Trop de tentatives de connexion. Réessayez plus tard.',
    'Unable to prepare calendar selection.': 'Impossible de préparer le choix du calendrier.',

    'Nextcloud (direct connection)': 'Nextcloud (connexion directe)',
    'Apple iCloud (app-specific password)': 'Apple iCloud (mot de passe d’application)',
    'Google Calendar (read-only subscription for now)': 'Google Agenda (abonnement en lecture seule pour le moment)',
    'Outlook / Microsoft 365 (read-only subscription for now)': 'Outlook / Microsoft 365 (abonnement en lecture seule pour le moment)',
    'Another calendar service': 'Un autre service d’agenda',
    'Keep my appointments inside WordPress only': 'Garder mes rendez-vous uniquement dans WordPress',
    'Where should your appointments appear?': 'Où souhaitez-vous voir vos rendez-vous ?',
    'Google and Outlook do not have one-click access yet. They use an optional read-only subscription until direct authorization is ready.': 'La connexion directe à Google et Outlook n’est pas encore disponible. Vous pouvez provisoirement choisir un abonnement en lecture seule.',

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
