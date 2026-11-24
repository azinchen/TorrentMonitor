<!--
Release description format for TorrentMonitor.

Where it lives: CHANGELOG.md has one section per release, "## <version>" (the
version as in version.txt, no "v"), newest first. tools/release.sh takes that
section as the description of the GitHub release and refuses to publish
without it. "tools/release.sh --notes [version]" prints what would be published.

Who reads it: mostly a TorrentMonitor user, inside the program - the block
"Доступна версия" on the Состояние page shows the description. So:
  - Russian, user-facing wording: what changed for the user, not how the code
    changed. No refactoring, tests or CI.
  - Every item ends with where the change was made, in parentheses: the pull
    request, "(#15)", several as "(#15, #16)", or the short commit id when
    there was no pull request, "(1a2b3c4)". GitHub links both by itself and so
    does the program.
  - One or two sentences per item. Name a setting in bold, as the interface
    spells it. Link the wiki page for anything new.
  - The program renders only this part of Markdown: "##"/"###" headings,
    "-" lists, **bold**, `code` and [text](https://...) links. No tables,
    images, code blocks, nested lists or HTML: they would show as plain text.

What a release describes: the changes since the previous release, a beta
included - a copy that skips versions is shown the descriptions of all of them.
The final version of a series (4.0.0 after 4.0.0-betaN) is read by people who
never saw the betas: its section describes everything since the previous
stable version and replaces the beta sections in CHANGELOG.md.

Sections, in this order. "Что нужно сделать" is always there; the others are
left out when empty. The script adds the "Все изменения" line itself.
-->

One or two sentences: what this version is about. It is all a notification
says about the release, so it has to make sense alone. No heading above it.

### ⬆️ Что нужно сделать

<!-- Anything the user must do or check after the update: a changed default,
a removed setting, a step to run by hand. Otherwise one line:
"Ничего: настройки и данные остаются как есть." -->

### ✨ Новое

- ... (#PR)

### 🐛 Исправлено

<!-- Say what went wrong for the user, then that it is fixed, e.g.:
- kinozal.me: раздача могла скачаться повторно на сервере не в московском поясе. (#15) -->

- ... (#PR)

### ♻️ Изменено

<!-- Behaviour that is different now, and things that were removed. -->

- ... (#PR)
