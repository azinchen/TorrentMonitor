#!/usr/bin/env bash
# Builds a release archive and, with --publish, creates the GitHub release.
#
#   tools/release.sh                      build dist/TorrentMonitor-<version>.zip only
#   tools/release.sh --publish            build and create the release (GitHub creates the tag v<version>)
#   tools/release.sh --notes [version]    print the description of a version (default: the one in version.txt)
#
# The description is the section "## <version>" of CHANGELOG.md; its format is in
# .github/RELEASE_TEMPLATE.md. A release is not published without it.
#
# The version is read from version.txt: change it there (and merge it) first.
# TorrentMonitor installations find new versions through GitHub Releases,
# so publishing a release is what makes an update visible to users.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

publish=0
show=0
wanted=""
for arg in "$@"; do
    case "$arg" in
        --publish) publish=1 ;;
        --notes)   show=1 ;;
        -h|--help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) wanted="$arg" ;;
    esac
done

fail() { echo "error: $*" >&2; exit 1; }

# the address of the repository behind "origin": releases never go into the upstream of a fork
repository() { git remote get-url origin | sed -E 's#^(git@github\.com:|https://github\.com/)##; s#\.git$##'; }

# The description of a version: its section of CHANGELOG.md, followed by a link to everything
# that changed since the version described right below it.
description() {
    local version="$1" body previous
    body=$(awk -v v="$version" '
        /^## / { if (found) exit; if ($2 == v) { found = 1; next } }
        found { print }' CHANGELOG.md | sed -e '/./,$!d' | tac | sed -e '/./,$!d' | tac)
    [[ -n "$body" ]] || return 1
    previous=$(awk -v v="$version" '/^## / { if (found) { print $2; exit } if ($2 == v) found = 1 }' CHANGELOG.md)
    printf '%s\n' "$body"
    [[ -n "$previous" ]] && printf '\n[Все изменения](https://github.com/%s/compare/v%s...v%s)\n' "$(repository)" "$previous" "$version"
    return 0
}

version=$(php -r '$v = json_decode(file_get_contents("version.txt")); echo $v->system ?? "";')
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || fail "version.txt has no valid version: '$version'"
tag="v$version"
archive="dist/TorrentMonitor-$version.zip"

if [[ $show -eq 1 ]]; then
    description "${wanted:-$version}" || fail "CHANGELOG.md has no section '## ${wanted:-$version}'"
    exit 0
fi

[[ -z "$(git status --porcelain)" ]] || fail "working tree is not clean"
notes=$(mktemp)
trap 'rm -f "$notes"' EXIT
description "$version" > "$notes" || fail "CHANGELOG.md has no section '## $version': describe the release there first (see .github/RELEASE_TEMPLATE.md)"

# every PHP file must at least parse
while IFS= read -r file; do
    php -l "$file" > /dev/null || fail "syntax error in $file"
done < <(git ls-files '*.php')

mkdir -p dist
rm -f "$archive"
git archive --format=zip --prefix="TorrentMonitor-$version/" -o "$archive" HEAD
echo "built $archive ($(du -h "$archive" | cut -f1))"

if [[ $publish -eq 0 ]]; then
    echo "not published; run with --publish to create release $tag"
    exit 0
fi

branch=$(git rev-parse --abbrev-ref HEAD)
[[ "$branch" == "master" ]] || fail "releases are published from master, current branch is $branch"
# a connection to GitHub over SSH sometimes stalls: give up after a minute and try again
fetched=0
for attempt in 1 2 3; do
    if timeout 60 git fetch --quiet origin master; then fetched=1; break; fi
    echo "fetch attempt $attempt failed, retrying"
done
[[ $fetched -eq 1 ]] || fail "cannot fetch origin/master"
head=$(git rev-parse HEAD)
[[ "$head" == "$(git rev-parse origin/master)" ]] || fail "local master differs from origin/master"

# always release into the repository behind "origin", never into the upstream of a fork
repo=$(repository)
[[ "$repo" =~ ^[^/]+/[^/]+$ ]] || fail "cannot determine GitHub repository from origin: $repo"

# The tag is created by GitHub together with the release, on the commit that was checked above:
# nothing is pushed from here. A run that stopped half-way can simply be repeated.
gh release view "$tag" --repo "$repo" > /dev/null 2>&1 && fail "release $tag already exists"
if git rev-parse -q --verify "refs/tags/$tag^{commit}" > /dev/null; then
    [[ "$(git rev-parse "refs/tags/$tag^{commit}")" == "$head" ]] || fail "local tag $tag points to another commit"
fi
target=(--target "$head")
if remote=$(gh api "repos/$repo/commits/$tag" --jq .sha 2> /dev/null); then
    [[ "$remote" == "$head" ]] || fail "tag $tag already exists on GitHub and points to another commit"
    target=()
fi

# a version with a suffix (4.0.0-beta1) is a pre-release: GitHub does not report it as the latest
# release, so installed copies are not offered it as an update
flags=()
[[ "$version" == *-* ]] && flags+=(--prerelease)
flags+=(--notes-file "$notes")
gh release create "$tag" "$archive" --repo "$repo" --title "$version" "${flags[@]}" ${target[@]+"${target[@]}"}

# bring the tag here; not essential, so a network hiccup does not fail a release that is already out
timeout 60 git fetch --quiet origin "refs/tags/$tag:refs/tags/$tag" 2> /dev/null || echo "note: fetch the tag later with: git fetch origin tag $tag"
echo "published https://github.com/$repo/releases/tag/$tag"
