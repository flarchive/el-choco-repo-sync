# Repo Sync

Keep your Flarum extension announcements in sync with GitHub.

**Package:** `el-choco/repo-sync` · **Version:** `0.1.0-beta.1` · **Flarum:** `^2.0.2` · **PHP:** `^8.3` · **License:** MIT

Repo Sync connects GitHub repositories to existing discussions on **your own Flarum forum**. It can maintain the README in the first post and publish release notes as replies. Configuration is available in the admin panel in English and German.

## Features

- Up to 20 repository mappings, each with a branch, README path, discussion ID and first-post ID.
- Update a protected README section or explicitly replace the entire first post.
- Preserve introductions and other text outside the managed section.
- Announce published GitHub releases; optionally include prereleases.
- Receive signed GitHub webhooks and store work in the database before responding.
- Process work through Flarum's scheduler or from the admin panel.
- Avoid duplicate deliveries and duplicate release announcements, even when GitHub uses a different delivery ID.
- Retry transient GitHub/network failures up to five attempts, with increasing delays; manually retry failed jobs after correcting configuration or permissions.
- Use a selected Flarum user through the normal post-edit and reply APIs. Permissions and visibility restrictions remain in effect.
- Keep the last ten previous post versions per mapping, accessible only to administrators.
- Rewrite common relative Markdown links, reference links and images against GitHub. Fenced/indented code blocks and simple inline code are preserved.

## Installation

This beta is supplied as a local ZIP with the compiled admin interface. Follow the accompanying **REPO-SYNC-INSTALL-DE.md** for installation through `/tmp`, including CrazyMax Docker commands and the persistent local Composer source.

Once the package is published on Packagist, a standard installation is:

```bash
composer require el-choco/repo-sync:"^0.1.0@beta"
php flarum migrate
php flarum cache:clear
```

Enable **Repo Sync** in Administration → Extensions. Use the normal Flarum user when running maintenance commands. For CrazyMax, also run `extension sync` after Composer succeeds.

## Configuration

1. Select a publishing **user ID**. This user must be able to view the discussion, edit its first post and reply. A dedicated bot needs suitable group permissions; Repo Sync grants none automatically.
2. Generate a webhook secret and **copy it before saving**. The saved secret is never returned by Repo Sync's status API. An optional GitHub token can be saved for private repositories or higher API limits. For a fine-grained token, use Contents: Read on the required repositories.
3. Add a mapping: `owner/repository`, branch, file path, numeric discussion ID and numeric first-post ID. These IDs are different. Use **Find first post from discussion ID** to fill the post ID automatically. Alternatively, find it in `GET /api/discussions/DISCUSSION_ID`, under `data.relationships.firstPost.data.id`.
4. Choose README synchronization, release announcements or both. New mappings and the global automation switch start disabled. Enable the intended mappings and save.
5. In GitHub → Repository → Settings → Webhooks, add the URL shown by Repo Sync. Select `application/json`, enter the same secret, select **Pushes** and **Releases**, and keep SSL verification enabled.
6. Start with **Initialize README section and synchronize**, then **Process up to two jobs now**. Verify the resulting post before relying on automatic processing.

Only the configured branch's pushes are accepted. Each push checks the current configured README; unrelated changes produce no edit if the resulting content is unchanged. Old push deliveries fetch the current branch rather than restoring an old commit. Draft releases and unsupported events are ignored. Release replies are created for `published` events only; editing release notes later does not edit an existing reply.

### Managed section

The default mode only changes text inside one pair of markers:

```text
Your introduction remains here.

[REPOSYNC]
The synchronized README appears here.
[/REPOSYNC]

Your personal footer remains here.
```

The markers are rendered as an invisible wrapper while Repo Sync is enabled. The initialization button appends the section if neither marker exists. Webhooks never initialize missing markers on their own. Missing, repeated or reversed markers stop the job rather than replace unrelated text.

In **Replace entire first post** mode, the entire post is managed. Manual edits inside the managed content can be replaced on the next synchronization. The README itself cannot contain the reserved `[REPOSYNC]` markers. Formatting is provided by the forum's active formatter extensions; GitHub-specific formatting is not guaranteed to render identically.

### Scheduler

