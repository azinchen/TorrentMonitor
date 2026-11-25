<!--
Release description format for TorrentMonitor.

Where it lives: CHANGELOG.md has one section per release, "## <version>" (the
version as in version.txt, no "v"), newest first. tools/release.sh takes that
section as the description of the GitHub release and refuses to publish
without it. "tools/release.sh --notes [version]" prints what would be published.

The script also checks what can be checked of the rules below and does not
publish a description that breaks them: it starts with a summary sentence,
every change ends with its pull request or commit, the section "Что нужно
сделать" is not there just to say "nothing", and nothing the program cannot
show is used. "tools/release.sh --check"
checks every section; "tests/run.sh lint" (and so "tests/run.sh all") runs it.

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
The first release candidate and the final version of a series (4.0.0-rc.1, then
4.0.0, after 4.0.0-betaN) are read by people who never saw the betas: their
section describes everything since the previous stable version, as the program
is now, not beta by beta. A later candidate (4.0.0-rc.2) describes the changes
since the candidate before it, like a beta: a copy that skipped a candidate is
shown both descriptions, and two full ones would say the same twice. The section
of the final version takes in the candidates and replaces the sections of its
betas and candidates in CHANGELOG.md.

Names of pre-releases: the number is set off by a dot - 4.0.0-rc.1, 4.1.0-beta.1.
GitHub orders releases of one day by the rules of semantic versioning, where
"beta9" is above "beta11" (they are compared as text) and "beta.9" is below
"beta.11" (as numbers). The betas of 4.0.0 were named without the dot.

Sections, in this order. Every one of them is left out when empty: "Что нужно
сделать" too - when the user has nothing to do, the description does not say so. The script adds the "Все изменения" line itself.
-->

One or two sentences: what this version is about. It is all a notification
says about the release, so it has to make sense alone. No heading above it.

### ⬆️ Что нужно сделать

<!-- Anything the user must do or check after the update: a changed default,
a removed setting, a step to run by hand. When there is nothing of the kind,
leave the whole section out, heading included. -->

### ✨ Новое

- ... (#PR)

### 🐛 Исправлено

<!-- Say what went wrong for the user, then that it is fixed, e.g.:
- kinozal.me: раздача могла скачаться повторно на сервере не в московском поясе. (#15) -->

- ... (#PR)

### ♻️ Изменено

<!-- Behaviour that is different now, and things that were removed. -->

- ... (#PR)
