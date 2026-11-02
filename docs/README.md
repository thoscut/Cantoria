# Documentation

Cantoria is a Nextcloud app that keeps the catalog of a choir's sheet music
archive. This page tells you where to start. The project overview, installation
and requirements are in the [README](../README.md) of the repository.

The guides for using and running the app are in English and German; the
documentation for developers is in English.

| Guide | English | Deutsch |
|---|---|---|
| Using the app | [User guide](user-guide.md) | [Benutzerhandbuch](de/benutzerhandbuch.md) |
| Running the app | [Administration guide](admin-guide.md) | [Administrationshandbuch](de/administrationshandbuch.md) |
| The essentials in a few minutes | – | [Schnellstart](de/schnellstart.md) |
| One page for the singers, to pass on | [For choir members](for-choir-members.md) | [Für Chormitglieder](de/fuer-chormitglieder.md) |
| The law behind the rights checks | [Legal background](legal-background.md) | [Rechtlicher Hintergrund](de/rechtlicher-hintergrund.md) |
| Data protection templates for the choir | [Privacy templates](privacy-templates.md) | [Datenschutz-Vorlagen](de/datenschutz-vorlagen.md) |
| Running it next to a member app or choir website | [Alongside a choir system](alongside-a-choir-system.md) | [Neben einem Chorsystem](de/neben-einem-chorsystem.md) |
| What the app does for accessibility, and its limits | [Accessibility statement](accessibility.md) | [Erklärung zur Barrierefreiheit](de/barrierefreiheit.md) |

## Using the app

| You are … | Read |
|---|---|
| a music librarian who keeps the catalog, the rights and the backups | [User guide](user-guide.md) |
| a conductor who chooses versions, answers questions and plans concerts | [User guide](user-guide.md) |
| a board member or auditor who reads the catalog and the audit log | [User guide](user-guide.md) |
| a choir member who practises with the excerpts shared with you | [For choir members](for-choir-members.md), one page; details in the [user guide, "As a choir member"](user-guide.md#as-a-choir-member) |

## Running the app

| You are … | Read |
|---|---|
| a Nextcloud administrator who installs and sets up the app | [Administration guide](admin-guide.md), starting with [Setting up in six steps](admin-guide.md#setting-up-in-six-steps) |
| a music librarian to whom an admin gave the settings page | [Administration guide, "Giving the settings to a group"](admin-guide.md#giving-the-settings-to-a-group) |
| responsible for backups and data protection | [Administration guide](admin-guide.md) and [SECURITY.md](../SECURITY.md) |
| deciding whether to share excerpts with choir members or the public | [Administration guide, section 14](admin-guide.md#14-sharing-with-choir-members-and-the-public) and [SECURITY.md](../SECURITY.md) |
| setting up an agent or a tool that works with the archive | [Administration guide, section 13](admin-guide.md#13-agents-and-the-api), [API for agents](api-v1.md) and the [MCP server](../tools/mcp/README.md) |

## Trying it out

- [Demo data](../tools/demo/README.md): the invented archive of a fictitious
  choir (CC0) with the steps to import it and the figures to expect.

## Developing the app

- [CONTRIBUTING.md](../CONTRIBUTING.md): development setup, checks,
  translations, style and commit messages.
- [API](api.md): the internal JSON API between the user interface and the
  server. It is not a stable public interface.
- [API for agents](api-v1.md): the stable, versioned OCS API v1 for tools
  and agents, described in [openapi.json](../openapi.json).
- [Tests](../tests/README.md): unit tests and integration tests against a
  real Nextcloud.
- [Releasing](release.md): building, signing and publishing a release in the
  Nextcloud App Store.
- [Screenshot script](../tools/screenshots/README.md): recreates the
  screenshots of this documentation from the demo data.
- [Screenshots](screenshots/README.md): the list of images, what each shows
  and which ones the App Store uses.
- [Roadmap](../ROADMAP.md) (German): principles and milestones.
- [Namensrecherche „Cantoria“](de/namensrecherche-cantoria.md) (German):
  research on a possible international name for the app.
- [Changelog](../CHANGELOG.md).

## Deutsch

Die Anleitungen gibt es auf Englisch und auf Deutsch, die Unterlagen für die
Entwicklung nur auf Englisch.

| Du bist … | Lies |
|---|---|
| in der Archivverwaltung, in der Chorleitung, im Vorstand oder in der Revision | [Benutzerhandbuch](de/benutzerhandbuch.md) |
| neu und willst das Wichtigste in wenigen Minuten | [Schnellstart](de/schnellstart.md) |
| in der Administration und richtest die App ein | [Administrationshandbuch](de/administrationshandbuch.md) |
| verantwortlich für Sicherung und Datenschutz | [Administrationshandbuch](de/administrationshandbuch.md) und [SECURITY.md](../SECURITY.md) (englisch) |

## Security

Report vulnerabilities privately as described in [SECURITY.md](../SECURITY.md).