Your server must invoke Flarum's scheduler every minute as the normal Flarum user:

```cron
* * * * * cd /path/to/flarum && php flarum schedule:run
```

Repo Sync registers its own scheduled task; no continuously running worker is needed. An existing Flarum scheduler also processes Repo Sync. Without it, jobs remain pending until **Process now** is used or this command runs:

```bash
php flarum repo-sync:run
```

One command processes up to five jobs. The admin button processes up to two. GitHub webhooks return promptly after recording jobs, not after publishing. A `202` response means queued, not published. Check job status to confirm publication. GitHub delivery failures can be redelivered from GitHub after fixing the cause.

### Backups and limitations

Before every README edit, Repo Sync stores the previous source text. Open a backup in the admin panel, copy it and restore it through the normal post editor. Disable the mapping first if a manual restoration should remain untouched. The ten-backup limit applies per mapping; removing a mapping does not immediately erase its backups. Backups are post text only, not database or forum backups.

Private repository content becomes visible according to the target discussion's permissions. Administrators can view all Repo Sync backups, including text originating in private discussions. Tokens and secrets are stored in Flarum's settings database; protect database backups accordingly. They are not serialized into the public forum payload. Network requests use `api.github.com` without redirects; arbitrary webhook URLs are not fetched.

README input is limited to 50 KB and resulting posts to 60 KB. Larger content is refused. Only GitHub.com repositories are supported. The extension does not install on or modify discuss.flarum.org. When removing Repo Sync completely, its uninstall migration removes its job history and post backups; export any needed backups first. Existing post contents remain.

## Development checks

```bash
php -d extension=pdo_sqlite -d extension=xsl tests/run.php /path/to/flarum/vendor/autoload.php
cd js
npm install
npm run build
npm test
```

The backend suite uses SQLite with table prefixes, Flarum models, its internal API client, and the real text formatter. GitHub and publishing effects are controlled test doubles; it does not publish to a live forum.

---

# Deutsch

**Repo Sync** hält die Dokumentation deiner Erweiterungen im eigenen Flarum-Forum aktuell. Eine README von GitHub kann im Startbeitrag synchronisiert werden. Bei einer Veröffentlichung kann zusätzlich eine Antwort mit den Release-Notizen erscheinen.

## Funktionen

- Bis zu 20 Repository-Zuordnungen mit eigenem Branch, Dateipfad und Zielthema.
- Wahlweise nur einen markierten README-Bereich oder den gesamten Startbeitrag ersetzen.
- Persönliche Einleitung und Abschluss außerhalb der Markierungen bleiben erhalten.
- Release-Antworten mit optionaler Berücksichtigung von Vorabversionen.
- Signierte Webhooks, dauerhafte Aufträge, Schutz gegen doppelte Release-Antworten.
- Verarbeitung durch den Flarum-Aufgabenplaner oder manuell im Adminbereich.
- Bis zu fünf Versuche bei vorübergehenden GitHub-/Netzwerkfehlern.
- Ein eigener Veröffentlichungsbenutzer; die normalen Flarum-Rechte bleiben wirksam.
- Die letzten zehn Beitragssicherungen je Zuordnung, nur für Administratoren abrufbar.
- Relative Bilder und übliche Markdown-Verweise werden auf GitHub-Adressen umgeschrieben.

## Installation und Einrichtung

Für diese lokale Beta die separate **REPO-SYNC-INSTALL-DE.md** verwenden. Sie enthält die Befehle mit `/tmp`, dem Flarum-Benutzer und deinem Docker-Container. Auf dem Forumserver wird Node.js nicht benötigt.

Nach der späteren Veröffentlichung auf Packagist ist die normale Installation:

```bash
composer require el-choco/repo-sync:"^0.1.0@beta"
php flarum migrate
php flarum cache:clear
```

Anschließend **Administration → Erweiterungen → Repo Sync** öffnen:

1. Die numerische Benutzer-ID des Veröffentlichungsbenutzers eintragen. Er braucht Leserechte, Bearbeitungsrechte am Startbeitrag und Antwortrechte. Rechte werden nicht automatisch vergeben.
2. Ein Geheimnis erzeugen, vor dem Speichern kopieren und bei GitHub als Webhook-Secret verwenden. Einen GitHub-Token nur bei Bedarf eintragen; bei Fine-grained Tokens reichen für diesen Zweck Contents: Read auf den benötigten Repositorys.
3. Ein Repository hinzufügen, beispielsweise `el-choco/kobalt`. Branch und README-Datei festlegen. Diskussion und Startbeitrag als numerische IDs eintragen. Mit **Startbeitrag aus der Diskussions-ID ermitteln** wird die Beitrags-ID automatisch eingetragen. Alternativ steht sie in `/api/discussions/DISKUSSIONS_ID` unter `data.relationships.firstPost.data.id`; sie ist nicht die sichtbare Beitragsnummer `1`.
4. README-Synchronisierung, Release-Antworten oder beides auswählen. Die Zuordnung und Repo Sync aktivieren, dann speichern.
5. Bei GitHub unter **Settings → Webhooks** die angezeigte Adresse, `application/json`, dasselbe Secret sowie die Ereignisse **Pushes** und **Releases** eintragen. SSL-Prüfung eingeschaltet lassen.
6. Zunächst **README-Bereich anlegen und synchronisieren** und danach **Jetzt bis zu zwei Aufträge verarbeiten** anklicken. Den Beitrag kontrollieren.

Die README-Markierung lautet `[REPOSYNC]…[/REPOSYNC]` und wird im fertigen Beitrag unsichtbar dargestellt. Nur der Initialisierungsauftrag legt sie bei Bedarf an. Normale Webhooks ersetzen bei fehlenden oder fehlerhaften Markierungen nichts. Im Modus „Gesamten Startbeitrag ersetzen“ wird ausdrücklich der vollständige Text verwaltet. Änderungen von Hand innerhalb des verwalteten Inhalts können beim nächsten Abgleich überschrieben werden.

## Automatische Verarbeitung

Der Flarum-Aufgabenplaner muss jede Minute laufen. Ein bereits eingerichteter Scheduler genügt; ein weiterer Dauerdienst ist nicht nötig. Ohne Aufgabenplaner bleiben Aufträge vorgemerkt, bis sie im Adminbereich oder mit `php flarum repo-sync:run` verarbeitet werden. Die Docker-Befehle stehen in der Installationsanleitung.

Die Webhook-Antwort `202` bedeutet „vorgemerkt“. Erst der Auftragsstatus bestätigt die Veröffentlichung. Pushes anderer Branches und gelöschte Branches werden ignoriert. Bei unverändertem README-Inhalt entsteht keine Bearbeitung. Release-Antworten entstehen nur beim Ereignis `published`; spätere Änderungen der Release-Notizen werden nicht nachgetragen. Bei einem fehlgeschlagenen GitHub-Aufruf kann die Zustellung dort erneut gesendet werden.

## Sicherungen und Grenzen

Vor einer README-Bearbeitung wird der vorige Quelltext gesichert. Eine Sicherung öffnen, kopieren und über den normalen Beitragseditor wiederherstellen. Die Zuordnung vorher deaktivieren, wenn die manuelle Wiederherstellung erhalten bleiben soll. Die letzten zehn Sicherungen je Zuordnung ersetzen keine Datenbank- oder Vollsicherung.

Private README-Inhalte sind im Zielthema gemäß dessen Leserechten sichtbar. Alle Repo-Sync-Sicherungen können von Administratoren gelesen werden. Token und Secret liegen in der Flarum-Einstellungsdatenbank; Datenbanksicherungen entsprechend schützen. Sie werden von Repo Sync nicht an die öffentliche Forumsoberfläche übertragen.

README maximal 50 KB, fertiger Beitrag maximal 60 KB. Die Darstellung hängt von den aktivierten Formatter-Erweiterungen ab; GitHub-spezifische Formatierung kann abweichen. Nur GitHub.com wird unterstützt. Eine Diskussion auf discuss.flarum.org kann diese Erweiterung nicht aus deinem Forum heraus bearbeiten. Beim vollständigen Entfernen mit Datenlöschung werden Aufträge und Repo-Sync-Sicherungen gelöscht; vorhandene Beitragstexte bleiben bestehen. Benötigte Sicherungen vorher exportieren.
